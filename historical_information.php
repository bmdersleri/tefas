<?php
// historical_information.php  v4.0  (2026-04-27)
// ─────────────────────────────────────────────────────────────────
// Endpoint : POST /api/funds/fonGnlBlgSiraliGetir
// YENİ     : SQLite önbellek katmanı
//
// Strateji:
//  1. SQLite'ta mevcut veriyi sorgula (fon_kodu + tarih aralığı)
//  2. Eksik aralıkları hesapla (baş boşluğu + son boşluğu)
//  3. Yalnızca eksik aralıkları TEFAS API'sinden çek
//  4. Yeni veriyi SQLite'a yaz
//  5. Tamamını DB'den oku ve döndür
//
// Avantajlar:
//  - Geçmiş günler bir kez çekilir, sonsuza kadar cache'de kalır
//  - Sadece bugünden geriye doğru eksik günler istenir
//  - Başarısız chunk bir sonraki istekte yeniden denenir,
//    başarılı veriler korunur
//  - Sayfa yüklemesi API olmadan ~milisaniye
//
// index.php API'si (historical_information, TefasResult,
// TefasChunkError) bire bir korunmuştur — çağıran değişmez.
// ─────────────────────────────────────────────────────────────────

/* ─── Genel ayarlar ──────────────────────────────────────────── */
define('TEFAS_TIMEOUT',      30);
define('TEFAS_CONNECT_TO',    8);
define('TEFAS_MAX_RETRIES',   4);    // 3 -> 4: daha fazla deneme
define('TEFAS_RETRY_DELAY',   3);
define('TEFAS_PAGE_SIZE',   100);
define('TEFAS_CHUNK_DAYS',   14);    // 30 -> 14: kucuk dilim, API daha az zorlanir
define('TEFAS_CHUNK_SLEEP', 1500000); // 0.6 -> 1.5 sn: rate-limit onlemi

// Bugünün verisi kaç saat sonra "bayat" sayılır (piyasa kapanışı ~18:00 TR)
define('TEFAS_TODAY_TTL_H',   2);

/* ─── SQLite DB yolu ─────────────────────────────────────────── */
if (!defined('TEFAS_DB_FILE')) {
    $__tefas_cache_dir = __DIR__ . DIRECTORY_SEPARATOR . 'cache';
    if (!is_dir($__tefas_cache_dir)) {
        @mkdir($__tefas_cache_dir, 0755, true);
    }
    define('TEFAS_DB_FILE',
        $__tefas_cache_dir . DIRECTORY_SEPARATOR . 'tefas_cache.sqlite3');
    define('TEFAS_COOKIE_FILE',
        $__tefas_cache_dir . DIRECTORY_SEPARATOR . 'tefas_cookies.txt');
    unset($__tefas_cache_dir);
}
if (!defined('TEFAS_VERIFY_SSL')) {
    define('TEFAS_VERIFY_SSL', true);
}

/* ─── API sabitleri ──────────────────────────────────────────── */
define('TEFAS_API_URL',
    'https://www.tefas.gov.tr/api/funds/fonGnlBlgSiraliGetir');
define('TEFAS_ORIGIN',       'https://www.tefas.gov.tr');
define('TEFAS_REFERER_BASE', 'https://www.tefas.gov.tr/tr/fon-detayli-analiz/');
define('TEFAS_WARMUP_URL',   'https://www.tefas.gov.tr/tr/');
define('TEFAS_USER_AGENT',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
    . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36');

/* ─── Dışsal API sınıfları (DEĞİŞMEDİ) ──────────────────────── */
class TefasResult
{
    public array $data   = [];
    public array $errors = [];
    public function hasErrors(): bool { return !empty($this->errors); }
    public function hasData(): bool   { return !empty($this->data);   }
}

class TefasChunkError
{
    public string $start;
    public string $end;
    public string $message;
    public int    $attempts;

    public function __construct(
        string $start, string $end, string $message, int $attempts)
    {
        $this->start    = $start;
        $this->end      = $end;
        $this->message  = $message;
        $this->attempts = $attempts;
    }

    public function __toString(): string
    {
        return sprintf('%s – %s: %s (%d deneme)',
            $this->start, $this->end, $this->message, $this->attempts);
    }
}

/* ═══════════════════════════════════════════════════════════════
 * SQLite önbellek katmanı
 * ═══════════════════════════════════════════════════════════════ */
class TefasCache
{
    private static ?SQLite3 $db = null;

    /** DB bağlantısını aç ve şemayı oluştur (ilk açılışta). */
    public static function db(): SQLite3
    {
        if (self::$db !== null) return self::$db;

        self::$db = new SQLite3(TEFAS_DB_FILE);
        self::$db->busyTimeout(5000);
        self::$db->exec('PRAGMA journal_mode=WAL');   // eş zamanlı okuma/yazma
        self::$db->exec('PRAGMA synchronous=NORMAL'); // hız/güvenlik dengesi
        self::$db->exec('PRAGMA foreign_keys=ON');

        // Ana fiyat tablosu
        self::$db->exec('
            CREATE TABLE IF NOT EXISTS fon_fiyat (
                fon_kodu         TEXT    NOT NULL,
                tarih            TEXT    NOT NULL,   -- YYYY-MM-DD
                fiyat            REAL,
                kisi_sayisi      INTEGER,
                portfoy_buyukluk REAL,
                ted_pay_sayisi   REAL,
                fon_unvan        TEXT,
                kayit_zamani     INTEGER NOT NULL
                    DEFAULT (strftime(\'%s\',\'now\')),
                PRIMARY KEY (fon_kodu, tarih)
            )
        ');

        // Hızlı tarih-aralığı sorguları için index
        self::$db->exec('
            CREATE INDEX IF NOT EXISTS idx_fon_tarih
                ON fon_fiyat (fon_kodu, tarih)
        ');

        // Bayat "bugün" kaydını bulmak için index
        self::$db->exec('
            CREATE INDEX IF NOT EXISTS idx_kayit_zamani
                ON fon_fiyat (fon_kodu, kayit_zamani)
        ');

        // Cron senkronizasyon durumu
        self::$db->exec('
            CREATE TABLE IF NOT EXISTS fon_sync_state (
                fund_code            TEXT    NOT NULL,
                fund_type            TEXT    NOT NULL,
                mode                 TEXT    NOT NULL DEFAULT \'discovering\',
                cursor_start         TEXT,
                cursor_end           TEXT,
                first_available_date TEXT,
                last_success_at      INTEGER,
                last_error           TEXT,
                retry_count          INTEGER NOT NULL DEFAULT 0,
                updated_at           INTEGER NOT NULL DEFAULT (strftime(\'%s\',\'now\')),
                PRIMARY KEY (fund_code, fund_type)
            )
        ');
        self::$db->exec('
            CREATE INDEX IF NOT EXISTS idx_sync_state_mode
                ON fon_sync_state (mode, updated_at)
        ');

        return self::$db;
    }

    /**
     * Verilen fon + tarih aralığındaki satırları döndürür.
     * @return array  [ ['fon_kodu'=>..., 'tarih'=>..., ...], ... ]
     */
    public static function get(
        string $fund_code,
        string $start_iso,  // Y-m-d
        string $end_iso
    ): array {
        $db   = self::db();
        $stmt = $db->prepare('
            SELECT fon_kodu, tarih, fiyat, kisi_sayisi,
                   portfoy_buyukluk, ted_pay_sayisi, fon_unvan
            FROM   fon_fiyat
            WHERE  fon_kodu = :k
              AND  tarih BETWEEN :s AND :e
            ORDER  BY tarih ASC
        ');
        $stmt->bindValue(':k', $fund_code, SQLITE3_TEXT);
        $stmt->bindValue(':s', $start_iso,  SQLITE3_TEXT);
        $stmt->bindValue(':e', $end_iso,    SQLITE3_TEXT);
        $res  = $stmt->execute();
        $rows = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * DB'deki mevcut verinin min/max tarihini döndürür.
     * @return array{min: string|null, max: string|null}
     */
    public static function dateRange(
        string $fund_code,
        string $start_iso,
        string $end_iso
    ): array {
        $db   = self::db();
        $stmt = $db->prepare('
            SELECT MIN(tarih) as mn, MAX(tarih) as mx
            FROM   fon_fiyat
            WHERE  fon_kodu = :k
              AND  tarih BETWEEN :s AND :e
        ');
        $stmt->bindValue(':k', $fund_code, SQLITE3_TEXT);
        $stmt->bindValue(':s', $start_iso,  SQLITE3_TEXT);
        $stmt->bindValue(':e', $end_iso,    SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return ['min' => $row['mn'] ?? null, 'max' => $row['mx'] ?? null];
    }

    /**
     * "Bugün" kaydının ne zaman yazıldığını döndürür (unix timestamp).
     * Null = hiç yok.
     */
    public static function todayFetchTime(string $fund_code): ?int
    {
        $today = date('Y-m-d');
        $db    = self::db();
        $stmt  = $db->prepare('
            SELECT kayit_zamani FROM fon_fiyat
            WHERE  fon_kodu = :k AND tarih = :t
            LIMIT  1
        ');
        $stmt->bindValue(':k', $fund_code, SQLITE3_TEXT);
        $stmt->bindValue(':t', $today,     SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $row ? (int)$row['kayit_zamani'] : null;
    }

    /**
     * Satır dizisini DB'ye yazar (INSERT OR REPLACE — güncelleme de yapar).
     * @param array $rows  API'den gelen ham satırlar (büyük harf alan adları)
     */
    public static function save(array $rows): void
    {
        if (empty($rows)) return;
        $db = self::db();
        $db->exec('BEGIN');
        $stmt = $db->prepare('
            INSERT OR REPLACE INTO fon_fiyat
                (fon_kodu, tarih, fiyat, kisi_sayisi,
                 portfoy_buyukluk, ted_pay_sayisi, fon_unvan, kayit_zamani)
            VALUES
                (:k, :t, :f, :ks, :pb, :tp, :u,
                 strftime(\'%s\',\'now\'))
        ');
        foreach ($rows as $r) {
            $stmt->bindValue(':k',  $r['FONKODU']         ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':t',  $r['TARIH']           ?? '', SQLITE3_TEXT);
            $stmt->bindValue(':f',  $r['FIYAT'],               SQLITE3_FLOAT);
            $stmt->bindValue(':ks', $r['KISISAYISI'],          SQLITE3_INTEGER);
            $stmt->bindValue(':pb', $r['PORTFOYBUYUKLUK'],     SQLITE3_FLOAT);
            $stmt->bindValue(':tp', $r['TEDPAYSAYISI'],        SQLITE3_FLOAT);
            $stmt->bindValue(':u',  $r['FONUNVAN']        ?? '', SQLITE3_TEXT);
            $stmt->execute();
            $stmt->reset();
        }
        $db->exec('COMMIT');
    }

    /**
     * DB satırlarını index.php'nin beklediği büyük-harf formata dönüştürür.
     */
    public static function toResult(array $db_rows): array
    {
        $out = [];
        foreach ($db_rows as $r) {
            $out[] = [
                'FONKODU'         => $r['fon_kodu'],
                'FONUNVAN'        => $r['fon_unvan'],
                'TARIH'           => $r['tarih'],
                'FIYAT'           => $r['fiyat']            !== null
                                        ? (float) $r['fiyat']            : null,
                'KISISAYISI'      => $r['kisi_sayisi']       !== null
                                        ? (int)   $r['kisi_sayisi']      : null,
                'PORTFOYBUYUKLUK' => $r['portfoy_buyukluk'] !== null
                                        ? (float) $r['portfoy_buyukluk'] : null,
                'TEDPAYSAYISI'    => $r['ted_pay_sayisi']    !== null
                                        ? (float) $r['ted_pay_sayisi']   : null,
            ];
        }
        return $out;
    }

    /** Belirli fon+tarih aralığında satır sayısını döndürür. */
    public static function countRows(
        string $fund_code,
        string $start_iso,
        string $end_iso
    ): int {
        $db   = self::db();
        $stmt = $db->prepare('
            SELECT COUNT(*) AS c
            FROM fon_fiyat
            WHERE fon_kodu = :k
              AND tarih BETWEEN :s AND :e
        ');
        $stmt->bindValue(':k', $fund_code, SQLITE3_TEXT);
        $stmt->bindValue(':s', $start_iso, SQLITE3_TEXT);
        $stmt->bindValue(':e', $end_iso, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return (int)($row['c'] ?? 0);
    }

    /** Aralıkta en erken tarihi döndürür; hiç yoksa null. */
    public static function earliestDateInRange(
        string $fund_code,
        string $start_iso,
        string $end_iso
    ): ?string {
        $db   = self::db();
        $stmt = $db->prepare('
            SELECT MIN(tarih) AS mn
            FROM fon_fiyat
            WHERE fon_kodu = :k
              AND tarih BETWEEN :s AND :e
        ');
        $stmt->bindValue(':k', $fund_code, SQLITE3_TEXT);
        $stmt->bindValue(':s', $start_iso, SQLITE3_TEXT);
        $stmt->bindValue(':e', $end_iso, SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $row && !empty($row['mn']) ? (string)$row['mn'] : null;
    }

    /** Fon için sync-state kaydını döndürür. */
    public static function getSyncState(string $fund_code, string $fund_type): ?array
    {
        $db   = self::db();
        $stmt = $db->prepare('
            SELECT fund_code, fund_type, mode,
                   cursor_start, cursor_end, first_available_date,
                   last_success_at, last_error, retry_count, updated_at
            FROM fon_sync_state
            WHERE fund_code = :k AND fund_type = :t
            LIMIT 1
        ');
        $stmt->bindValue(':k', strtoupper($fund_code), SQLITE3_TEXT);
        $stmt->bindValue(':t', strtoupper($fund_type), SQLITE3_TEXT);
        $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        return $row ?: null;
    }

    /** Fon için sync-state kaydını ekler/günceller. */
    public static function upsertSyncState(array $state): void
    {
        $db = self::db();
        $stmt = $db->prepare('
            INSERT INTO fon_sync_state (
                fund_code, fund_type, mode, cursor_start, cursor_end,
                first_available_date, last_success_at, last_error,
                retry_count, updated_at
            )
            VALUES (
                :k, :t, :m, :cs, :ce, :fa, :ls, :le, :rc, strftime(\'%s\',\'now\')
            )
            ON CONFLICT(fund_code, fund_type) DO UPDATE SET
                mode = excluded.mode,
                cursor_start = excluded.cursor_start,
                cursor_end = excluded.cursor_end,
                first_available_date = excluded.first_available_date,
                last_success_at = excluded.last_success_at,
                last_error = excluded.last_error,
                retry_count = excluded.retry_count,
                updated_at = strftime(\'%s\',\'now\')
        ');
        $stmt->bindValue(':k', strtoupper((string)$state['fund_code']), SQLITE3_TEXT);
        $stmt->bindValue(':t', strtoupper((string)$state['fund_type']), SQLITE3_TEXT);
        $stmt->bindValue(':m', (string)($state['mode'] ?? 'discovering'), SQLITE3_TEXT);
        $stmt->bindValue(':cs', $state['cursor_start'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':ce', $state['cursor_end'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':fa', $state['first_available_date'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':ls', isset($state['last_success_at']) ? (int)$state['last_success_at'] : null, SQLITE3_INTEGER);
        $stmt->bindValue(':le', $state['last_error'] ?? null, SQLITE3_TEXT);
        $stmt->bindValue(':rc', (int)($state['retry_count'] ?? 0), SQLITE3_INTEGER);
        $stmt->execute();
    }

    /** Tüm sync-state kayıtlarını döndürür. */
    public static function listSyncStates(): array
    {
        $db = self::db();
        $res = $db->query('
            SELECT fund_code, fund_type, mode, cursor_start, cursor_end,
                   first_available_date, last_success_at, last_error,
                   retry_count, updated_at
            FROM fon_sync_state
            ORDER BY fund_code ASC
        ');
        $rows = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** Son başarılı senkron zamanını döndürür. */
    public static function latestSyncTimestamp(): ?int
    {
        $db = self::db();
        $row = $db->querySingle(
            'SELECT MAX(last_success_at) FROM fon_sync_state WHERE last_success_at IS NOT NULL',
            true
        );
        if (!$row || empty($row['MAX(last_success_at)'])) return null;
        return (int)$row['MAX(last_success_at)'];
    }

    /**
     * Önbelleği temizler. fund_code null ise tüm fonlar.
     */
    public static function clear(?string $fund_code = null): void
    {
        $db = self::db();
        if ($fund_code === null) {
            $db->exec('DELETE FROM fon_fiyat');
        } else {
            $stmt = $db->prepare('DELETE FROM fon_fiyat WHERE fon_kodu = :k');
            $stmt->bindValue(':k', strtoupper($fund_code), SQLITE3_TEXT);
            $stmt->execute();
        }
    }
}

/* ═══════════════════════════════════════════════════════════════
 * Bot koruması için warmup
 * ═══════════════════════════════════════════════════════════════ */
class TefasSession
{
    private static ?bool $warmedUp = null;

    public static function cookieFile(): string { return TEFAS_COOKIE_FILE; }

    public static function warmup(bool $force = false): bool
    {
        if (!$force && self::$warmedUp === true) return true;

        $ch = curl_init(TEFAS_WARMUP_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => TEFAS_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => TEFAS_CONNECT_TO,
            CURLOPT_SSL_VERIFYPEER => TEFAS_VERIFY_SSL,
            CURLOPT_ENCODING       => '',
            CURLOPT_COOKIEJAR      => self::cookieFile(),
            CURLOPT_COOKIEFILE     => self::cookieFile(),
            CURLOPT_USERAGENT      => TEFAS_USER_AGENT,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8',
                'Upgrade-Insecure-Requests: 1',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Sec-Fetch-User: ?1',
            ],
        ]);
        $body      = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ok = ($body !== false && $http_code >= 200 && $http_code < 400);
        self::$warmedUp = $ok;
        return $ok;
    }

    public static function reset(): void
    {
        self::$warmedUp = null;
        @unlink(self::cookieFile());
    }
}

/* ═══════════════════════════════════════════════════════════════
 * ANA FONKSİYON (dışsal API DEĞİŞMEDİ)
 * ═══════════════════════════════════════════════════════════════ */
function historical_information(
    string   $information_type,
    string   $fund_type,
    DateTime $start_date,
    DateTime $end_date,
    ?string  $fund_code      = null,
    bool     $throw_on_error = false
): TefasResult {

    $allowed_info = ['BindHistoryInfo', 'BindHistoryAllocation'];
    if (!in_array($information_type, $allowed_info, true)) {
        throw new InvalidArgumentException(
            'information_type şunlardan biri olmalı: '
            . implode(', ', $allowed_info));
    }
    if (!in_array($fund_type, ['YAT','EMK','BYF'], true)) {
        throw new InvalidArgumentException(
            'fund_type şunlardan biri olmalı: YAT, EMK, BYF');
    }
    if ($start_date > $end_date) {
        throw new InvalidArgumentException(
            'start_date, end_date\'den küçük veya eşit olmalı!');
    }
    if (empty($fund_code)) {
        throw new InvalidArgumentException(
            'Yeni TEFAS API\'sinde fund_code zorunludur.');
    }

    $result    = new TefasResult();
    $fund_code = strtoupper(trim($fund_code));

    if ($information_type === 'BindHistoryAllocation') {
        $result->errors[] = new TefasChunkError(
            $start_date->format('d.m.Y'), $end_date->format('d.m.Y'),
            'BindHistoryAllocation: portföy dağılımı endpoint\'i henüz '
            . 'yeni API\'de eşleştirilmedi.', 0);
        if ($throw_on_error) throw new RuntimeException((string) $result->errors[0]);
        return $result;
    }

    $start_iso  = $start_date->format('Y-m-d');
    $end_iso    = $end_date->format('Y-m-d');
    $today_iso  = date('Y-m-d');

    /* ── 1. SQLite'taki mevcut aralığı sorgula ──────────────── */
    $cached = TefasCache::dateRange($fund_code, $start_iso, $end_iso);
    $db_min = $cached['min']; // null ya da 'Y-m-d'
    $db_max = $cached['max'];

    /* ── 2. Hangi aralıklar eksik? ──────────────────────────── */
    $fetch_ranges = []; // [ [DateTime $from, DateTime $to], ... ]

    if ($db_min === null) {
        // DB'de hiç veri yok → tüm aralığı çek
        $fetch_ranges[] = [clone $start_date, clone $end_date];
    } else {
        // Baş boşluğu: kullanıcı daha eski veri istiyor
        if ($start_iso < $db_min) {
            $gap_end = new DateTime($db_min);
            $gap_end->modify('-1 day');
            if ($start_date <= $gap_end) {
                $fetch_ranges[] = [clone $start_date, $gap_end];
            }
        }

        // Son boşluğu: son kayıt bugünden önceyse veya bugün bayatsa
        $need_tail = false;
        if ($db_max < $end_iso) {
            $need_tail = true; // hiç bugünkü veri yok ya da aralık var
        } elseif ($db_max === $today_iso) {
            // Bugün DB'de var ama TTL kontrolü
            $fetch_time = TefasCache::todayFetchTime($fund_code);
            if ($fetch_time !== null) {
                $age_hours = (time() - $fetch_time) / 3600;
                if ($age_hours > TEFAS_TODAY_TTL_H) {
                    $need_tail = true; // bayat, yenile
                }
            }
        }

        if ($need_tail) {
            $tail_start = new DateTime($db_max);
            $tail_start->modify('+1 day');
            if ($tail_start <= $end_date) {
                $fetch_ranges[] = [$tail_start, clone $end_date];
            }
        }
    }

    /* ── 3. Eksik aralıkları API'den çek ────────────────────── */
    if (!empty($fetch_ranges)) {
        TefasSession::warmup();

        foreach ($fetch_ranges as $range) {
            [$range_start, $range_end] = $range;
            $new_rows = _tefas_fetch_range(
                $fund_code, $fund_type, $range_start, $range_end, $result
            );

            if (!empty($new_rows)) {
                TefasCache::save($new_rows);
            }

            if ($throw_on_error && $result->hasErrors()) {
                throw new RuntimeException((string) end($result->errors));
            }
        }
    }

    /* ── 4. Tüm veriyi DB'den oku ───────────────────────────── */
    $db_rows      = TefasCache::get($fund_code, $start_iso, $end_iso);
    $result->data = TefasCache::toResult($db_rows);

    return $result;
}

/* ═══════════════════════════════════════════════════════════════
 * API'den belirli bir tarih aralığını çek (chunk + pagination)
 * Yeni satırları döndürür; hataları $result->errors'a yazar.
 * ═══════════════════════════════════════════════════════════════ */
function _tefas_fetch_range(
    string     $fund_code,
    string     $fund_type,
    DateTime   $range_start,
    DateTime   $range_end,
    TefasResult &$result,
    int        $max_chunks = 20  // tek sayfa yüklemesinde max chunk -- geri kalanı sonraki istekte
): array {
    $all_new = [];
    $chunks  = _tefas_build_chunks($range_start, $range_end, TEFAS_CHUNK_DAYS);
    $limit   = min(count($chunks) - 1, $max_chunks);

    for ($ci = 0; $ci < $limit; $ci++) {
        if ($ci > 0) usleep(TEFAS_CHUNK_SLEEP);

        $chunk_start = $chunks[$ci];
        $chunk_end   = $chunks[$ci + 1];
        $bas_tarih   = $chunk_start->format('Ymd');
        $bit_tarih   = $chunk_end->format('Ymd');

        $chunk_ok       = false;
        $last_error     = '';
        $waf_reset_done = false;
        $page           = 1;

        // ── Pagination döngüsü ───────────────────────────────
        while (true) {
            $bas_sira = ($page - 1) * TEFAS_PAGE_SIZE + 1;
            $bit_sira = $page * TEFAS_PAGE_SIZE;

            $payload = [
                'fonTipi'        => $fund_type,
                'fonKodu'        => null,
                'aramaMetni'     => $fund_code,
                'fonTurKod'      => null,
                'fonGrubu'       => null,
                'sfonTurKod'     => null,
                'basTarih'       => $bas_tarih,
                'bitTarih'       => $bit_tarih,
                'basSira'        => $bas_sira,
                'bitSira'        => $bit_sira,
                'fonTurAciklama' => null,
                'dil'            => 'TR',
                'kurucuKod'      => null,
            ];

            // ── Retry döngüsü ────────────────────────────────
            $attempt = 0;
            $page_ok = false;

            while ($attempt < TEFAS_MAX_RETRIES) {
                $attempt++;
                [$response, $curl_err, $http_code] =
                    _tefas_post_json(TEFAS_API_URL, $payload, $fund_code);

                if ($response === false) {
                    $last_error = $curl_err ?: 'cURL hatası';
                    if ($attempt < TEFAS_MAX_RETRIES) sleep(TEFAS_RETRY_DELAY);
                    continue;
                }
                if ($http_code === 403 || _tefas_is_waf_block($response)) {
                    $last_error = "Bot koruması (HTTP $http_code)";
                    if (!$waf_reset_done) {
                        TefasSession::reset();
                        TefasSession::warmup(true);
                        $waf_reset_done = true;
                    }
                    if ($attempt < TEFAS_MAX_RETRIES) sleep(TEFAS_RETRY_DELAY);
                    continue;
                }
                if ($http_code >= 500) {
                    $last_error = "Sunucu hatası (HTTP $http_code)";
                    if ($attempt < TEFAS_MAX_RETRIES) sleep(TEFAS_RETRY_DELAY);
                    continue;
                }

                $json = json_decode($response, true);
                if (!is_array($json)) {
                    // Bos/HTML yanit = rate-limit; exponential backoff: 5s, 10s, 20s, 40s
                    $last_error = 'Gecersiz JSON (rate-limit?)';
                    if ($attempt < TEFAS_MAX_RETRIES) sleep(5 * (int)pow(2, $attempt - 1));
                    continue;
                }
                if (!empty($json['fault'])) {
                    $f  = $json['fault'];
                    $fc = $f['faultCode']   ?? '?';
                    $fs = $f['faultString'] ?? '';
                    if (strpos($fs, '429') !== false
                        || strpos(strtolower($fs), 'rate') !== false
                        || strpos(strtolower($fs), 'limit') !== false) {
                        $last_error = "Rate-limit (fault $fc)";
                        if ($attempt < TEFAS_MAX_RETRIES) sleep(5 * (int)pow(2, $attempt - 1));
                        continue;
                    }
                    $last_error = "API fault: $fc - $fs";
                    break;
                }

                $list = $json['resultList'] ?? $json['data'] ?? [];
                if (!is_array($list)) $list = [];

                foreach ($list as $r) {
                    if (strtoupper($r['fonKodu'] ?? '') !== $fund_code) continue;
                    $tarih = $r['tarih'] ?? null;
                    if ($tarih === null) continue;
                    $all_new[] = [
                        'FONKODU'         => $fund_code,
                        'FONUNVAN'        => $r['fonUnvan']        ?? null,
                        'TARIH'           => $tarih,
                        'FIYAT'           => isset($r['fiyat'])
                                                ? (float)$r['fiyat']           : null,
                        'KISISAYISI'      => isset($r['kisiSayisi'])
                                                ? (int)  $r['kisiSayisi']      : null,
                        'PORTFOYBUYUKLUK' => isset($r['portfoyBuyukluk'])
                                                ? (float)$r['portfoyBuyukluk'] : null,
                        'TEDPAYSAYISI'    => isset($r['tedPaySayisi'])
                                                ? (float)$r['tedPaySayisi']    : null,
                    ];
                }

                $page_ok  = true;
                $chunk_ok = true;

                if (count($list) >= TEFAS_PAGE_SIZE) {
                    $page++;
                    break;  // sonraki sayfa
                } else {
                    break 2; // son sayfa
                }
            }
            // ── /Retry ───────────────────────────────────────

            if (!$page_ok) break;
        }
        // ── /Pagination ──────────────────────────────────────

        if (!$chunk_ok) {
            $result->errors[] = new TefasChunkError(
                $chunk_start->format('d.m.Y'),
                $chunk_end->format('d.m.Y'),
                $last_error,
                $attempt ?? TEFAS_MAX_RETRIES
            );
        }
    }

    return $all_new;
}

/* ─── UUID v4 ────────────────────────────────────────────────── */
function _tefas_uuid_v4(): string
{
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff));
}

/* ─── Tarih chunk listesi ────────────────────────────────────── */
function _tefas_build_chunks(
    DateTime $start,
    DateTime $end,
    int $step_days = 30
): array {
    $chunks  = [];
    $current = clone $start;
    while ($current < $end) {
        $chunks[] = clone $current;
        $current->modify('+' . $step_days . ' days');
    }
    $chunks[] = clone $end;
    if (count($chunks) === 1) $chunks[] = clone $end;
    return $chunks;
}

/* ─── HTTP POST JSON ─────────────────────────────────────────── */
function _tefas_post_json(
    string $url,
    array  $body,
    string $fund_code = 'TLY'
): array {
    $ch      = curl_init($url);
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
    $referer = TEFAS_REFERER_BASE . urlencode($fund_code);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => TEFAS_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => TEFAS_CONNECT_TO,
        CURLOPT_SSL_VERIFYPEER => TEFAS_VERIFY_SSL,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => TEFAS_USER_AGENT,
        CURLOPT_COOKIEJAR      => TefasSession::cookieFile(),
        CURLOPT_COOKIEFILE     => TefasSession::cookieFile(),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json, text/plain, */*',
            'Accept-Language: tr-TR,tr;q=0.9,en-US;q=0.8,en;q=0.7',
            'Origin: '     . TEFAS_ORIGIN,
            'Referer: '    . $referer,
            'x-request-id: ' . _tefas_uuid_v4(),
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
        ],
    ]);

    $response  = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) return [false, $curl_err, $http_code];
    return [$response, '', $http_code];
}

/* ─── WAF blok tespiti ───────────────────────────────────────── */
function _tefas_is_waf_block(string $body): bool
{
    if ($body === '') return false;
    foreach ([
        'The requested URL was rejected',
        'Please consult with your administrator',
        'Your support ID is',
        'Pardon Our Interruption',
        '_Incapsula_Resource',
        'Request unsuccessful',
    ] as $n) {
        if (stripos($body, $n) !== false) return true;
    }
    $trim = ltrim($body);
    return strlen($trim) < 4096
        && (stripos($trim, '<html') === 0
            || stripos($trim, '<!doctype') === 0);
}

/* ─── Geriye uyumluluk — eski fonksiyon adları korundu ──────── */
function _looks_like_waf_block(string $body): bool
{
    return _tefas_is_waf_block($body);
}

function http_post_json_ex(string $url, array $body, string $fund_code = ''): array
{
    return _tefas_post_json($url, $body, $fund_code);
}

function historical_information_compat(
    string   $information_type,
    string   $fund_type,
    DateTime $start_date,
    DateTime $end_date,
    ?string  $fund_code = null
): array {
    $r = historical_information(
        $information_type, $fund_type,
        $start_date, $end_date, $fund_code
    );
    return $r->data;
}

/**
 * Sadece SQLite üzerinden veri döndürür; TEFAS API çağrısı yapmaz.
 */
function historical_information_db_only(
    string   $information_type,
    string   $fund_type,
    DateTime $start_date,
    DateTime $end_date,
    ?string  $fund_code = null
): TefasResult {
    $result = new TefasResult();

    if (!in_array($information_type, ['BindHistoryInfo', 'BindHistoryAllocation'], true)) {
        throw new InvalidArgumentException(
            'information_type şunlardan biri olmalı: BindHistoryInfo, BindHistoryAllocation'
        );
    }
    if (!in_array($fund_type, ['YAT', 'EMK', 'BYF'], true)) {
        throw new InvalidArgumentException(
            'fund_type şunlardan biri olmalı: YAT, EMK, BYF'
        );
    }
    if ($start_date > $end_date) {
        throw new InvalidArgumentException('start_date, end_date\'den küçük veya eşit olmalı!');
    }
    if (empty($fund_code)) {
        throw new InvalidArgumentException('DB-only modunda fund_code zorunludur.');
    }

    $fund_code = strtoupper(trim($fund_code));
    $db_rows = TefasCache::get(
        $fund_code,
        $start_date->format('Y-m-d'),
        $end_date->format('Y-m-d')
    );
    $result->data = TefasCache::toResult($db_rows);
    return $result;
}

function historical_information_db_only_compat(
    string   $information_type,
    string   $fund_type,
    DateTime $start_date,
    DateTime $end_date,
    ?string  $fund_code = null
): array {
    $r = historical_information_db_only(
        $information_type, $fund_type, $start_date, $end_date, $fund_code
    );
    return $r->data;
}

/**
 * Cron worker için tek çağrıda sınırlı chunk çekimi + SQLite kaydı.
 */
function tefas_fetch_and_store_range(
    string   $fund_code,
    string   $fund_type,
    DateTime $range_start,
    DateTime $range_end,
    int      $max_chunks = 1
): TefasResult {
    if ($range_start > $range_end) {
        throw new InvalidArgumentException('range_start, range_end\'den küçük veya eşit olmalı!');
    }
    $fund_code = strtoupper(trim($fund_code));
    $fund_type = strtoupper(trim($fund_type));

    $result = new TefasResult();
    TefasSession::warmup();

    $new_rows = _tefas_fetch_range(
        $fund_code, $fund_type, $range_start, $range_end, $result, $max_chunks
    );
    if (!empty($new_rows)) {
        TefasCache::save($new_rows);
    }

    $result->data = TefasCache::toResult(
        TefasCache::get($fund_code, $range_start->format('Y-m-d'), $range_end->format('Y-m-d'))
    );
    return $result;
}

/**
 * Hafta içi gün listesini döndürür (resmi tatiller hariç).
 * @return string[]  YYYY-MM-DD
 */
function tefas_weekday_dates(DateTime $start, DateTime $end): array
{
    static $holidays = null;
    if ($holidays === null) {
        $holidays = [
            '2024-01-01', '2024-04-09', '2024-04-10', '2024-04-11', '2024-04-12',
            '2024-04-23', '2024-05-01', '2024-05-19', '2024-06-17', '2024-06-18',
            '2024-06-19', '2024-06-20', '2024-06-23', '2024-07-15', '2024-08-30',
            '2024-10-29',
            '2025-01-01', '2025-03-20', '2025-03-30', '2025-03-31', '2025-04-01',
            '2025-04-23', '2025-05-01', '2025-05-19', '2025-06-06', '2025-06-07',
            '2025-06-08', '2025-06-09', '2025-06-23', '2025-07-15', '2025-08-30',
            '2025-10-29',
            '2026-01-01', '2026-03-20', '2026-04-23', '2026-05-01', '2026-05-19',
            '2026-05-27', '2026-05-28', '2026-05-29', '2026-05-30', '2026-06-23',
            '2026-07-15', '2026-08-30', '2026-10-29',
        ];
    }
    $days = [];
    $cur  = clone $start;
    while ($cur <= $end) {
        $w = (int)$cur->format('N'); // 1=Mon ... 7=Sun
        if ($w <= 5) {
            $dateStr = $cur->format('Y-m-d');
            if (!in_array($dateStr, $holidays)) {
                $days[] = $dateStr;
            }
        }
        $cur->modify('+1 day');
    }
    return $days;
}

/**
 * Fon için hafta içi bazlı eksik günleri döndürür.
 * @return string[]  eksik YYYY-MM-DD listesi
 */
function tefas_missing_weekday_dates(
    string   $fund_code,
    DateTime $start,
    DateTime $end
): array {
    $fund_code = strtoupper(trim($fund_code));
    $rows = TefasCache::get($fund_code, $start->format('Y-m-d'), $end->format('Y-m-d'));
    $present = [];
    foreach ($rows as $r) {
        if (!empty($r['tarih'])) {
            $present[(string)$r['tarih']] = true;
        }
    }

    $missing = [];
    foreach (tefas_weekday_dates($start, $end) as $d) {
        if (!isset($present[$d])) {
            $missing[] = $d;
        }
    }
    return $missing;
}

function tefas_sync_state(string $fund_code, string $fund_type): ?array
{
    return TefasCache::getSyncState($fund_code, $fund_type);
}

function tefas_latest_sync_timestamp(): ?int
{
    return TefasCache::latestSyncTimestamp();
}

function build_date_chunks(
    DateTime $start,
    DateTime $end,
    int $step_days = 90
): array {
    return _tefas_build_chunks($start, $end, $step_days);
}

function format_tr_date(DateTime $dt): string
{
    return $dt->format('d.m.Y');
}

function _convert_dates(array &$rows): void
{
    foreach ($rows as &$row) {
        if (!isset($row['TARIH']) || !is_numeric($row['TARIH'])) continue;
        $s  = (int) round($row['TARIH'] / 1000);
        $dt = new DateTime('@' . $s);
        $dt->setTimezone(new DateTimeZone('Europe/Istanbul'));
        $row['TARIH'] = $dt->format('Y-m-d');
    }
    unset($row);
}

function _cast_history_info(array &$rows): void
{
    foreach ($rows as &$row) {
        unset($row['BORSABULTENFIYAT']);
        foreach (['FIYAT','TEDPAYSAYISI','KISISAYISI','PORTFOYBUYUKLUK'] as $f) {
            if (isset($row[$f]) && $row[$f] !== null) $row[$f] = (float)$row[$f];
        }
    }
    unset($row);
}

function _cast_allocation(array &$rows): void
{
    foreach ($rows as &$row) {
        $keys = array_keys($row);
        for ($i = 3; $i < count($keys); $i++) {
            $f = $keys[$i];
            if (isset($row[$f]) && is_numeric($row[$f])) $row[$f] = (float)$row[$f];
        }
    }
    unset($row);
}


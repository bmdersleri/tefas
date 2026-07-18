<?php

require_once __DIR__ . '/historical_information.php';

$configFile = __DIR__ . '/tefas_sync_config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Config bulunamadı: tefas_sync_config.php\n");
    exit(1);
}
$cfg = require $configFile;

date_default_timezone_set($cfg['timezone'] ?? 'Europe/Istanbul');

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Bu script sadece CLI için tasarlandı.\n";
    exit(1);
}

$lockPath = __DIR__ . '/cache/tefas_sync.lock';
$lockDir = dirname($lockPath);
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockFp = fopen($lockPath, 'c');
if ($lockFp === false) {
    fwrite(STDERR, "Lock dosyası açılamadı: $lockPath\n");
    exit(1);
}
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "[sync] başka bir süreç çalışıyor, çıkılıyor.\n";
    fclose($lockFp);
    exit(0);
}
register_shutdown_function(static function () use ($lockFp): void {
    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);
});

if (!isWithinWindow((string)($cfg['window_start'] ?? '10:00'), (string)($cfg['window_end'] ?? '18:00'))) {
    echo "[sync] çalışma penceresi dışında, no-op.\n";
    exit(0);
}

$fundSpecs = parseFundWhitelist((array)($cfg['fund_whitelist'] ?? []));
if (empty($fundSpecs)) {
    echo "[sync] whitelist boş, no-op.\n";
    exit(0);
}

$floorDate = new DateTime((string)($cfg['global_floor_date'] ?? '2022-01-01'));
$floorDate->setTime(0, 0, 0);
$today = new DateTime('today');

$coarseDays = max(14, (int)($cfg['discovery_coarse_days'] ?? 90));
$syncChunkDays = max(7, (int)($cfg['sync_chunk_days'] ?? 14));

echo '[sync] başladı: ' . date('Y-m-d H:i:s') . " (" . count($fundSpecs) . " fon)\n";

foreach ($fundSpecs as $item) {
    $code = $item['code'];
    $type = $item['type'];
    try {
        syncFundOneStep($code, $type, $floorDate, $today, $coarseDays, $syncChunkDays);
    } catch (Throwable $e) {
        TefasCache::upsertSyncState([
            'fund_code' => $code,
            'fund_type' => $type,
            'mode' => 'discovering',
            'last_error' => 'Kritik: ' . $e->getMessage(),
            'retry_count' => 1,
        ]);
        echo "[sync][$code] kritik hata: {$e->getMessage()}\n";
    }
}

echo "[sync] forward-fill başlıyor...\n";
$fillCount = forwardFillGaps($fundSpecs, $today);
echo "[sync] forward-fill tamamlandı: $fillCount satır dolduruldu.\n";

echo '[sync] bitti: ' . date('Y-m-d H:i:s') . "\n";
exit(0);

function parseFundWhitelist(array $raw): array
{
    $out = [];
    foreach ($raw as $entry) {
        $parts = array_map('trim', explode(':', strtoupper((string)$entry), 2));
        $code = $parts[0] ?? '';
        $type = $parts[1] ?? 'YAT';
        if ($code === '') continue;
        if (!in_array($type, ['YAT', 'EMK', 'BYF'], true)) $type = 'YAT';
        $out[] = ['code' => $code, 'type' => $type];
    }
    return $out;
}

function isWithinWindow(string $startHm, string $endHm): bool
{
    $now = new DateTime();
    $today = $now->format('Y-m-d');
    $start = new DateTime($today . ' ' . $startHm . ':00');
    $end = new DateTime($today . ' ' . $endHm . ':00');
    return $now >= $start && $now <= $end;
}

function syncFundOneStep(
    string $code,
    string $type,
    DateTime $floorDate,
    DateTime $today,
    int $coarseDays,
    int $syncChunkDays
): void {
    $state = TefasCache::getSyncState($code, $type);
    if ($state === null) {
        $state = [
            'fund_code' => $code,
            'fund_type' => $type,
            'mode' => 'discovering',
            'cursor_start' => null,
            'cursor_end' => null,
            'first_available_date' => null,
            'last_success_at' => null,
            'last_error' => null,
            'retry_count' => 0,
        ];
    }

    // Eğer DB'de zaten veri varsa doğrudan syncing moduna geç.
    if (empty($state['first_available_date'])) {
        $earliest = TefasCache::earliestDateInRange(
            $code,
            $floorDate->format('Y-m-d'),
            $today->format('Y-m-d')
        );
        if ($earliest !== null) {
            $state['first_available_date'] = $earliest;
            $state['mode'] = 'syncing';
            $state['cursor_start'] = $earliest;
            $state['cursor_end'] = null;
            $state['last_error'] = null;
            $state['retry_count'] = 0;
        }
    }

    if (($state['mode'] ?? 'discovering') === 'discovering') {
        runDiscoveryStep($state, $code, $type, $floorDate, $today, $coarseDays, $syncChunkDays);
    } else {
        runSyncStep($state, $code, $type, $today, $syncChunkDays);
    }
}

function runDiscoveryStep(
    array $state,
    string $code,
    string $type,
    DateTime $floorDate,
    DateTime $today,
    int $coarseDays,
    int $syncChunkDays
): void {
    $cursorStart = !empty($state['cursor_start']) ? new DateTime($state['cursor_start']) : null;
    $cursorEnd   = !empty($state['cursor_end']) ? new DateTime($state['cursor_end']) : null;
    $probeSizeDays = $syncChunkDays - 1;

    if ($cursorStart === null || $cursorEnd === null) {
        $cursorEnd = clone $today;
        $cursorStart = clone $cursorEnd;
        $cursorStart->modify("-{$probeSizeDays} days");
        if ($cursorStart < $floorDate) $cursorStart = clone $floorDate;
    }

    // Fine fazı: cursor aralığı 14 günden büyükse, lower->upper aralığında ileri tarıyoruz.
    $spanDays = (int)$cursorStart->diff($cursorEnd)->format('%a');
    $isFinePhase = $spanDays > $probeSizeDays;

    if ($isFinePhase) {
        $probeStart = clone $cursorStart;
        $probeEnd = clone $probeStart;
        $probeEnd->modify("+{$probeSizeDays} days");
        if ($probeEnd > $cursorEnd) $probeEnd = clone $cursorEnd;
    } else {
        $probeStart = clone $cursorStart;
        $probeEnd = clone $cursorEnd;
    }

    $r = tefas_fetch_and_store_range($code, $type, $probeStart, $probeEnd, 1);
    $rowCount = TefasCache::countRows($code, $probeStart->format('Y-m-d'), $probeEnd->format('Y-m-d'));

    if ($r->hasErrors()) {
        $state['retry_count'] = (int)($state['retry_count'] ?? 0) + 1;
        $state['last_error'] = (string)end($r->errors);
        TefasCache::upsertSyncState($state);
        echo "[sync][$code] discovery hata: {$state['last_error']}\n";
        return;
    }

    if ($isFinePhase) {
        if ($rowCount > 0) {
            $first = TefasCache::earliestDateInRange(
                $code,
                $probeStart->format('Y-m-d'),
                $probeEnd->format('Y-m-d')
            );
            $state['first_available_date'] = $first ?: $probeStart->format('Y-m-d');
            $state['mode'] = 'syncing';
            $state['cursor_start'] = $state['first_available_date'];
            $state['cursor_end'] = null;
            $state['last_success_at'] = time();
            $state['last_error'] = null;
            $state['retry_count'] = 0;
            TefasCache::upsertSyncState($state);
            echo "[sync][$code] first_available bulundu: {$state['first_available_date']}\n";
            return;
        }

        $nextStart = clone $probeEnd;
        $nextStart->modify('+1 day');
        if ($nextStart > $cursorEnd) {
            $state['last_error'] = 'Discovery fine fazı bitti, veri bulunamadı.';
            $state['retry_count'] = (int)($state['retry_count'] ?? 0) + 1;
        } else {
            $state['cursor_start'] = $nextStart->format('Y-m-d');
            $state['cursor_end'] = $cursorEnd->format('Y-m-d');
            $state['last_success_at'] = time();
            $state['last_error'] = null;
            $state['retry_count'] = 0;
        }
        TefasCache::upsertSyncState($state);
        echo "[sync][$code] discovery fine devam.\n";
        return;
    }

    // Coarse fazı
    if ($rowCount > 0) {
        $lowerBound = clone $probeStart;
        $lowerBound->modify("-{$coarseDays} days");
        if ($lowerBound < $floorDate) $lowerBound = clone $floorDate;

        $state['cursor_start'] = $lowerBound->format('Y-m-d');
        $state['cursor_end'] = $probeEnd->format('Y-m-d');
        $state['last_success_at'] = time();
        $state['last_error'] = null;
        $state['retry_count'] = 0;
        TefasCache::upsertSyncState($state);
        echo "[sync][$code] discovery coarse hit, fine faza geçildi.\n";
        return;
    }

    $nextEnd = clone $probeEnd;
    $nextEnd->modify("-{$coarseDays} days");
    if ($nextEnd < $floorDate) {
        $state['last_error'] = 'Global alt sınıra kadar veri bulunamadı.';
        $state['retry_count'] = (int)($state['retry_count'] ?? 0) + 1;
        TefasCache::upsertSyncState($state);
        echo "[sync][$code] discovery: veri yok (floor).\n";
        return;
    }

    $nextStart = clone $nextEnd;
    $nextStart->modify("-{$probeSizeDays} days");
    if ($nextStart < $floorDate) $nextStart = clone $floorDate;

    $state['cursor_start'] = $nextStart->format('Y-m-d');
    $state['cursor_end'] = $nextEnd->format('Y-m-d');
    $state['last_success_at'] = time();
    $state['last_error'] = null;
    $state['retry_count'] = 0;
    TefasCache::upsertSyncState($state);
    echo "[sync][$code] discovery coarse devam ({$state['cursor_start']}..{$state['cursor_end']}).\n";
}

function runSyncStep(
    array $state,
    string $code,
    string $type,
    DateTime $today,
    int $syncChunkDays
): void {
    $firstAvailable = !empty($state['first_available_date']) ? new DateTime($state['first_available_date']) : null;
    if ($firstAvailable === null) {
        $state['mode'] = 'discovering';
        TefasCache::upsertSyncState($state);
        return;
    }

    $cursorStart = !empty($state['cursor_start']) ? new DateTime($state['cursor_start']) : clone $firstAvailable;
    if ($cursorStart > $today) {
        $missing = tefas_missing_weekday_dates($code, $firstAvailable, $today);
        if (!empty($missing)) {
            $cursorStart = new DateTime(min($missing));
            echo "[sync][$code] geçmiş boşluk bulundu, cursor {$cursorStart->format('Y-m-d')} tarihine döndü.\n";
        } else {
            $cursorStart = clone $today;
        }
    }

    $rangeStart = clone $cursorStart;
    $rangeEnd = clone $rangeStart;
    $rangeEnd->modify('+' . ($syncChunkDays - 1) . ' days');
    if ($rangeEnd > $today) $rangeEnd = clone $today;

    $r = tefas_fetch_and_store_range($code, $type, $rangeStart, $rangeEnd, 1);
    if ($r->hasErrors()) {
        $state['retry_count'] = (int)($state['retry_count'] ?? 0) + 1;
        $state['last_error'] = (string)end($r->errors);
        TefasCache::upsertSyncState($state);
        echo "[sync][$code] sync hata: {$state['last_error']}\n";
        return;
    }

    $nextStart = clone $rangeEnd;
    $nextStart->modify('+1 day');
    $state['cursor_start'] = $nextStart->format('Y-m-d');
    $state['cursor_end'] = $rangeEnd->format('Y-m-d');
    $state['last_success_at'] = time();
    $state['last_error'] = null;
    $state['retry_count'] = 0;
    TefasCache::upsertSyncState($state);
    echo "[sync][$code] sync ok ({$rangeStart->format('Y-m-d')}..{$rangeEnd->format('Y-m-d')}).\n";
}

function forwardFillGaps(array $fundSpecs, DateTime $today): int
{
    $db = new SQLite3(__DIR__ . '/cache/tefas_cache.sqlite3');
    $totalFilled = 0;

    foreach ($fundSpecs as $item) {
        $code = $item['code'];

        $stmt = $db->prepare("SELECT tarih, fon_kodu, fiyat, kisi_sayisi, portfoy_buyukluk, ted_pay_sayisi, fon_unvan FROM fon_fiyat WHERE fon_kodu = :fund ORDER BY tarih");
        $stmt->bindValue(':fund', $code, SQLITE3_TEXT);
        $result = $stmt->execute();
        $rows = [];
        while ($r = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $r;

        if (empty($rows)) continue;

        $existing = [];
        foreach ($rows as $r) $existing[$r['tarih']] = $r;

        $firstDate = new DateTime($rows[0]['tarih']);
        $filled = 0;
        $cur = clone $firstDate;

        while ($cur <= $today) {
            if ((int)$cur->format('N') <= 5) {
                $ds = $cur->format('Y-m-d');
                if (!isset($existing[$ds])) {
                    $prevDate = null;
                    $check = clone $cur;
                    $check->modify('-1 day');
                    for ($i = 0; $i < 60; $i++) {
                        $prevDs = $check->format('Y-m-d');
                        if (isset($existing[$prevDs])) {
                            $prevDate = $prevDs;
                            break;
                        }
                        $check->modify('-1 day');
                    }

                    if ($prevDate !== null) {
                        $src = $existing[$prevDate];
                        $now = time();

                        $ins = $db->prepare("INSERT OR IGNORE INTO fon_fiyat (fon_kodu, tarih, fiyat, kisi_sayisi, portfoy_buyukluk, ted_pay_sayisi, fon_unvan, kayit_zamani) VALUES (:fk, :t, :f, :ks, :pb, :tps, :fu, :kz)");
                        $ins->bindValue(':fk', $code, SQLITE3_TEXT);
                        $ins->bindValue(':t', $ds, SQLITE3_TEXT);
                        $ins->bindValue(':f', $src['fiyat'], SQLITE3_FLOAT);
                        $ins->bindValue(':ks', $src['kisi_sayisi'], SQLITE3_INTEGER);
                        $ins->bindValue(':pb', $src['portfoy_buyukluk'], SQLITE3_FLOAT);
                        $ins->bindValue(':tps', $src['ted_pay_sayisi'], SQLITE3_FLOAT);
                        $ins->bindValue(':fu', $src['fon_unvan'], SQLITE3_TEXT);
                        $ins->bindValue(':kz', $now, SQLITE3_INTEGER);
                        $ins->execute();
                        $filled++;
                        $existing[$ds] = $src;
                    }
                }
            }
            $cur->modify('+1 day');
        }

        $totalFilled += $filled;
        if ($filled > 0) {
            echo "[sync][$code] forward-fill: $filled boşluk dolduruldu.\n";
        }
    }

    $db->close();
    return $totalFilled;
}


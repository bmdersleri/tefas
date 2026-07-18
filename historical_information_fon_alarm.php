<?php
// historical_information.php

/* ─────────────────────────────────────────────
 * Yapılandırma sabitleri  (ihtiyaca göre değiştir)
 * ───────────────────────────────────────────── */
define('TEFAS_TIMEOUT',      20);   // saniye – tek istek zaman aşımı
define('TEFAS_CONNECT_TO',    8);   // saniye – bağlantı zaman aşımı
define('TEFAS_MAX_RETRIES',   3);   // başarısız istek için maksimum deneme sayısı
define('TEFAS_RETRY_DELAY',   2);   // denemeler arası bekleme (saniye)
define('TEFAS_CHUNK_DAYS',   90);   // TEFAS'ın desteklediği maksimum gün aralığı

/* ─────────────────────────────────────────────
 * Hata kayıt sınıfı
 * ─────────────────────────────────────────────
 * historical_information() artık bir istisna fırlatmak yerine
 * kısmi veri + uyarı listesi döndürebiliyor.
 * ───────────────────────────────────────────── */
class TefasResult
{
    /** @var array  Başarıyla çekilen satırlar */
    public array $data = [];

    /** @var TefasChunkError[]  Başarısız chunk'lara ait hatalar */
    public array $errors = [];

    public function hasErrors(): bool  { return !empty($this->errors); }
    public function hasData(): bool    { return !empty($this->data);   }
}

class TefasChunkError
{
    public string $start;
    public string $end;
    public string $message;
    public int    $attempts;

    public function __construct(string $start, string $end, string $message, int $attempts)
    {
        $this->start    = $start;
        $this->end      = $end;
        $this->message  = $message;
        $this->attempts = $attempts;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s – %s: %s (%d deneme)',
            $this->start, $this->end, $this->message, $this->attempts
        );
    }
}

/* ─────────────────────────────────────────────
 * Ana fonksiyon
 * ───────────────────────────────────────────── */

/**
 * TEFAS'tan tarihsel fon verisi çeker.
 *
 * @param  string        $information_type  "BindHistoryInfo" | "BindHistoryAllocation"
 * @param  string        $fund_type         "YAT" | "EMK" | "BYF"
 * @param  DateTime      $start_date
 * @param  DateTime      $end_date
 * @param  string|null   $fund_code         Fon kodu (null = tüm fonlar)
 * @param  bool          $throw_on_error    true → herhangi bir chunk hatasında istisna fırlat
 *                                          false → kısmi veri + TefasResult::$errors döndür
 * @return TefasResult
 */
function historical_information(
    string   $information_type,
    string   $fund_type,
    DateTime $start_date,
    DateTime $end_date,
    ?string  $fund_code     = null,
    bool     $throw_on_error = false
): TefasResult {

    $allowed_info_types = ['BindHistoryInfo', 'BindHistoryAllocation'];
    $allowed_fund_types = ['YAT', 'EMK', 'BYF'];

    if (!in_array($information_type, $allowed_info_types, true)) {
        throw new InvalidArgumentException(
            'information_type şunlardan biri olmalı: ' . implode(', ', $allowed_info_types)
        );
    }
    if (!in_array($fund_type, $allowed_fund_types, true)) {
        throw new InvalidArgumentException(
            'fund_type şunlardan biri olmalı: ' . implode(', ', $allowed_fund_types)
        );
    }
    if ($start_date > $end_date) {
        throw new InvalidArgumentException('start_date, end_date\'den küçük veya eşit olmalı!');
    }

    $endpoint    = 'https://www.tefas.gov.tr/api/DB/' . $information_type;
    $date_chunks = build_date_chunks($start_date, $end_date, TEFAS_CHUNK_DAYS);
    $result      = new TefasResult();

    for ($i = 0; $i < count($date_chunks) - 1; $i++) {
        $bastarih = format_tr_date($date_chunks[$i]);
        $bittarih = format_tr_date($date_chunks[$i + 1]);

        $post_fields = [
            'fontip'   => $fund_type,
            'bastarih' => $bastarih,
            'bittarih' => $bittarih,
            'fonkod'   => $fund_code ?? '',
        ];

        // ── Retry döngüsü ──────────────────────────────
        $attempt      = 0;
        $last_error   = '';
        $chunk_ok     = false;

        while ($attempt < TEFAS_MAX_RETRIES) {
            $attempt++;

            [$response, $curl_error, $http_code] = http_post_form_ex($endpoint, $post_fields);

            // cURL seviyesinde hata (timeout vb.)
            if ($response === false) {
                $last_error = $curl_error ?: 'cURL hatası';
                if ($attempt < TEFAS_MAX_RETRIES) {
                    sleep(TEFAS_RETRY_DELAY);
                }
                continue;
            }

            // HTTP hata kodu
            if ($http_code >= 500) {
                $last_error = "TEFAS sunucu hatası (HTTP $http_code)";
                if ($attempt < TEFAS_MAX_RETRIES) {
                    sleep(TEFAS_RETRY_DELAY);
                }
                continue;
            }

            // JSON parse
            $json = json_decode($response, true);
            if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
                // Bazen TEFAS boş ya da HTML döndürüyor
                $last_error = 'Geçersiz veya boş JSON yanıtı';
                if ($attempt < TEFAS_MAX_RETRIES) {
                    sleep(TEFAS_RETRY_DELAY);
                }
                continue;
            }

            // Başarılı
            foreach ($json['data'] as $row) {
                $result->data[] = $row;
            }
            $chunk_ok = true;
            break;
        }
        // ── /Retry döngüsü ─────────────────────────────

        if (!$chunk_ok) {
            $err = new TefasChunkError($bastarih, $bittarih, $last_error, $attempt);
            if ($throw_on_error) {
                throw new RuntimeException((string) $err);
            }
            $result->errors[] = $err;
            // Kısmi veri moduunda devam ediyoruz; bu chunk atlanıyor
        }
    }

    // ── Tip dönüşümleri ────────────────────────────────
    _convert_dates($result->data);

    if ($information_type === 'BindHistoryInfo') {
        _cast_history_info($result->data);
    } else {
        _cast_allocation($result->data);
    }

    return $result;
}

/* ─────────────────────────────────────────────
 * İç yardımcı fonksiyonlar
 * ───────────────────────────────────────────── */

function _convert_dates(array &$rows): void
{
    foreach ($rows as &$row) {
        if (isset($row['TARIH']) && $row['TARIH'] !== null) {
            $seconds      = (int) round($row['TARIH'] / 1000);
            $dt           = new DateTime('@' . $seconds);
            $dt->setTimezone(new DateTimeZone('Europe/Istanbul'));
            $row['TARIH'] = $dt->format('Y-m-d');
        }
    }
    unset($row);
}

function _cast_history_info(array &$rows): void
{
    $numeric = ['FIYAT', 'TEDPAYSAYISI', 'KISISAYISI', 'PORTFOYBUYUKLUK'];
    foreach ($rows as &$row) {
        unset($row['BORSABULTENFIYAT']);
        foreach ($numeric as $f) {
            if (isset($row[$f])) {
                $row[$f] = (float) $row[$f];
            }
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
            if (isset($row[$f]) && is_numeric($row[$f])) {
                $row[$f] = (float) $row[$f];
            }
        }
    }
    unset($row);
}

/**
 * cURL POST – HTTP durum kodu ve hata mesajıyla birlikte döner.
 *
 * @return array{0: string|false, 1: string, 2: int}
 *         [response|false,  curl_error_string,  http_status_code]
 */
function http_post_form_ex(string $url, array $fields): array
{
    $ch          = curl_init($url);
    $post_fields = http_build_query($fields, '', '&');

    $headers = [
        'User-Agent: https://github.com/can-taslicukur/tefasr-php',
        'Accept: application/json, text/javascript, */*; q=0.01',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With: XMLHttpRequest',
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => $post_fields,
        CURLOPT_HTTPHEADER      => $headers,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_TIMEOUT         => TEFAS_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT  => TEFAS_CONNECT_TO,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_ENCODING        => '',          // gzip/deflate otomatik
    ]);

    $response  = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return [false, $curl_err, $http_code];
    }

    return [$response, '', $http_code];
}

/* ─────────────────────────────────────────────
 * Geriye dönük uyumluluk sarmalayıcısı
 * Eski kod  historical_information(...) → array  bekliyor;
 * TefasResult::$data döndürerek kırılmayı önle.
 * ───────────────────────────────────────────── */
function historical_information_compat(
    string   $information_type,
    string   $fund_type,
    DateTime $start_date,
    DateTime $end_date,
    ?string  $fund_code = null
): array {
    $result = historical_information(
        $information_type, $fund_type, $start_date, $end_date, $fund_code
    );
    return $result->data;
}

/* ─────────────────────────────────────────────
 * Tarih yardımcıları
 * ───────────────────────────────────────────── */

function build_date_chunks(DateTime $start, DateTime $end, int $step_days = 90): array
{
    $chunks  = [];
    $current = clone $start;

    while ($current < $end) {
        $chunks[] = clone $current;
        $current->modify('+' . $step_days . ' days');
    }
    $chunks[] = clone $end;

    if (count($chunks) === 1) {
        $chunks[] = clone $chunks[0];
    }

    return $chunks;
}

function format_tr_date(DateTime $dt): string
{
    return $dt->format('d.m.Y');
}

/*
// ── Kullanım örneği ──────────────────────────

$start = new DateTime('2025-08-01');
$end   = new DateTime('2025-10-15');

$result = historical_information('BindHistoryInfo', 'YAT', $start, $end, 'HEH');

if ($result->hasErrors()) {
    foreach ($result->errors as $e) {
        error_log('[TEFAS] ' . $e);        // sunucu loguna yaz
        echo "⚠️ Uyarı: $e\n";
    }
}

if ($result->hasData()) {
    print_r(array_slice($result->data, 0, 5));
} else {
    echo "Hiç veri çekilemedi.\n";
}
*/
?>


<?php
declare(strict_types=1);

/**
 * Alarm Bildirim Ucu — Cron ile çalıştırılır.
 * Kullanım: php notify.php [email1] [email2] ...
 * Veya:     php notify.php              → varsayılan e-posta listesini kullanır
 *
 * Son 1 iş günündeki alarm skorlarını kontrol eder.
 * Kırmızı alarm eşiğini aşan fonlar varsa e-posta gönderir.
 */

require_once __DIR__ . '/historical_information.php';
require_once __DIR__ . '/lib/stats.php';
require_once __DIR__ . '/lib/fund_analyzer.php';

/* ── E-posta alıcıları ── */
$default_emails = [
    // Buraya kendi e-postanızı ekleyin:
    // 'admin@kirbas.com',
];

$cli_emails = array_slice($argv, 1);
$emails = !empty($cli_emails) ? $cli_emails : $default_emails;

if (empty($emails)) {
    echo "Hata: E-posta alıcısı belirtilmedi.\n";
    echo "Kullanım: php notify.php email1@email.com email2@email.com\n";
    exit(1);
}

/* ── Parametreler ── */
$fund_type   = 'YAT';
$days_back   = 3;
$end_date    = new DateTime();
$start_date  = (new DateTime())->modify("-{$days_back} days");
$alarm_red   = 70;

$fund_codes = ['TLY','PHE','PBR','LTL','DFI','KZL','KUT','YZG','MJG'];

/* ── Analiz ── */
$analyzer = new FundAnalyzer();
$alarm_thresholds = ['watch' => 55, 'red' => $alarm_red, 'critical' => 85];

foreach ($fund_codes as $code) {
    $analyzer->analyzeFund($code, $fund_type, $start_date, $end_date, 14, 14, $alarm_thresholds);
}

$metrics = $analyzer->metrics_by_fund;
if (empty($metrics)) {
    echo "Veri yok, bildirim gönderilmiyor.\n";
    exit(0);
}

/* ── Alarm kontrolü ── */
$alarms = [];
foreach ($metrics as $code => $m) {
    $score = $m['latest_flow_score'] ?? null;
    if ($score === null) continue;
    if ($score >= $alarm_red) {
        $alarms[$code] = [
            'score'    => $score,
            'status'   => $m['flow_status'] ?? '—',
            'net_flow' => $m['latest_net_flow'] ?? null,
        ];
    }
}

if (empty($alarms)) {
    echo "Alarm yok — bildirim gönderilmiyor.\n";
    exit(0);
}

/* ── E-posta oluştur ── */
usort($alarms, fn($a, $b) => $b['score'] <=> $a['score']);

$body  = "TEFAS Alarm Raporu — " . date('Y-m-d H:i') . "\n";
$body .= str_repeat('=', 50) . "\n\n";
$body .= "Kırmızı alarm eşiği: {$alarm_red}+\n";
$body .= count($alarms) . " fon alarm bölgesinde:\n\n";

foreach ($alarms as $code => $a) {
    $nf = $a['net_flow'] !== null ? number_format($a['net_flow'], 0) . ' TL' : '—';
    $body .= sprintf("  %-8s  Skor: %5.1f  |  Durum: %-14s  |  Akış: %s\n",
        $code, $a['score'], $a['status'], $nf);
}

$body .= "\nDetay: https://kirbas.com/tefas/?fund_codes=" . implode(',', array_keys($alarms)) . "&fund_type={$fund_type}\n";

/* ── E-posta gönder ── */
$subject = "⚠️ TEFAS Alarm: " . count($alarms) . " fon kırmızıda — " . date('Y-m-d');
$headers = "From: TEFAS Alarm <alarm@kirbas.com>\r\n" .
           "Content-Type: text/plain; charset=UTF-8\r\n";

$sent = 0;
foreach ($emails as $to) {
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
    if (mail($to, $subject, $body, $headers)) {
        echo "  ✓ Gönderildi: {$to}\n";
        $sent++;
    } else {
        echo "  ✗ Başarısız: {$to}\n";
    }
}

echo "\nToplam: " . count($alarms) . " alarm, {$sent} e-posta gönderildi.\n";

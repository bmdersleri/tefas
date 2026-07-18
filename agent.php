<?php
declare(strict_types=1);

/**
 * TEFAS Agent API & Knowledge Base
 * AI agent'ları için fon verileri ve bilgi bankası.
 * 
 * GET /tefas/agent           → JSON API (varsayılan)
 * GET /tefas/agent?format=html → HTML bilgi bankası
 */

require_once __DIR__ . '/historical_information.php';
require_once __DIR__ . '/lib/stats.php';
require_once __DIR__ . '/lib/fund_analyzer.php';

// ── Sabitler ──────────────────────────────────────────────
define('AGENT_CACHE_DIR', '/tmp');
define('AGENT_CACHE_TTL', 3600); // 1 saat
define('AGENT_DEFAULT_FUNDS', 'TLY,PHE,PBR,LTL,DFI,KZL,KUT,YZG,MJG');
define('AGENT_DEFAULT_TYPE', 'YAT');
define('AGENT_LOOKBACK_DAYS', 180); // Son 6 ay veri
define('AGENT_DAILY_SERIES_DAYS', 30);
define('AGENT_PRICE_SERIES_DAYS', 60);
define('INFLATION_ANNUAL_PCT', 40.0);

// ── Parametreler ──────────────────────────────────────────
$format = $_GET['format'] ?? 'json';
$fund_codes_input = $_GET['codes'] ?? $_GET['fund_codes'] ?? '';
$fund_type = $_GET['type'] ?? AGENT_DEFAULT_TYPE;

// Alarm eşikleri (varsayılan: panel ile aynı)
$alarm_watch    = 55;
$alarm_red      = 70;
$alarm_critical = 85;
$flow_window    = 14;
$div_window     = 14;

define('ALARM_SCORE_WATCH', $alarm_watch);
define('ALARM_SCORE_RED', $alarm_red);
define('ALARM_SCORE_CRITICAL', $alarm_critical);
define('FLOW_WINDOW', $flow_window);
define('DIVERGENCE_WINDOW', $div_window);

$backtest_horizons = [5, 10, 20];
$alarm_thresholds = [
    'watch'    => $alarm_watch,
    'red'      => $alarm_red,
    'critical' => $alarm_critical,
];

// ── Tarihler ──────────────────────────────────────────────
$end_date = new DateTime();
$start_date = (clone $end_date)->modify('-' . AGENT_LOOKBACK_DAYS . ' days');

// ── Fon kodları ───────────────────────────────────────────
if (!empty($fund_codes_input)) {
    $fund_codes = array_filter(array_map('trim', preg_split('/[,\s]+/', $fund_codes_input)));
} else {
    $fund_codes = array_filter(array_map('trim', explode(',', AGENT_DEFAULT_FUNDS)));
}

// ── Cache kontrol ─────────────────────────────────────────
$cache_key = md5(implode(',', $fund_codes) . '|' . $fund_type . '|' . $start_date->format('Y-m-d'));
$cache_file = AGENT_CACHE_DIR . '/tefas_agent_' . $cache_key . '.json';
$cached_data = null;

if (file_exists($cache_file)) {
    $age = time() - filemtime($cache_file);
    if ($age < AGENT_CACHE_TTL) {
        $cached_data = file_get_contents($cache_file);
    }
}

if ($cached_data !== null) {
    $json_output = $cached_data;
} else {
    // ── Analiz ────────────────────────────────────────────
    $analyzer = new FundAnalyzer();
    $error_msg = null;

    foreach ($fund_codes as $code) {
        if ($code === '') continue;
        $analyzer->analyzeFund($code, $fund_type, $start_date, $end_date, $flow_window, $div_window, $alarm_thresholds);
        if ($analyzer->error_msg !== null) {
            $error_msg = $analyzer->error_msg;
        }
    }

    $datasets = $analyzer->prepareDatasets();
    $dashboard_summary = $analyzer->buildDashboardSummary();
    $backtest_summary = $analyzer->calculateBacktest($backtest_horizons);
    $metrics_by_fund = $analyzer->metrics_by_fund;

    // ── JSON oluştur ──────────────────────────────────────
    $funds_output = [];

    foreach ($metrics_by_fund as $code => $m) {
        // Günlük getiri serisi (son N gün)
        $daily_returns_raw = $analyzer->daily_returns_by_fund[$code] ?? [];
        ksort($daily_returns_raw);
        $daily_returns_30d = [];
        $ret_dates = array_keys($daily_returns_raw);
        $ret_start = max(0, count($ret_dates) - AGENT_DAILY_SERIES_DAYS);
        for ($i = $ret_start; $i < count($ret_dates); $i++) {
            $d = $ret_dates[$i];
            $daily_returns_30d[$d] = round($daily_returns_raw[$d] * 100, 4);
        }

        // Fiyat serisi (son N gün)
        $raw_prices = $analyzer->raw_price_by_fund[$code] ?? [];
        ksort($raw_prices);
        $prices_60d = [];
        $price_dates = array_keys($raw_prices);
        $price_start = max(0, count($price_dates) - AGENT_PRICE_SERIES_DAYS);
        for ($i = $price_start; $i < count($price_dates); $i++) {
            $d = $price_dates[$i];
            $prices_60d[$d] = round((float)$raw_prices[$d], 4);
        }

        // Katılımcı serisi (son N gün)
        $participants_raw = $analyzer->participants_by_fund[$code] ?? [];
        ksort($participants_raw);
        $participants_60d = [];
        $part_dates = array_keys($participants_raw);
        $part_start = max(0, count($part_dates) - AGENT_PRICE_SERIES_DAYS);
        for ($i = $part_start; $i < count($part_dates); $i++) {
            $d = $part_dates[$i];
            $participants_60d[$d] = (int)$participants_raw[$d];
        }

        // Fon büyüklüğü serisi (son N gün)
        $size_raw = $analyzer->size_by_fund[$code] ?? [];
        ksort($size_raw);
        $size_60d = [];
        $size_dates = array_keys($size_raw);
        $size_start = max(0, count($size_dates) - AGENT_PRICE_SERIES_DAYS);
        for ($i = $size_start; $i < count($size_dates); $i++) {
            $d = $size_dates[$i];
            $size_60d[$d] = round((float)$size_raw[$d], 2);
        }

        // Net akış serisi (son N gün)
        $net_flow_raw = $analyzer->net_flow_by_fund[$code] ?? [];
        ksort($net_flow_raw);
        $net_flow_60d = [];
        $nf_dates = array_keys($net_flow_raw);
        $nf_start = max(0, count($nf_dates) - AGENT_PRICE_SERIES_DAYS);
        for ($i = $nf_start; $i < count($nf_dates); $i++) {
            $d = $nf_dates[$i];
            $net_flow_60d[$d] = round((float)$net_flow_raw[$d], 2);
        }

        // Alarm skoru serisi (son N gün)
        $flow_score_raw = $analyzer->flow_score_by_fund[$code] ?? [];
        ksort($flow_score_raw);
        $flow_score_60d = [];
        $fs_dates = array_keys($flow_score_raw);
        $fs_start = max(0, count($fs_dates) - AGENT_PRICE_SERIES_DAYS);
        for ($i = $fs_start; $i < count($fs_dates); $i++) {
            $d = $fs_dates[$i];
            $flow_score_60d[$d] = round((float)$flow_score_raw[$d], 2);
        }

        $funds_output[$code] = [
            'code' => $code,
            'type' => $fund_type,

            // Temel Bilgiler
            'start_date'                    => $m['start_date'],
            'end_date'                      => $m['end_date'],
            'base_price'                    => round($m['base_price'], 4),
            'last_price'                    => round($m['last_price'], 4),

            // Getiri Metrikleri
            'period_return_pct'             => round($m['period_return'], 2),
            'annual_return_pct'             => $m['annual_return'] !== null ? round($m['annual_return'] * 100, 2) : null,
            'real_return_pct'               => $m['real_return'] !== null ? round($m['real_return'] * 100, 2) : null,
            'mom_3m_pct'                    => $m['mom_3m'] !== null ? round($m['mom_3m'] * 100, 2) : null,
            'mom_6m_pct'                    => $m['mom_6m'] !== null ? round($m['mom_6m'] * 100, 2) : null,
            'mom_12m_pct'                   => $m['mom_12m'] !== null ? round($m['mom_12m'] * 100, 2) : null,
            'win_rate_pct'                  => $m['win_rate'] !== null ? round($m['win_rate'], 1) : null,
            'best_day_pct'                  => $m['best_day'] !== null ? round($m['best_day'], 2) : null,
            'worst_day_pct'                 => $m['worst_day'] !== null ? round($m['worst_day'], 2) : null,
            'pos_streak_days'               => $m['pos_streak'],
            'neg_streak_days'               => $m['neg_streak'],

            // Risk Metrikleri
            'annual_vol_pct'                => $m['annual_vol'] !== null ? round($m['annual_vol'] * 100, 2) : null,
            'daily_vol_pct'                 => $m['daily_vol'] !== null ? round($m['daily_vol'] * 100, 2) : null,
            'max_drawdown_pct'              => round($m['max_drawdown'], 2),
            'dd_current_pct'                => $m['dd_current'] !== null ? round($m['dd_current'], 2) : null,
            'dd_duration_days'              => $m['dd_duration'],
            'dd_recovery_days'              => $m['dd_recovery'],
            'var95_pct'                     => $m['var95'] !== null ? round($m['var95'], 2) : null,
            'var99_pct'                     => $m['var99'] !== null ? round($m['var99'], 2) : null,
            'cvar95_pct'                    => $m['cvar95'] !== null ? round($m['cvar95'], 2) : null,
            'cvar99_pct'                    => $m['cvar99'] !== null ? round($m['cvar99'], 2) : null,
            'skewness'                      => $m['skewness'] !== null ? round($m['skewness'], 3) : null,
            'kurtosis'                      => $m['kurtosis'] !== null ? round($m['kurtosis'], 3) : null,
            'min_price'                     => round($m['min_price'], 4),
            'max_price'                     => round($m['max_price'], 4),

            // Oran Metrikleri
            'sharpe'                        => $m['sharpe'] !== null ? round($m['sharpe'], 2) : null,
            'sortino'                       => $m['sortino'] !== null ? round($m['sortino'], 2) : null,
            'calmar'                        => $m['calmar'] !== null ? round($m['calmar'], 2) : null,
            'omega'                         => $m['omega'] !== null ? round($m['omega'], 2) : null,
            'beta'                          => $m['beta'] !== null ? round($m['beta'], 2) : null,
            'treynor'                       => $m['treynor'] !== null ? round($m['treynor'], 2) : null,
            'information_ratio'             => $m['information_ratio'] !== null ? round($m['information_ratio'], 2) : null,
            'r_squared'                     => $m['r_squared'] !== null ? round($m['r_squared'], 1) : null,
            'tracking_error'                => $m['tracking_error'] !== null ? round($m['tracking_error'], 4) : null,

            // Akış Analizi
            'latest_flow_score'             => $m['latest_flow_score'] !== null ? round($m['latest_flow_score'], 2) : null,
            'flow_status'                   => $m['flow_status'],
            'latest_net_flow_tl'            => $m['latest_net_flow'] !== null ? round($m['latest_net_flow'], 0) : null,
            'latest_net_flow_pct'           => $m['latest_net_flow_pct'] !== null ? round($m['latest_net_flow_pct'], 4) : null,
            'sum_net_flow_tl'               => $m['sum_net_flow'] !== null ? round($m['sum_net_flow'], 0) : null,
            'avg_net_flow_pct'              => $m['avg_net_flow_pct'] !== null ? round($m['avg_net_flow_pct'], 4) : null,
            'negative_flow_days'            => $m['negative_flow_days'],
            'positive_flow_days'            => $m['positive_flow_days'],
            'alert_days'                    => $m['alert_days'],
            'watch_days'                    => $m['watch_days'],
            'alert_ratio_pct'               => $m['alert_ratio'] !== null ? round($m['alert_ratio'], 2) : null,
            'latest_participant_chg_pct'    => $m['latest_participant_chg_pct'] !== null ? round($m['latest_participant_chg_pct'], 4) : null,
            'latest_unit_chg_pct'           => $m['latest_unit_chg_pct'] !== null ? round($m['latest_unit_chg_pct'], 4) : null,
            'latest_avg_size_chg_pct'       => $m['latest_avg_size_chg_pct'] !== null ? round($m['latest_avg_size_chg_pct'], 4) : null,
            'last_avg_participant_size'     => $m['last_avg_participant_size'] !== null ? round($m['last_avg_participant_size'], 0) : null,
            'avg_participant_size_chg_pct'  => $m['avg_participant_size_chg'] !== null ? round($m['avg_participant_size_chg'], 2) : null,
            'last_flow_date'                => $m['last_flow_date'],

            // Seriler
            'daily_returns_30d'             => $daily_returns_30d,
            'prices_60d'                    => $prices_60d,
            'participants_60d'              => $participants_60d,
            'fund_size_60d'                 => $size_60d,
            'net_flow_60d'                  => $net_flow_60d,
            'flow_score_60d'                => $flow_score_60d,
        ];
    }

    // ── Korelasyon matrisi ─────────────────────────────────
    $corr = $datasets['correlation'] ?? [];
    $correlation_rounded = [];
    foreach ($corr as $c1 => $row) {
        $correlation_rounded[$c1] = [];
        foreach ($row as $c2 => $val) {
            $correlation_rounded[$c1][$c2] = $val !== null ? round($val, 3) : null;
        }
    }

    // ── Backtest ───────────────────────────────────────────
    $backtest_output = [];
    foreach ($backtest_summary as $code => $bt) {
        $backtest_output[$code] = [
            'signal_count'     => $bt['signal_count'],
            'last_signal_date' => $bt['last_signal_date'],
            'avg_ret_5d'       => $bt['avg_ret_5d'] !== null ? round($bt['avg_ret_5d'], 2) : null,
            'avg_ret_10d'      => $bt['avg_ret_10d'] !== null ? round($bt['avg_ret_10d'], 2) : null,
            'avg_ret_20d'      => $bt['avg_ret_20d'] !== null ? round($bt['avg_ret_20d'], 2) : null,
            'avg_mdd_20d'      => $bt['avg_mdd_20d'] !== null ? round($bt['avg_mdd_20d'], 2) : null,
            'hit_rate_20d'     => $bt['hit_rate_20d'] !== null ? round($bt['hit_rate_20d'], 1) : null,
            'comment'          => $bt['comment'],
        ];
    }

    // ── Dashboard ──────────────────────────────────────────
    $dashboard_output = [
        'fund_count'           => $dashboard_summary['fund_count'] ?? 0,
        'red_count'            => $dashboard_summary['red_count'] ?? 0,
        'watch_count'          => $dashboard_summary['watch_count'] ?? 0,
        'top_alarm'            => $dashboard_summary['top_alarm_code'] !== null ? [
            'code'  => $dashboard_summary['top_alarm_code'],
            'score' => round($dashboard_summary['top_alarm_score'], 2),
        ] : null,
        'top_negative_flow'    => $dashboard_summary['top_negative_flow_code'] !== null ? [
            'code'     => $dashboard_summary['top_negative_flow_code'],
            'flow_pct' => round($dashboard_summary['top_negative_flow_pct'], 4),
            'flow_tl'  => $dashboard_summary['top_negative_flow_tl'] !== null ? round($dashboard_summary['top_negative_flow_tl'], 0) : null,
        ] : null,
        'strongest_composite'  => $dashboard_summary['strongest_code'] !== null ? [
            'code'  => $dashboard_summary['strongest_code'],
            'score' => round($dashboard_summary['strongest_score'], 2),
        ] : null,
        'best_return'          => $dashboard_summary['best_return_code'] !== null ? [
            'code'        => $dashboard_summary['best_return_code'],
            'return_pct'  => round($dashboard_summary['best_return'], 2),
        ] : null,
    ];

    // ── Son montaj ─────────────────────────────────────────
    $output = [
        'meta' => [
            'generated_at'      => (new DateTime())->format('c'),
            'data_start'        => $start_date->format('Y-m-d'),
            'data_end'          => $end_date->format('Y-m-d'),
            'fund_count'        => count($funds_output),
            'fund_type'         => $fund_type,
            'alarm_thresholds'  => $alarm_thresholds,
            'inflation_annual_pct' => INFLATION_ANNUAL_PCT,
            'days_analyzed'     => (int)$start_date->diff($end_date)->format('%r%a'),
            'error'             => $error_msg,
        ],
        'funds'               => $funds_output,
        'correlation_matrix'  => $correlation_rounded,
        'backtest'            => $backtest_output,
        'dashboard'           => $dashboard_output,
    ];

    $json_output = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    // Cache yaz
    file_put_contents($cache_file, $json_output);
}

// ── Çıktı ─────────────────────────────────────────────────
if ($format === 'html') {
    header('Content-Type: text/html; charset=utf-8');
    echo renderAgentHtml($json_output);
} else {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    header('X-TEFAS-Agent-API: v1');
    echo $json_output;
}


// ════════════════════════════════════════════════════════════
// HTML Bilgi Bankası
// ════════════════════════════════════════════════════════════

function renderAgentHtml(string $json_data): string
{
    $data = json_decode($json_data, true);
    $meta = $data['meta'] ?? [];
    $funds = $data['funds'] ?? [];
    $corr = $data['correlation_matrix'] ?? [];
    $bt = $data['backtest'] ?? [];
    $dash = $data['dashboard'] ?? [];
    $fund_count = $meta['fund_count'] ?? 0;

    // Fon listesi JSON'dan çıkar
    $fund_list_html = '';
    foreach ($funds as $code => $f) {
        $score = $f['latest_flow_score'] ?? null;
        $status = $f['flow_status'] ?? 'NORMAL';
        $status_class = match($status) {
            'KRİTİK' => 'critical', 'KIRMIZI ALARM' => 'red',
            'UYARI' => 'watch', 'İZLEME' => 'info', default => 'normal',
        };
        $fund_list_html .= "<tr>
            <td><strong>{$code}</strong></td>
            <td>{$f['type']}</td>
            <td>" . number_format($f['period_return_pct'], 2) . "%</td>
            <td>" . ($f['annual_return_pct'] !== null ? number_format($f['annual_return_pct'], 2) . '%' : '—') . "</td>
            <td>" . ($f['real_return_pct'] !== null ? number_format($f['real_return_pct'], 2) . '%' : '—') . "</td>
            <td>" . number_format($f['max_drawdown_pct'], 2) . "%</td>
            <td>" . ($f['sharpe'] !== null ? number_format($f['sharpe'], 2) : '—') . "</td>
            <td>" . ($f['omega'] !== null ? number_format($f['omega'], 2) : '—') . "</td>
            <td class=\"status-{$status_class}\">{$status}</td>
            <td>" . ($score !== null ? number_format($score, 1) : '—') . "</td>
        </tr>";
    }

    // Korelasyon matrisi HTML
    $corr_codes = array_keys($corr);
    $corr_header = '<th></th>' . implode('', array_map(fn($c) => "<th>{$c}</th>", $corr_codes));
    $corr_rows = '';
    foreach ($corr_codes as $c1) {
        $cells = '';
        foreach ($corr_codes as $c2) {
            $val = $corr[$c1][$c2] ?? null;
            if ($val === null) {
                $cells .= '<td>—</td>';
            } else {
                $abs = abs($val);
                $cls = $c1 === $c2 ? 'corr-self' : ($abs > 0.7 ? 'corr-high' : ($abs < 0.3 ? 'corr-low' : ''));
                $cells .= "<td class=\"{$cls}\">" . number_format($val, 2) . "</td>";
            }
        }
        $corr_rows .= "<tr><th>{$c1}</th>{$cells}</tr>";
    }

    // Backtest HTML
    $bt_rows = '';
    foreach ($bt as $code => $b) {
        $bt_rows .= "<tr>
            <td><strong>{$code}</strong></td>
            <td>{$b['signal_count']}</td>
            <td>" . ($b['last_signal_date'] ?? '—') . "</td>
            <td>" . ($b['avg_ret_5d'] !== null ? number_format($b['avg_ret_5d'], 2) . '%' : '—') . "</td>
            <td>" . ($b['avg_ret_10d'] !== null ? number_format($b['avg_ret_10d'], 2) . '%' : '—') . "</td>
            <td>" . ($b['avg_ret_20d'] !== null ? number_format($b['avg_ret_20d'], 2) . '%' : '—') . "</td>
            <td>" . ($b['hit_rate_20d'] !== null ? number_format($b['hit_rate_20d'], 1) . '%' : '—') . "</td>
            <td>{$b['comment']}</td>
        </tr>";
    }

    $json_pretty = htmlspecialchars($json_data);

    return <<<HTML
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TEFAS Agent API — AI Bilgi Bankası</title>
    <style>
        :root {
            --bg: #0d1117; --surface: #161b22; --border: #30363d;
            --text: #e6edf3; --muted: #8b949e; --accent: #58a6ff;
            --green: #3fb950; --red: #f85149; --orange: #d29922;
            --purple: #bc8cff;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; background: var(--bg); color: var(--text); line-height: 1.6; }
        .container { max-width: 1100px; margin: 0 auto; padding: 2rem 1.5rem; }
        h1 { font-size: 1.8rem; margin-bottom: 0.5rem; color: var(--accent); }
        h2 { font-size: 1.3rem; margin: 2rem 0 0.8rem; color: var(--purple); border-bottom: 1px solid var(--border); padding-bottom: 0.4rem; }
        h3 { font-size: 1.1rem; margin: 1.2rem 0 0.5rem; color: var(--accent); }
        p, li { color: var(--text); margin-bottom: 0.5rem; }
        .meta-info { color: var(--muted); font-size: 0.85rem; margin-bottom: 1.5rem; }
        .endpoint { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 1rem 1.2rem; margin: 1rem 0; font-family: 'SFMono-Regular', Consolas, monospace; font-size: 0.9rem; }
        .endpoint code { color: var(--green); }
        table { width: 100%; border-collapse: collapse; margin: 0.8rem 0; font-size: 0.85rem; }
        th, td { padding: 0.4rem 0.6rem; border: 1px solid var(--border); text-align: left; }
        th { background: var(--surface); color: var(--accent); font-weight: 600; }
        td { color: var(--text); }
        tr:hover { background: rgba(88,166,255,0.05); }
        .status-critical { color: var(--red); font-weight: bold; }
        .status-red { color: var(--red); }
        .status-watch { color: var(--orange); }
        .status-info { color: var(--muted); }
        .status-normal { color: var(--green); }
        .corr-high { background: rgba(248,81,73,0.15); color: var(--red); }
        .corr-low { background: rgba(139,148,158,0.1); color: var(--muted); }
        .corr-self { background: rgba(88,166,255,0.1); color: var(--accent); }
        code { background: var(--surface); padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.88em; color: var(--green); }
        pre { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 1rem; overflow-x: auto; font-size: 0.82rem; color: var(--text); }
        ul { padding-left: 1.5rem; }
        li { margin-bottom: 0.3rem; }
        .note { background: rgba(210,153,34,0.1); border-left: 3px solid var(--orange); padding: 0.8rem 1rem; margin: 1rem 0; border-radius: 0 6px 6px 0; font-size: 0.9rem; }
        .section-intro { color: var(--muted); font-size: 0.92rem; margin-bottom: 1rem; }
        @media (max-width: 768px) { .container { padding: 1rem; } table { font-size: 0.75rem; } }
    </style>
</head>
<body>
<div class="container">

<h1>TEFAS Agent API</h1>
<p class="meta-info">Oluşturulma: {$meta['generated_at']} · Veri aralığı: {$meta['data_start']} — {$meta['data_end']} · {$fund_count} fon · Enflasyon: %{$meta['inflation_annual_pct']}</p>

<!-- ═══ 1. API Kullanımı ═══ -->
<h2>1. API Kullanımı</h2>
<p class="section-intro">Bu sayfa AI agent'ları için TEFAS fon verilerini sunar. JSON API veya bu HTML bilgi bankası olarak erişilebilir.</p>

<div class="endpoint">
    <strong>JSON API:</strong> <code>GET /tefas/agent</code><br>
    <strong>HTML Bilgi Bankası:</strong> <code>GET /tefas/agent?format=html</code><br>
    <strong>Özel fonlar:</strong> <code>GET /tefas/agent?codes=TLY,PHE,PBR</code><br>
    <strong>Fon tipi:</strong> <code>GET /tefas/agent?type=YAT</code> (YAT|EMK|BYF)
</div>

<div class="note">
    <strong>Varsayılan fonlar:</strong> TLY, PHE, PBR, LTL, DFI, KZL, KUT, YZG, MJG<br>
    <strong>Cache süresi:</strong> 1 saat · <strong>Max veri:</strong> Son 180 gün<br>
    <strong>JSON boyutu:</strong> ~15-25KB (8 fon için)
</div>

<!-- ═══ 2. TEFAS Nedir ═══ -->
<h2>2. TEFAS Nedir</h2>
<p>TEFAS (Takasbank Fon Portalı), Türkiye'deki yatırım fonlarının alım/satım ve bilgi platformudur.</p>
<ul>
    <li><strong>Veri kaynağı:</strong> takasbank.gov.tr ve tefas.gov.tr üzerinden günlük fon verileri</li>
    <li><strong>Güncellik:</strong> İş günleri sonunda güncellenir (saat 19:00-21:00 arası)</li>
    <li><strong>Kapsam:</strong> BIST'de işlem gören tüm yatırım fonları (400+ fon)</li>
    <li><strong>Veri türleri:</strong> Fiyat, katılımcı sayısı, fon büyüklüğü, tedavüldeki pay sayısı</li>
</ul>

<!-- ═══ 3. Fon Tipleri ═══ -->
<h2>3. Fon Tipleri</h2>
<table>
    <tr><th>Kod</th><th>Tam Adı</th><th>Profil</th><th>Tahmini Getiri</th><th>Risk</th></tr>
    <tr><td><strong>YAT</strong></td><td>Yatırım Fonu (Hisse Ağırlıklı)</td><td>Borsa hisselerine yatırım yapar</td><td>Yüksek (~%30-500+ yıllık)</td><td>Yüksek (max DD: %10-30)</td></tr>
    <tr><td><strong>EMK</strong></td><td>Emeklilik Yatırım Fonu</td><td>Karma portföy, daha dengeli</td><td>Orta (~%15-40 yıllık)</td><td>Orta (max DD: %5-15)</td></tr>
    <tr><td><strong>BYF</strong></td><td>Borsa Yatırım Fonu (ETF)</td><td>Endeks takibi, düşük yönetim ücreti</td><td>Endekse eşit</td><td>Endeks kadar</td></tr>
</table>

<!-- ═══ 4. Metrik Sözlüğü ═══ -->
<h2>4. Metrik Sözlüğü</h2>

<h3>Getiri Metrikleri</h3>
<table>
    <tr><th>Alan</th><th>Tanım</th><th>İyi</th><th>Kötü</th></tr>
    <tr><td><code>period_return_pct</code></td><td>Dönem başından sonuna toplam getiri (%)</td><td>Pozitif ve yüksek</td><td>Negatif</td></tr>
    <tr><td><code>annual_return_pct</code></td><td>Yıllıklandırılmış getiri (%) — logaritmik extrapolasyon</td><td>Enflasyon üstünde</td><td>Enflasyon altında</td></tr>
    <tr><td><code>real_return_pct</code></td><td>Gerçek getiri = nominal − enflasyon (varsayılan: %40)</td><td>Pozitif = enflasyon yenildi</td><td>Negatif = enflasyon yenildi</td></tr>
    <tr><td><code>mom_3m_pct</code></td><td>Son 3 aydaki getiri (%) — momentum göstergesi</td><td>Pozitif ve yükselen</td><td>Negatif veya düşen</td></tr>
    <tr><td><code>mom_6m_pct</code></td><td>Son 6 aydaki getiri (%)</td><td>Pozitif ve yüksek</td><td>Negatif</td></tr>
    <tr><td><code>mom_12m_pct</code></td><td>Son 12 aydaki getiri (%) — veri yoksa null</td><td>Pozitif ve yüksek</td><td>Negatif</td></tr>
    <tr><td><code>win_rate_pct</code></td><td>Pozitif getiri günlerin yüzdesi (0-100)</td><td>>%55 iyi, >%65 çok iyi</td><td><%45 zayıf</td></tr>
    <tr><td><code>best_day_pct</code></td><td>Tek günde elde edilen en yüksek getiri (%)</td><td>Yüksek (volatilite göstergesi)</td><td>—</td></tr>
    <tr><td><code>worst_day_pct</code></td><td>Tek günde yaşanan en düşük getiri (%)</td><td>—</td><td>Çok negatif (risk göstergesi)</td></tr>
    <tr><td><code>pos_streak_days</code></td><td>En uzun art arda kazanma süresi (gün)</td><td>Uzun (tutarlı yükseliş)</td><td>Kısa (dalgalı)</td></tr>
    <tr><td><code>neg_streak_days</code></td><td>En uzun art arda kaybetme süresi (gün)</td><td>Kısa</td><td>Uzun (psikolojik risk)</td></tr>
</table>

<h3>Risk Metrikleri</h3>
<table>
    <tr><th>Alan</th><th>Tanım</th><th>İyi</th><th>Kötü</th></tr>
    <tr><td><code>annual_vol_pct</code></td><td>Yıllık volatilite (%) — getiri dalgalanması</td><td><%15 düşük risk</td><td>>%30 yüksek risk</td></tr>
    <tr><td><code>daily_vol_pct</code></td><td>Günlük volatilite (%)</td><td>—</td><td>—</td></tr>
    <tr><td><code>max_drawdown_pct</code></td><td>Zirveden dip kaybı (%) — en kötü senaryo</td><td><%5 düşük</td><td>>%20 yüksek</td></tr>
    <tr><td><code>dd_current_pct</code></td><td>Mevcut drawdown (%) — 0 = zirvede</td><td>0 (zirvede)</td><td>>%5 devam eden kayıp</td></tr>
    <tr><td><code>dd_duration_days</code></td><td>Zirveden bu yana geçen gün sayısı</td><td>0 (zirvede)</td><td>>%30 gün (uzun düşüş)</td></tr>
    <tr><td><code>dd_recovery_days</code></td><td>Max drawdown'dan kurtarma süresi (gün)</td><td>Kısa (<30 gün)</td><td>Uzun (>90 gün) veya null (hâlâ dip)</td></tr>
    <tr><td><code>var95_pct</code></td><td>VaR 95: Günlük %5 olasılıkla beklenen kayıp</td><td>—</td><td>—</td></tr>
    <tr><td><code>var99_pct</code></td><td>VaR 99: Günlük %1 olasılıkla beklenen kayıp</td><td>—</td><td>—</td></tr>
    <tr><td><code>cvar95_pct</code></td><td>CVaR 95: VaR eşiğinin altındaki ortalama kayıp</td><td>—</td><td>—</td></tr>
    <tr><td><code>cvar99_pct</code></td><td>CVaR 99: En kötü %1'lik ortalama kayıp</td><td>—</td><td>—</td></tr>
    <tr><td><code>skewness</code></td><td>Dağılım çarpıklığı — negatif = sol kuyruk riski</td><td>>0 (sağ çarpık)</td><td><0 (sol çarpık, aşırı kayıp riski)</td></tr>
    <tr><td><code>kurtosis</code></td><td>Basıklık — yüksek = normalden kalın kuyruklar</td><td>~0 (normal dağılım)</td><td>>3 (aşırı uç değerler)</td></tr>
</table>

<h3>Oran Metrikleri</h3>
<table>
    <tr><th>Alan</th><th>Tanım</th><th>İyi</th><th>Kötü</th></tr>
    <tr><td><code>sharpe</code></td><td>Risksiz getiriye göre birim risk başına getiri</td><td>>1 iyi, >2 mükemmel</td><td><0 negatif getiri</td></tr>
    <tr><td><code>sortino</code></td><td>Sharpe gibi ama sadece aşağı yönlü volatilite</td><td>>1.5 iyi</td><td><0</td></tr>
    <tr><td><code>calmar</code></td><td>Yıllık getiri / max drawdown</td><td>>2 iyi</td><td><1 zayıf</td></tr>
    <tr><td><code>omega</code></td><td>Gains / Losses oranı (tam dağılım)</td><td>>1.5 iyi, >2 mükemmel</td><td><1 kayıp ağırlıklı</td></tr>
    <tr><td><code>beta</code></td><td>Piyasa duyarlılığı (1.0 = piyasa kadar değişken)</td><td>0.5-0.8 (düşük piyasa riski)</td><td>>1.5 (aşırı piyasa riski)</td></tr>
    <tr><td><code>treynor</code></td><td>Sistemsel risk başına getiri</td><td>Yüksek</td><td>Negatif</td></tr>
    <tr><td><code>information_ratio</code></td><td>Benchmark'a göre tutarlı aşırı getiri</td><td>>0.5 iyi</td><td><0</td></tr>
    <tr><td><code>r_squared</code></td><td>Benchmark ile korelasyon karesi (0-100)</td><td>Yüksek (tutarlı)</td><td>Düşük (bağımsız)</td></tr>
    <tr><td><code>tracking_error</code></td><td>Benchmark'tan sapmanın volatilitesi</td><td>Düşük (tutarlı)</td><td>Yüksek (tutarsız)</td></tr>
</table>

<!-- ═══ 5. Alarm Sistemi ═══ -->
<h2>5. Alarm Sistemi</h2>
<p class="section-intro">Akış alarm sistemi, fonlardaki olağandışı para çıkışını tespit etmek için kullanılır.</p>

<h3>Flow Score Hesaplama</h3>
<p><code>flow_score</code> 0-100 arası bir skordur. Şu faktörlere dayanır:</p>
<ul>
    <li><strong>Katılımcı sayısı değişimi:</strong> Katılımcı artışı +puan ekler (küçük yatırımcı çıkışı alarmı)</li>
    <li><strong>Net akış yüzdesi:</strong> Negatif akış yüksek ağırlıkla +puan ekler (para çıkışı)</li>
    <li><strong>Tedavüldeki pay değişimi:</strong> Pay azalması +puan ekler (geri ödeme)</li>
    <li><strong>Kişi başı ortalama büyüklük değişimi:</strong> Büyüklük azalması +puan ekler (küçük yatırımcıDominant)</li>
</ul>

<h3>Alarm Bantları</h3>
<table>
    <tr><th>Bant</th><th>Skor Aralığı</th><th>Anlamı</th><th>Aksiyon</th></tr>
    <tr><td style="color:var(--green)">NORMAL</td><td>0 – 54</td><td>Normal akış koşulları</td><td>İzleme yeterli</td></tr>
    <tr><td style="color:var(--orange)">İZLEME</td><td>55 – 69</td><td>Hafif anormallik</td><td>Dikkatli izleme</td></tr>
    <tr><td style="color:var(--red)">KIRMIZI ALARM</td><td>70 – 84</td><td>Belirgin para çıkışı</td><td>Pozisyon küçültme değerlendir</td></tr>
    <tr><td style="color:var(--red);font-weight:bold">KRİTİK</td><td>85 – 100</td><td>Şiddetli para çıkışı</td><td>Ciddi çıkış riski</td></tr>
</table>

<h3>Flow Status Değerlendirmesi</h3>
<ul>
    <li><strong>NORMAL:</strong> Alarm skoru düşük, akış sağlıklı</li>
    <li><strong>İZLEME:</strong> Geçmişte kısa süreli alarm görmüş ama şu an normal</li>
    <li><strong>UYARI:</strong> Güncel skor izleme bandında veya yakın zamanda yüksek skor görmüş</li>
    <li><strong>KIRMIZI ALARM:</strong> Güncel skor alarm bandında</li>
    <li><strong>KRİTİK:</strong> Güncel skor kritik bandında — acil dikkat</li>
</ul>

<h3>Önemli Akış Metrikleri</h3>
<table>
    <tr><th>Alan</th><th>Tanım</th><th>Yorum</th></tr>
    <tr><td><code>latest_net_flow_tl</code></td><td>Son gün net para akışı (TL)</td><td>Negatif = çıkış, Pozitif = giriş</td></tr>
    <tr><td><code>latest_net_flow_pct</code></td><td>Son gün net akış yüzdesi</td><td>>%1 çıkış dikkat çekici, >%5 ciddi çıkış</td></tr>
    <tr><td><code>sum_net_flow_tl</code></td><td>Dönem toplam net akış (TL)</td><td>Büyük negatif = yapısal çıkış</td></tr>
    <tr><td><code>alert_ratio_pct</code></td><td>Alarm günlerin yüzdesi</td><td>>%20 yüksek alarm oranı</td></tr>
    <tr><td><code>latest_participant_chg_pct</code></td><td>Katılımcı sayısı değişim oranı (%)</td><td>Pozitif = yeni katılımcı girişi (veya çıkış)</td></tr>
    <tr><td><code>latest_unit_chg_pct</code></td><td>Tedavüldeki pay sayısı değişim (%)</td><td>Negatif = geri ödeme (pay azalması)</td></tr>
    <tr><td><code>latest_avg_size_chg_pct</code></td><td>Kişi başı ortalama büyüklük değişimi (%)</td><td>Negatif = küçük yatırımcı Dominant</td></tr>
</table>

<!-- ═══ 6. Getiri Yorumlama ═══ -->
<h2>6. Getiri Yorumlama</h2>

<h3>Nominal vs Gerçek Getiri</h3>
<div class="note">
    <strong>Önemli:</strong> Türkiye'de yıllık enflasyon ~%40 civarındadır. Bir fonun <code>annual_return_pct: 30</code> olması aslında <strong>negatif gerçek getiri</strong> anlamına gelir.<br>
    <code>real_return_pct</code> alanı bunu hesaba katar: <code>real = (1 + nominal) / (1 + enflasyon) − 1</code>
</div>

<h3>Momentum Yorumlama</h3>
<ul>
    <li><strong>mom_3m > 0, mom_6m > 0:</strong> Güçlü yükseliş trendi</li>
    <li><strong>mom_3m > 0, mom_6m < 0:</strong> Son 3 ayda toparlanma</li>
    <li><strong>mom_3m < 0, mom_6m > 0:</strong> Son 3 ayda düzeltme, orta vadeli trend güçlü</li>
    <li><strong>mom_3m < 0, mom_6m < 0:</strong> Zayıflama / düşüş trendi</li>
</ul>

<!-- ═══ 7. Risk Yorumlama ═══ -->
<h2>7. Risk Yorumlama</h2>

<h3>Drawdown Analizi</h3>
<ul>
    <li><code>dd_current_pct = 0</code>: Fon şu an zirvede — en iyi durum</li>
    <li><code>dd_current_pct < 5</code>: Hafif düzeltme, normal</li>
    <li><code>dd_current_pct > 10</code>: Ciddi düşüş, dikkat</li>
    <li><code>dd_duration_days > 30</code>: Uzun düşüş trendi</li>
    <li><code>dd_recovery_days = null</code>: Hâlâ dip noktada, kurtarılamamış</li>
</ul>

<h3>VaR/CVaR Yorumlama</h3>
<ul>
    <li><code>var95_pct = 2</code>: Günde %95 olasılıkla en fazla %2 kaybedersin</li>
    <li><code>cvar95_pct = 5</code>: Kötü günlerde ortalama kayıp %5</li>
    <li><code>cvar99 > 2 × var99</code>: Kuyruk riski yüksek — aşırı kayıp potansiyeli</li>
</ul>

<h3>Dağılım Şekli</h3>
<ul>
    <li><code>skewness > 0</code>: Sağ çarpık — daha fazla pozitif sürpriz</li>
    <li><code>skewness < 0</code>: Sol çarpık — ani büyük kayıp riski daha yüksek</li>
    <li><code>kurtosis > 3</code>: Kalın kuyruk — normalden daha aşırı hareketler</li>
    <li><code>kurtosis < 0</code>: İnce kuyruk — aşırı hareketler beklenenden az</li>
</ul>

<!-- ═══ 8. Korelasyon ═══ -->
<h2>8. Korelasyon Matrisi</h2>
<p class="section-intro">Fonlar arası günlük getiri korelasyonu. Diversifikasyon değerlendirmesi için kritiktir.</p>

<table>
    <thead><tr>{$corr_header}</tr></thead>
    <tbody>{$corr_rows}</tbody>
</table>

<h3>Korelasyon Yorumlama</h3>
<ul>
    <li><code>r > 0.7</code> (kırmızı): Yüksek korelasyon — benzer hareket, diversifikasyon düşük</li>
    <li><code>0.3 < r < 0.7</code>: Orta korelasyon — kısmi diversifikasyon</li>
    <li><code>r < 0.3</code> (gri): Düşük korelasyon — iyi diversifikasyon potansiyeli</li>
    <li><code>r < 0</code>: Negatif korelasyon — biri düşerken diğeri yükselir (mükemmel hedge)</li>
</ul>

<!-- ═══ 9. Backtest ═══ -->
<h2>9. Backtest Sonuçları</h2>
<p class="section-intro">Alarm sinyallerinin geçmişteki başarısı. Sinyal tarihi → sonraki 5/10/20 gündeki getiri.</p>

<table>
    <thead>
        <tr><th>Fon</th><th>Sinyal Sayısı</th><th>Son Sinyal</th><th>Ort. 5G Getiri</th><th>Ort. 10G Getiri</th><th>Ort. 20G Getiri</th><th>Hit Rate (20G)</th><th>Yorum</th></tr>
    </thead>
    <tbody>{$bt_rows}</tbody>
</table>

<h3>Backtest Yorumlama</h3>
<ul>
    <li><code>hit_rate_20d > 60%</code>: Alarm sinyalleri geçmişte işe yaramış (düşüş gelmiş)</li>
    <li><code>avg_ret_20d < 0</code>: Sinyallerden sonra ortalama düşüş olmuş — alarm güvenilir</li>
    <li><code>signal_count < 3</code>: Yeterli sinyal yok, istatistiksel anlamlılık düşük</li>
    <li><strong>"Alarm etkili"</strong>: Sinyaller tutarlı şekilde düşüş öncesi verilmiş</li>
    <li><strong>"Yanlış pozitif olabilir"</strong>: Sinyallerden sonra fiyat düşmemiş, yükselmeye devam etmiş</li>
</ul>

<!-- ═══ 10. Karşılaştırma Kılavuzu ═══ -->
<h2>10. Karşılaştırma Kılavuzu</h2>

<h3>Nasıl Karşılaştırılır?</h3>
<div class="note">
    <strong> Tek metrik yeterli değil!</strong> Doğru karşılaştırma için birden fazla metrik kombinasyonu değerlendirilmelidir.
</div>

<h3>Karşılaştırma Matrisi</h3>
<table>
    <tr><th>Kriter</th><th>Bakılacak Alanlar</th><th>Nasıl Yorumlanır</th></tr>
    <tr><td><strong>En iyi getiri</strong></td><td>period_return_pct, annual_return_pct, real_return_pct</td><td>En yüksek reel getiri en iyi</td></tr>
    <tr><td><strong>En düşük risk</strong></td><td>max_drawdown_pct, annual_vol_pct, var95_pct, cvar95_pct</td><td>En düşük drawdown ve volatilite = en güvenli</td></tr>
    <tr><td><strong>En iyi risk/ getiri</strong></td><td>sharpe, sortino, omega, calmar</td><td>En yüksek oran = en verimli</td></tr>
    <tr><td><strong>En stabil</strong></td><td>win_rate_pct, neg_streak_days, kurtosis</td><td>Yüksek win rate, düşük streak, düşük kurtosis</td></tr>
    <tr><td><strong>En iyi trend</strong></td><td>mom_3m_pct, mom_6m_pct, dd_current_pct</td><td>Pozitif momentum, düşük drawdown</td></tr>
    <tr><td><strong>En güvenli akış</strong></td><td>flow_status, latest_flow_score, alert_ratio_pct</td><td>NORMAL durum, düşük skor</td></tr>
    <tr><td><strong>En iyi diversifikasyon</strong></td><td>correlation_matrix</td><td>Düşük korelasyonlu fonlar seç</td></tr>
    <tr><td><strong>Geçmiş alarm güvenilirliği</strong></td><td>backtest.hit_rate_20d, backtest.avg_ret_20d</td><td>>%60 hit rate, negatif ortalama getiri</td></tr>
</table>

<h3>Kombine Skor Örneği</h3>
<pre>// Basit kombine skor (ağırlıklı)
composite = (period_return × 0.35)
          + (sharpe × 1.50)
          − (max_drawdown × 0.40)
          − (flow_score × 0.08)

// En yüksek skor = en güçlü fon</pre>

<!-- ═══ 11. Fon Verileri ═══ -->
<h2>11. Fon Verileri (Güncel)</h2>
<p class="section-intro">Seçili {$fund_count} fonun güncel metrikleri.</p>

<table>
    <thead>
        <tr>
            <th>Fon</th><th>Tip</th><th>Dönem Get.</th><th>Yıllık Get.</th><th>Gerçek Get.</th>
            <th>Max DD</th><th>Sharpe</th><th>Omega</th><th>Durum</th><th>Skor</th>
        </tr>
    </thead>
    <tbody>{$fund_list_html}</tbody>
</table>

<!-- ═══ 12. Örnek Analiz ═══ -->
<h2>12. Örnek Analiz Metni</h2>
<p class="section-intro">Aşağıdaki format, bir AI agent'ın fon karşılaştırması yaparken kullanabileceği yapıdır.</p>

<pre>
<strong>[FON KODU] Değerlendirmesi</strong>

<strong>Genel Bakış:</strong> [Fon adı], [tip] kategorisinde [tarih] itibarıyla işlem görüyor.
Dönem getirisi [%] olup, yıllıklandırılmış getirisi [%]'dir.
Enflasyona göre gerçek getiri [%] seviyesindedir.

<strong>Getiri Profili:</strong>
- Son 3 ay: [%] momentum (pozitif/negatif trend)
- Son 6 ay: [%] momentum
- Kazanma oranı: [%] — günlük getirilerin [X]'i pozitif
- En iyi tek gün: [+%], En kötü tek gün: [-%]

<strong>Risk Profili:</strong>
- Maksimum drawdown: [%] — [düşük/orta/yüksek] risk
- Mevcut drawdown: [%] — [zirvede/düzeltmede/düşüşte]
- VaR95: [%] — günlük %5 olasılıkla en fazla kayıp
- CVa95: [%] — kötü günlerde ortalama kayıp
- Volatilite: [%] yıllık — [düşük/orta/yüksek] dalgalanma

<strong>Oran Analizi:</strong>
- Sharpe: [değer] — [iyi/kötü/mükemmel] risk/getiri dengesi
- Sortino: [değer] — aşağı yönlü risk için [iyi/kötü]
- Omega: [değer] — [kazanç/kayıp] ağırlıklı dağılım

<strong>Akış Durumu:</strong>
- Alarm skoru: [değer] — [NORMAL/İZLEME/UYARI/KIRMIZI ALARM/KRİTİK]
- Son net akış: [TL] ([%]) — [giriş/çıkış]
- Katılımcı değişimi: [%] — [yeni katılımcı girişi/çıkışı]
- Alert oranı: [%] — alarm günlerin sıklığı

<strong>Korelasyon:</strong>
- En yüksek korelasyonlu fon: [FON] (r=[değer])
- En düşük korelasyonlu fon: [FON] (r=[değer])
- Diversifikasyon potansiyeli: [yüksek/orta/düşük]

<strong>Backtest:</strong>
- Sinyal sayısı: [adet]
- Hit rate: [%] — alarm güvenilirliği [yüksek/düşük]

<strong>Sonuç:</strong> [Kısa değerlendirme — fonun güçlü ve zayıf yönleri, genel tavsiye]
</pre>

<p class="meta-info" style="margin-top:2rem; border-top:1px solid var(--border); padding-top:1rem;">
    TEFAS Agent API v1 · Oluşturulma: {$meta['generated_at']} · 
    <a href="/tefas/agent" style="color:var(--accent)">JSON API</a> · 
    <a href="/tefas/agent?format=html" style="color:var(--accent)">HTML Bilgi Bankası</a> · 
    <a href="/tefas/" style="color:var(--accent)">Ana Panel</a>
</p>

</div>
</body>
</html>
HTML;
}

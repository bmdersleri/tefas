<?php
declare(strict_types=1);

require_once __DIR__ . '/historical_information.php';
require_once __DIR__ . '/lib/stats.php';
require_once __DIR__ . '/lib/fund_analyzer.php';

/* ========= Form ve parametreler ========= */

$default_date = date('Y-m-d');

$fund_codes_input = $_GET['fund_codes'] ?? 'TLY,PHE,PBR,LTL,DFI,KZL,KUT,YZG,MJG';
$fund_type        = $_GET['fund_type']  ?? 'YAT';
$start_input      = $_GET['start_date'] ?? '2026-01-01';
$end_input        = $_GET['end_date']   ?? $default_date;

$alarm_watch_param    = request_int_param('alarm_watch',    55, 1, 98);
$alarm_red_param      = request_int_param('alarm_red',      70, $alarm_watch_param + 1, 99);
$alarm_critical_param = request_int_param('alarm_critical', 85, $alarm_red_param + 1, 100);
$flow_window_param    = request_int_param('flow_window',    14, 2, 90);
$div_window_param     = request_int_param('divergence_window', 14, 2, 90);
$score_view_param     = request_choice_param('score_view', 'ma7', ['raw', 'ma7', 'ma14']);

define('DIVERGENCE_WINDOW', $div_window_param);
define('FLOW_WINDOW', $flow_window_param);
define('ALARM_SCORE_WATCH', $alarm_watch_param);
define('ALARM_SCORE_RED', $alarm_red_param);
define('ALARM_SCORE_CRITICAL', $alarm_critical_param);

try {
    $start_date = new DateTime($start_input);
} catch (\Exception $e) {
    $start_date = new DateTime('2026-01-01');
}
try {
    $end_date = new DateTime($end_input);
} catch (\Exception $e) {
    $end_date = new DateTime('2026-03-01');
}

$fund_codes = array_filter(array_map('trim', preg_split('/[,\s]+/', $fund_codes_input)));

$alarm_thresholds = [
    'watch'    => ALARM_SCORE_WATCH,
    'red'      => ALARM_SCORE_RED,
    'critical' => ALARM_SCORE_CRITICAL,
];

$backtest_horizons = [5, 10, 20];

/* ========= Analiz ========= */

$analyzer = new FundAnalyzer();
$error_msg = null;

if (!empty($_GET) && !empty($fund_codes)) {
    foreach ($fund_codes as $code) {
        if ($code === '') continue;
        $analyzer->analyzeFund($code, $fund_type, $start_date, $end_date, $flow_window_param, $div_window_param, $alarm_thresholds);
        if ($analyzer->error_msg !== null) {
            $error_msg = $analyzer->error_msg;
        }
    }
}

$datasets = $analyzer->prepareDatasets();
$dashboard_summary = $analyzer->buildDashboardSummary();
$divergence_result = $analyzer->calculateDivergence(DIVERGENCE_WINDOW, $analyzer->all_dates);
$backtest_summary = $analyzer->calculateBacktest($backtest_horizons);

$sync_last_run_ts = $analyzer->sync_last_run_ts;
$chunk_warnings = $analyzer->chunk_warnings;
$metrics_by_fund = $analyzer->metrics_by_fund;

// Dataset değişkenlerini template için hazırla
$all_dates               = $analyzer->all_dates;
$price_datasets          = $datasets['price'];
$participant_datasets    = $datasets['participants'];
$size_datasets           = $datasets['size'];
$size_norm_datasets      = $datasets['size_norm'];
$avg_participant_size_datasets = $datasets['avg_participant'];
$avg_participant_size_norm_datasets = $datasets['avg_participant_norm'];
$net_flow_pct_datasets   = $datasets['net_flow_pct'];
$net_flow_cum_pct_datasets = $datasets['net_flow_cum_pct'];
$flow_score_datasets     = $datasets['flow_score'];
$flow_score_ma7_datasets = $datasets['flow_score_ma7'];
$flow_score_ma14_datasets = $datasets['flow_score_ma14'];
$divergence_datasets     = $divergence_result['datasets'];
$divergence_summary      = $divergence_result['summary'];
$correlation_matrix      = $datasets['correlation'];
$last_day_returns        = $datasets['last_day_returns'];

$hist_labels    = $datasets['histogram']['labels'];
$hist_datasets  = $datasets['histogram']['datasets'];
$hist_fund_code = $datasets['histogram']['fund_code'];
$hist_var95     = $datasets['histogram']['var95'];
$hist_var99     = $datasets['histogram']['var99'];

// Performans tablosu için en iyi/kötü değerleri hesapla
$metric_stats = [];
$metric_directions = [
    'period_return' => 'high', 'annual_return' => 'high', 'annual_vol' => 'low',
    'sharpe' => 'high', 'sortino' => 'high', 'calmar' => 'high',
    'max_drawdown' => 'low', 'mom_3m' => 'high', 'mom_6m' => 'high', 'mom_12m' => 'high',
    'beta' => 'low', 'dd_current' => 'low',
    'treynor' => 'high', 'information_ratio' => 'high', 'r_squared' => 'high',
    'omega' => 'high', 'real_return' => 'high',
    'skewness' => 'high', 'kurtosis' => 'low',
    'win_rate' => 'high', 'best_day' => 'high', 'worst_day' => 'high',
];

foreach ($metric_directions as $key => $direction) {
    $values = [];
    foreach ($metrics_by_fund as $m) {
        if (!array_key_exists($key, $m) || $m[$key] === null) continue;
        $values[] = $m[$key];
    }
    if (empty($values)) {
        $metric_stats[$key] = ['best' => null, 'worst' => null, 'direction' => $direction];
        continue;
    }
    $metric_stats[$key] = [
        'best'      => $direction === 'high' ? max($values) : min($values),
        'worst'     => $direction === 'high' ? min($values) : max($values),
        'direction' => $direction,
    ];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Fon Analiz Paneli: Getiri, Risk, Korelasyon</title>
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<a href="#main-content" class="skip-nav">İçeriğe atla</a>

<!-- Loading overlay -->
<div id="loading-overlay" role="alert" aria-live="polite">
    <div class="spinner" aria-hidden="true"></div>
    <div class="loading-text">TEFAS'tan veriler çekiliyor…</div>
</div>

<div class="container">
    <h1>
        📊 Fon Analiz Paneli
        <button type="button" class="theme-toggle" id="theme-toggle" aria-label="Tema değiştir" title="Tema değiştir (açık/koyu)">
            <span class="icon-sun" aria-hidden="true">☀️</span>
            <span class="icon-moon" aria-hidden="true">🌙</span>
        </button>
    </h1>

    <form method="get" id="main-form">
        <div class="form-row">
            <div>
                <label for="fund_codes">Fon kodları (virgülle ayır)</label>
                <input type="text" id="fund_codes" name="fund_codes"
                       value="<?php echo htmlspecialchars($fund_codes_input); ?>"
                       placeholder="Örn: TLY, DFI, BSM">
                <div class="info">TEFAS fon kısaltmalarını virgülle yazın.</div>
            </div>
            <div>
                <label for="fund_type">Fon tipi</label>
                <select id="fund_type" name="fund_type">
                    <option value="YAT" <?php if ($fund_type === 'YAT') echo 'selected'; ?>>YAT</option>
                    <option value="EMK" <?php if ($fund_type === 'EMK') echo 'selected'; ?>>EMK</option>
                    <option value="BYF" <?php if ($fund_type === 'BYF') echo 'selected'; ?>>BYF</option>
                </select>
            </div>
            <div>
                <label for="start_date">Başlangıç</label>
                <input type="date" id="start_date" name="start_date"
                       value="<?php echo htmlspecialchars($start_date->format('Y-m-d')); ?>">
            </div>
            <div>
                <label for="end_date">Bitiş</label>
                <input type="date" id="end_date" name="end_date"
                       value="<?php echo htmlspecialchars($end_date->format('Y-m-d')); ?>">
            </div>
            <div>
                <button type="submit" class="btn-submit">Güncelle</button>
            </div>
        </div>
        <div class="quick-dates">
            <span style="font-size:12px;color:var(--muted);align-self:center;">Hızlı:</span>
            <button type="button" class="quick-btn" data-days="7">1H</button>
            <button type="button" class="quick-btn" data-days="30">1A</button>
            <button type="button" class="quick-btn" data-days="90">3A</button>
            <button type="button" class="quick-btn" data-days="180">6A</button>
            <button type="button" class="quick-btn" data-days="365">1Y</button>
            <button type="button" class="quick-btn" data-days="730">2Y</button>
            <button type="button" class="quick-btn" data-ytd="1">YTD</button>
        </div>

        <details class="settings-panel">
            <summary>⚙️ V4.1 Analiz Ayarları</summary>
            <div class="settings-grid">
                <div>
                    <label for="alarm_watch">İzleme eşiği</label>
                    <input type="number" id="alarm_watch" name="alarm_watch" min="1" max="98" value="<?php echo (int)ALARM_SCORE_WATCH; ?>">
                    <div class="info">Bu skor ve üzeri izleme bölgesi kabul edilir.</div>
                </div>
                <div>
                    <label for="alarm_red">Kırmızı alarm eşiği</label>
                    <input type="number" id="alarm_red" name="alarm_red" min="2" max="99" value="<?php echo (int)ALARM_SCORE_RED; ?>">
                    <div class="info">Backtest sinyalleri bu eşik üzerinden başlar.</div>
                </div>
                <div>
                    <label for="alarm_critical">Kritik alarm eşiği</label>
                    <input type="number" id="alarm_critical" name="alarm_critical" min="3" max="100" value="<?php echo (int)ALARM_SCORE_CRITICAL; ?>">
                    <div class="info">Alarm tablosundaki KRİTİK rozetini belirler.</div>
                </div>
                <div>
                    <label for="flow_window">Net akış skor penceresi</label>
                    <input type="number" id="flow_window" name="flow_window" min="2" max="90" value="<?php echo (int)FLOW_WINDOW; ?>">
                    <div class="info">Skor üretiminde kullanılan rolling işlem günü sayısı.</div>
                </div>
                <div>
                    <label for="divergence_window">İraksaklık penceresi</label>
                    <input type="number" id="divergence_window" name="divergence_window" min="2" max="90" value="<?php echo (int)DIVERGENCE_WINDOW; ?>">
                    <div class="info">K↑ / P↓ iraksaklık grafiğinin rolling penceresi.</div>
                </div>
                <div>
                    <label for="score_view">Varsayılan skor grafiği</label>
                    <select id="score_view" name="score_view">
                        <option value="raw" <?php if ($score_view_param === 'raw') echo 'selected'; ?>>Günlük</option>
                        <option value="ma7" <?php if ($score_view_param === 'ma7') echo 'selected'; ?>>7G Ortalama</option>
                        <option value="ma14" <?php if ($score_view_param === 'ma14') echo 'selected'; ?>>14G Ortalama</option>
                    </select>
                    <div class="info">Sayfa açılışındaki alarm skoru görünümü.</div>
                </div>
            </div>
            <div class="settings-actions">
                <button type="submit" class="btn-submit">Ayarları Uygula</button>
                <button type="button" class="quick-btn" id="reset-settings-btn">Varsayılana Dön</button>
            </div>
        </details>
    </form>

    <main id="main-content" tabindex="-1">

    <?php if ($error_msg): ?>
    <div class="alert alert-error">
        <div class="alert-icon">🚫</div>
        <div>
            <strong>Kritik Hata</strong><br>
            <?php echo htmlspecialchars($error_msg); ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($chunk_warnings)): ?>
    <div class="alert alert-warn" id="warn-box">
        <div class="alert-icon">⚠️</div>
        <div style="flex:1">
            <strong>Veritabanında eksik veri var</strong> — kısmi sonuçlar gösteriliyor.
            <button class="warn-toggle" onclick="document.getElementById('warn-detail').classList.toggle('hidden')">
                Detay ▾
            </button>
            <div id="warn-detail" class="hidden" style="margin-top:8px">
                <?php foreach ($chunk_warnings as $fcode => $msgs): ?>
                    <div style="margin-bottom:6px">
                        <strong><?php echo htmlspecialchars($fcode); ?></strong>
                        <ul style="margin:2px 0 0 16px;padding:0">
                            <?php foreach ($msgs as $msg): ?>
                                <li><?php echo htmlspecialchars($msg); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
                <div class="retry-hint">
                    💡 Bu ekran sadece SQLite verisini gösterir.
                    Cron senkronizasyonu verileri peyderpey tamamlar.
                    <?php if (!empty($sync_last_run_ts)): ?>
                        Son senkron: <strong><?php echo date('Y-m-d H:i:s', (int)$sync_last_run_ts); ?></strong>.
                    <?php else: ?>
                        Henüz başarılı bir senkron kaydı yok.
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="meta">
        Fiyat grafiğinde, her fon için seçilen dönemin başlangıç günü <strong>100</strong> kabul edilerek normalize edilmiş değerler gösterilir.
        Risk metrikleri ve korelasyonlar günlük fiyat serisinden hesaplanmıştır.
    </div>

    <div id="quick-section-nav" class="quick-section-nav" aria-label="Bölüm hızlı menüsü"></div>

    <?php if (!empty($dashboard_summary)): ?>
    <div class="section-title">📌 Genel Durum Paneli</div>
    <div class="dashboard-grid">
        <div class="dash-card dash-red">
            <div class="dc-label">Kırmızı alarmdaki fon</div>
            <div class="dc-value"><?php echo (int)$dashboard_summary['red_count']; ?></div>
            <div class="dc-sub"><?php echo (int)$dashboard_summary['fund_count']; ?> fon içinde <?php echo ALARM_SCORE_RED; ?>+ skor</div>
        </div>
        <div class="dash-card dash-warn">
            <div class="dc-label">İzleme / uyarı</div>
            <div class="dc-value"><?php echo (int)$dashboard_summary['watch_count']; ?></div>
            <div class="dc-sub"><?php echo ALARM_SCORE_WATCH; ?>–<?php echo ALARM_SCORE_RED; ?> arası son alarm skoru</div>
        </div>
        <div class="dash-card dash-red">
            <div class="dc-label">En yüksek alarm skoru</div>
            <div class="dc-value"><?php echo htmlspecialchars($dashboard_summary['top_alarm_code'] ?? '—'); ?></div>
            <div class="dc-sub"><?php echo $dashboard_summary['top_alarm_score'] !== null ? number_format($dashboard_summary['top_alarm_score'], 1) . ' / 100' : '—'; ?></div>
        </div>
        <div class="dash-card dash-blue">
            <div class="dc-label">En yüksek net çıkış</div>
            <div class="dc-value"><?php echo htmlspecialchars($dashboard_summary['top_negative_flow_code'] ?? '—'); ?></div>
            <div class="dc-sub">
                <?php echo $dashboard_summary['top_negative_flow_pct'] !== null ? number_format($dashboard_summary['top_negative_flow_pct'], 2) . '%' : '—'; ?>
                <?php echo $dashboard_summary['top_negative_flow_tl'] !== null ? ' / ' . number_format($dashboard_summary['top_negative_flow_tl'], 0) . ' TL' : ''; ?>
            </div>
        </div>
        <div class="dash-card dash-green">
            <div class="dc-label">En yüksek dönem getirisi</div>
            <div class="dc-value"><?php echo htmlspecialchars($dashboard_summary['best_return_code'] ?? '—'); ?></div>
            <div class="dc-sub"><?php echo $dashboard_summary['best_return'] !== null ? number_format($dashboard_summary['best_return'], 2) . '%' : '—'; ?></div>
        </div>
        <div class="dash-card dash-green">
            <div class="dc-label">Bileşik güçlü fon adayı</div>
            <div class="dc-value"><?php echo htmlspecialchars($dashboard_summary['strongest_code'] ?? '—'); ?></div>
            <div class="dc-sub">Getiri + Sharpe − DD − alarm etkisi</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($last_day_returns)): ?>
    <div class="section-title">0) Son İşlem Günü — Fon Kartları</div>
    <?php
        $ldr_values = array_column($last_day_returns, 'return_pct');
        $ldr_max = $ldr_values ? max($ldr_values) : null;
        $ldr_min = $ldr_values ? min($ldr_values) : null;
        $ldr_abs_max = $ldr_values ? max(array_map('abs', $ldr_values)) : 1;
    ?>
    <div class="card-grid" id="fund-cards">
    <?php foreach ($last_day_returns as $code => $ldr):
        $r = $ldr['return_pct'];
        $m = $metrics_by_fund[$code] ?? [];
        $is_pos = $r >= 0;
        $color   = $is_pos ? '#22c55e' : '#ef4444';
        $bar_pct = $ldr_abs_max > 0 ? min(100, abs($r) / $ldr_abs_max * 100) : 0;
        $badge_cls = '';
        if ($ldr_max !== null && abs($r - $ldr_max) < 1e-9) $badge_cls = '🥇 ';
        elseif ($ldr_min !== null && abs($r - $ldr_min) < 1e-9) $badge_cls = '📉 ';
    ?>
    <div class="fund-card" style="border-top: 3px solid <?php echo $color; ?>">
        <label class="fc-check" title="Karşılaştırmaya ekle">
            <input type="checkbox" class="compare-cb" value="<?php echo htmlspecialchars($code); ?>">
            <span class="checkmark"></span>
        </label>
        <div class="fc-code"><?php echo htmlspecialchars($badge_cls . $code); ?></div>
        <div class="fc-date"><?php echo htmlspecialchars($ldr['date']); ?></div>
        <div class="fc-return-big" style="color:<?php echo $color; ?>">
            <?php echo ($is_pos ? '+' : '') . number_format($r, 4); ?>%
        </div>
        <div class="fc-bar-wrap">
            <div class="fc-bar-fill" style="width:<?php echo round($bar_pct, 1); ?>%;background:<?php echo $color; ?>"></div>
        </div>
        <div class="fc-row">
            <span>Son Fiyat</span>
            <span class="val"><?php echo number_format($ldr['last_price'], 4); ?></span>
        </div>
        <?php if (!empty($m)): ?>
        <div class="fc-metrics">
            <div class="fc-m-item">
                <span class="lbl">Dönem Getiri</span>
                <span class="mval <?php echo ($m['period_return'] >= 0 ? 'pos-return' : 'neg-return'); ?>">
                    <?php echo number_format($m['period_return'], 2); ?>%
                </span>
            </div>
            <div class="fc-m-item">
                <span class="lbl">Yıllık Vol.</span>
                <span class="mval"><?php echo $m['annual_vol'] !== null ? number_format($m['annual_vol']*100,2).'%' : '—'; ?></span>
            </div>
            <div class="fc-m-item">
                <span class="lbl">Sharpe</span>
                <span class="mval"><?php echo $m['sharpe'] !== null ? number_format($m['sharpe'],2) : '—'; ?></span>
            </div>
            <div class="fc-m-item">
                <span class="lbl">Max DD</span>
                <span class="mval neg-return"><?php echo number_format($m['max_drawdown'],2); ?>%</span>
            </div>
            <div class="fc-m-item">
                <span class="lbl">CVaR95</span>
                <span class="mval"><?php echo ($m['cvar95'] ?? null) !== null ? number_format($m['cvar95'],2).'%' : '—'; ?></span>
            </div>
            <div class="fc-m-item">
                <span class="lbl">Beta</span>
                <span class="mval"><?php echo ($m['beta'] ?? null) !== null ? number_format($m['beta'],2) : '—'; ?></span>
            </div>
            <div class="fc-m-item">
                <span class="lbl">Omega</span>
                <span class="mval"><?php echo ($m['omega'] ?? null) !== null ? number_format($m['omega'],2) : '—'; ?></span>
            </div>
            <div class="fc-m-item">
                <span class="lbl">Gerçek Getiri</span>
                <span class="mval <?php echo (($m['real_return'] ?? null) !== null && $m['real_return'] >= 0 ? 'pos-return' : 'neg-return'); ?>"><?php echo ($m['real_return'] ?? null) !== null ? number_format($m['real_return']*100,2).'%' : '—'; ?></span>
            </div>
            <div class="fc-m-item">
                <span class="lbl">Kazanma</span>
                <span class="mval"><?php echo ($m['win_rate'] ?? null) !== null ? number_format($m['win_rate'],1).'%' : '—'; ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>

    <div id="lastday-container">
        <canvas id="lastDayChart"></canvas>
    </div>
    <?php endif; ?>

    <!-- Karşılaştırma Paneli -->
    <div id="compare-bar" class="compare-bar hidden">
        <span class="compare-count"><span id="compare-count-num">0</span> fon seçildi</span>
        <button type="button" class="btn-submit" id="compare-btn">Seçili Fonları Karşılaştır</button>
        <button type="button" class="quick-btn" id="compare-clear-btn">Temizle</button>
    </div>
    <div id="compare-section" class="hidden">
        <div class="section-title">⚡ Fon Karşılaştırma</div>
        <div id="compare-chart-container">
            <canvas id="compareChart"></canvas>
        </div>
        <div class="table-wrap">
        <table id="compare-table">
            <thead><tr>
                <th>Metrik</th>
            </tr></thead>
            <tbody></tbody>
        </table>
        </div>
    </div>

    <!-- Yazdırma Butonu -->
    <div class="export-toolbar no-print" style="margin-top:16px;margin-bottom:4px;">
        <button type="button" class="export-btn" id="print-btn">🖨️ PDF / Yazdır</button>
        <button type="button" class="export-btn" id="export-all-csv-btn">📥 Tüm Tabloları CSV İndir</button>
    </div>

    <div class="section-title">1) Normalize Edilmiş Fon Fiyatları</div>
    <div id="chart-container">
        <canvas id="fundChart"></canvas>
    </div>

    <?php if (!empty($metrics_by_fund)): ?>
    <div class="section-title">2) Performans ve Risk Metrikleri</div>
    <div class="export-toolbar"><button type="button" class="export-btn" data-export-table="metrics-table" data-export-name="performans_risk_metrikleri.csv">Performans metriklerini CSV indir</button></div>
    <div class="table-wrap">
    <table id="metrics-table">
        <thead>
        <tr>
            <th class="sortable" data-col="0" data-type="str">Fon</th>
            <th class="sortable" data-col="1" data-type="str">Başlangıç</th>
            <th class="sortable" data-col="2" data-type="str">Bitiş</th>
            <th class="sortable" data-col="3" data-type="num">Başlangıç Fiyatı</th>
            <th class="sortable" data-col="4" data-type="num">Son Fiyat</th>
            <th class="sortable" data-col="5" data-type="num">Dönem Getirisi (%)</th>
            <th class="sortable" data-col="6" data-type="num">Yıllık Getiri (%)</th>
            <th class="sortable" data-col="7" data-type="num">Yıllık Vol. (%)</th>
            <th class="sortable" data-col="8" data-type="num">Sharpe</th>
            <th class="sortable" data-col="9" data-type="num">Sortino</th>
            <th class="sortable" data-col="10" data-type="num">Calmar</th>
            <th class="sortable" data-col="11" data-type="num">Max DD (%)</th>
            <th class="sortable" data-col="12" data-type="num">Min Fiyat</th>
            <th class="sortable" data-col="13" data-type="num">Max Fiyat</th>
            <th class="sortable" data-col="14" data-type="num">3M Getiri (%)</th>
            <th class="sortable" data-col="15" data-type="num">6M Getiri (%)</th>
            <th class="sortable" data-col="16" data-type="num">12M Getiri (%)</th>
            <th class="sortable" data-col="17" data-type="num">Beta</th>
            <th class="sortable" data-col="18" data-type="num">Treynor</th>
            <th class="sortable" data-col="19" data-type="num">Info Ratio</th>
            <th class="sortable" data-col="20" data-type="num">R² (%)</th>
            <th class="sortable" data-col="21" data-type="num">Omega</th>
            <th class="sortable" data-col="22" data-type="num">Gerçek Getiri (%)</th>
            <th class="sortable" data-col="23" data-type="num">Çarpıklık</th>
            <th class="sortable" data-col="24" data-type="num">Basıklık</th>
            <th class="sortable" data-col="25" data-type="num">Kazanma (%)</th>
            <th class="sortable" data-col="26" data-type="num">En İyi Gün (%)</th>
            <th class="sortable" data-col="27" data-type="num">En Kötü Gün (%)</th>
            <th class="sortable" data-col="28" data-type="num">Kazanma Serisi</th>
            <th class="sortable" data-col="29" data-type="num">Kaybetme Serisi</th>
            <th class="sortable" data-col="30" data-type="num">Mevcut DD (%)</th>
            <th class="sortable" data-col="31" data-type="num">DD Süresi (gün)</th>
            <th class="sortable" data-col="32" data-type="num">Kurtarma (gün)</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($metrics_by_fund as $code => $m): ?>
            <tr>
                <td><?php echo htmlspecialchars($code); ?></td>
                <td><?php echo htmlspecialchars($m['start_date']); ?></td>
                <td><?php echo htmlspecialchars($m['end_date']); ?></td>
                <td><?php echo number_format($m['base_price'], 4); ?></td>
                <td><?php echo number_format($m['last_price'], 4); ?></td>
                <td class="<?php echo metric_class('period_return', $m['period_return'], $metric_stats); ?>"><?php echo number_format($m['period_return'], 2); ?></td>
                <td class="<?php echo metric_class('annual_return', $m['annual_return'], $metric_stats); ?>"><?php echo $m['annual_return'] !== null ? number_format($m['annual_return'] * 100.0, 2) : '—'; ?></td>
                <td class="<?php echo metric_class('annual_vol', $m['annual_vol'], $metric_stats); ?>"><?php echo $m['annual_vol'] !== null ? number_format($m['annual_vol'] * 100.0, 2) : '—'; ?></td>
                <td class="<?php echo metric_class('sharpe', $m['sharpe'], $metric_stats); ?>"><?php echo $m['sharpe'] !== null ? number_format($m['sharpe'], 2) : '—'; ?></td>
                <td class="<?php echo metric_class('sortino', $m['sortino'], $metric_stats); ?>"><?php echo $m['sortino'] !== null ? number_format($m['sortino'], 2) : '—'; ?></td>
                <td class="<?php echo metric_class('calmar', $m['calmar'], $metric_stats); ?>"><?php echo $m['calmar'] !== null ? number_format($m['calmar'], 2) : '—'; ?></td>
                <td class="<?php echo metric_class('max_drawdown', $m['max_drawdown'], $metric_stats); ?>"><?php echo number_format($m['max_drawdown'], 2); ?></td>
                <td><?php echo number_format($m['min_price'], 4); ?></td>
                <td><?php echo number_format($m['max_price'], 4); ?></td>
                <td class="<?php echo metric_class('mom_3m', $m['mom_3m'], $metric_stats); ?>"><?php echo $m['mom_3m'] !== null ? number_format($m['mom_3m'] * 100.0, 2) : '—'; ?></td>
                <td class="<?php echo metric_class('mom_6m', $m['mom_6m'], $metric_stats); ?>"><?php echo $m['mom_6m'] !== null ? number_format($m['mom_6m'] * 100.0, 2) : '—'; ?></td>
                <td class="<?php echo metric_class('mom_12m', $m['mom_12m'], $metric_stats); ?>"><?php echo $m['mom_12m'] !== null ? number_format($m['mom_12m'] * 100.0, 2) : '—'; ?></td>
                <td class="<?php echo metric_class('beta', $m['beta'] ?? null, $metric_stats); ?>"><?php echo ($m['beta'] ?? null) !== null ? number_format($m['beta'], 2) : '—'; ?></td>
                <td class="<?php echo metric_class('treynor', $m['treynor'] ?? null, $metric_stats); ?>"><?php echo ($m['treynor'] ?? null) !== null ? number_format($m['treynor'], 2) : '—'; ?></td>
                <td class="<?php echo metric_class('information_ratio', $m['information_ratio'] ?? null, $metric_stats); ?>"><?php echo ($m['information_ratio'] ?? null) !== null ? number_format($m['information_ratio'], 2) : '—'; ?></td>
                <td class="<?php echo metric_class('r_squared', $m['r_squared'] ?? null, $metric_stats); ?>"><?php echo ($m['r_squared'] ?? null) !== null ? number_format($m['r_squared'], 1) : '—'; ?></td>
                <td class="<?php echo metric_class('omega', $m['omega'] ?? null, $metric_stats); ?>"><?php echo ($m['omega'] ?? null) !== null ? number_format($m['omega'], 2) : '—'; ?></td>
                <td class="<?php echo metric_class('real_return', $m['real_return'] ?? null, $metric_stats); ?>"><?php echo ($m['real_return'] ?? null) !== null ? number_format($m['real_return'] * 100.0, 2) : '—'; ?></td>
                <td class="<?php echo metric_class('skewness', $m['skewness'] ?? null, $metric_stats); ?>"><?php echo ($m['skewness'] ?? null) !== null ? number_format($m['skewness'], 2) : '—'; ?></td>
                <td class="<?php echo metric_class('kurtosis', $m['kurtosis'] ?? null, $metric_stats); ?>"><?php echo ($m['kurtosis'] ?? null) !== null ? number_format($m['kurtosis'], 2) : '—'; ?></td>
                <td class="<?php echo metric_class('win_rate', $m['win_rate'] ?? null, $metric_stats); ?>"><?php echo ($m['win_rate'] ?? null) !== null ? number_format($m['win_rate'], 1) : '—'; ?></td>
                <td class="<?php echo metric_class('best_day', $m['best_day'] ?? null, $metric_stats); ?>"><?php echo ($m['best_day'] ?? null) !== null ? number_format($m['best_day'], 2) : '—'; ?></td>
                <td class="<?php echo metric_class('worst_day', $m['worst_day'] ?? null, $metric_stats); ?>"><?php echo ($m['worst_day'] ?? null) !== null ? number_format($m['worst_day'], 2) : '—'; ?></td>
                <td><?php echo ($m['pos_streak'] ?? null) !== null ? $m['pos_streak'] : '—'; ?></td>
                <td><?php echo ($m['neg_streak'] ?? null) !== null ? $m['neg_streak'] : '—'; ?></td>
                <td class="<?php echo metric_class('dd_current', $m['dd_current'] ?? null, $metric_stats); ?>"><?php echo ($m['dd_current'] ?? null) !== null ? number_format($m['dd_current'], 2) : '—'; ?></td>
                <td><?php echo ($m['dd_duration'] ?? null) !== null ? $m['dd_duration'] : '—'; ?></td>
                <td><?php echo ($m['dd_recovery'] ?? null) !== null ? $m['dd_recovery'] : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($metrics_by_fund)): ?>
    <div class="section-title">2.a) Günlük VaR / CVaR Tablosu (Her Fon İçin)</div>
    <div class="meta">
        VaR değerleri, seçilen dönemdeki günlük getiriler üzerinden hesaplanmıştır.
        CVaR (Conditional VaR / Expected Shortfall), VaR eşiğinin altındaki ortalama kaybı gösterir.
    </div>
    <div class="export-toolbar"><button type="button" class="export-btn" data-export-table="var-table" data-export-name="gunluk_var_cvar_tablosu.csv">VaR/CVaR tablosunu CSV indir</button></div>
    <div class="table-wrap">
    <table id="var-table">
        <thead>
        <tr>
            <th class="sortable" data-col="0" data-type="str">Fon</th>
            <th class="sortable" data-col="1" data-type="num">%95 VaR</th>
            <th class="sortable" data-col="2" data-type="num">%99 VaR</th>
            <th class="sortable" data-col="3" data-type="num">%95 CVaR</th>
            <th class="sortable" data-col="4" data-type="num">%99 CVaR</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($metrics_by_fund as $code => $m): ?>
            <tr>
                <td><?php echo htmlspecialchars($code); ?></td>
                <td><?php echo $m['var95'] !== null ? number_format($m['var95'], 2) . '%' : '—'; ?></td>
                <td><?php echo $m['var99'] !== null ? number_format($m['var99'], 2) . '%' : '—'; ?></td>
                <td><?php echo $m['cvar95'] !== null ? number_format($m['cvar95'], 2) . '%' : '—'; ?></td>
                <td><?php echo $m['cvar99'] !== null ? number_format($m['cvar99'], 2) . '%' : '—'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($correlation_matrix)): ?>
    <div class="section-title">3) Fonlar Arası Korelasyon Matrisi (Günlük Getiriler)</div>
    <div class="matrix-note">1'e yakın değerler yüksek pozitif korelasyon, -1'e yakın değerler negatif korelasyon anlamına gelir.</div>
    <div class="table-wrap">
    <table>
        <thead>
        <tr>
            <th>Fon</th>
            <?php foreach (array_keys($correlation_matrix) as $c): ?>
                <th><?php echo htmlspecialchars($c); ?></th>
            <?php endforeach; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($correlation_matrix as $c1 => $row): ?>
            <tr>
                <td><?php echo htmlspecialchars($c1); ?></td>
                <?php foreach ($row as $c2 => $val): ?>
                    <td><?php
                        if ($val === null) { echo '—'; }
                        else {
                            $abs = abs($val);
                            $opacity = round(0.2 + $abs * 0.8, 2);
                            $bg = $val > 0 ? "rgba(34,197,94,$opacity)" : "rgba(239,68,68,$opacity)";
                            echo '<span style="display:inline-block;width:100%;color:'.($abs>.5?'#fff':'inherit').'">' . number_format($val, 2) . '</span>';
                        }
                    ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($hist_labels) && !empty($hist_datasets) && $hist_fund_code !== null): ?>
    <div class="section-title">4) Günlük Getiri Dağılımı ve VaR</div>
    <div class="meta">
        Histogramda tüm fonların günlük getirilerinin frekans dağılımı gösterilir.
        VaR değerleri yaklaşık olarak ilk fon (<?php echo htmlspecialchars($hist_fund_code); ?>) için hesaplanmıştır.
    </div>
    <div id="hist-container">
        <canvas id="histChart"></canvas>
    </div>
    <div class="meta">
        <?php if ($hist_var95 !== null): ?>
            %95 günlük VaR (~<?php echo htmlspecialchars($hist_fund_code); ?>) ≈
            <strong><?php echo number_format($hist_var95, 2); ?>%</strong>
        <?php endif; ?>
        <?php if ($hist_var99 !== null): ?>
            , %99 günlük VaR ≈ <strong><?php echo number_format($hist_var99, 2); ?>%</strong>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="section-title">5) Katılımcı Sayısı (KISISAYISI)</div>
    <div class="meta">
        Aşağıdaki grafikte, aynı tarih aralığında fonların katılımcı sayısındaki değişim gösterilir.
    </div>
    <div id="participants-container">
        <canvas id="participantsChart"></canvas>
    </div>

    <div class="section-title">6) Fon Büyüklüğü (PORTFOYBUYUKLUK)</div>
    <div class="meta">
        Fonun toplam portföy büyüklüğü (TL). Bu değer = <em>Tedavüldeki Pay Sayısı × Pay Fiyatı</em>
        olduğundan, hem yeni para girişi hem de pay fiyatındaki değişimden etkilenir.
    </div>
    <div class="chart-toolbar" data-toolbar="size">
        <button type="button" class="chart-toggle-btn active" data-mode="tl">TL</button>
        <button type="button" class="chart-toggle-btn" data-mode="norm">Normalize 100</button>
    </div>
    <div id="size-container">
        <canvas id="sizeChart"></canvas>
    </div>

    <?php if (!empty($divergence_summary)): ?>
    <div class="section-title">7) 🚨 Akıllı Para Iraksaklık Analizi</div>
    <div class="meta">
        <strong>Mantık:</strong> Bir fonun <em>katılımcı sayısı (KISISAYISI) artarken</em>
        <em>portföy büyüklüğü (PORTFOYBUYUKLUK) düşüyorsa</em>, yeni perakende yatırımcıların fona girdiğini
        ancak büyük (kurumsal) yatırımcıların pay azalttığını gösterebilir. Bu klasik bir
        <em>"akıllı para çıkış" (smart money exit)</em> sinyalidir.
        Destekleyici metrik olarak <em>Tedavüldeki Pay Sayısı (TEDPAYSAYISI)</em> da raporlanır;
        bu değer fiyat etkisinden bağımsız <strong>saf para giriş/çıkış</strong> göstergesidir.
        <br><br>
        <strong>Sinyaller:</strong>
        🟢 <span style="color:var(--green)">SAĞLIKLI</span> (K↑ P↑) ·
        🔴 <span style="color:var(--red)">ALARM</span> (K↑ P↓) ·
        🔵 <span style="color:var(--blue)">KONSOLİDASYON</span> (K↓ P↑) ·
        🟡 <span style="color:#f59e0b">ZAYIFLAMA</span> (K↓ P↓)
    </div>

    <?php
    $alarm_funds = [];
    foreach ($divergence_summary as $code => $s) {
        if ($s['signal'] === 'alert') $alarm_funds[] = $code;
    }
    ?>

    <?php if (!empty($alarm_funds)): ?>
        <div class="alert-box">
            🚨 <strong>Dikkat:</strong> <?php echo count($alarm_funds); ?> fon alarm durumunda
            (katılımcı artıyor, fon büyüklüğü düşüyor):
            <strong><?php echo htmlspecialchars(implode(', ', $alarm_funds)); ?></strong>.
            Aşağıdaki kart ve grafikten detayları inceleyebilirsiniz.
        </div>
    <?php else: ?>
        <div class="alert-box no-alarm">
            ✅ <strong>Alarm yok:</strong> Seçilen dönemde hiçbir fonda
            "katılımcı artıyor – büyüklük azalıyor" iraksaması tespit edilmedi.
        </div>
    <?php endif; ?>

    <div class="signal-grid">
    <?php
    $signal_labels = [
        'healthy'       => '🟢 SAĞLIKLI',
        'alert'         => '🔴 ALARM',
        'consolidating' => '🔵 KONSOLİDASYON',
        'weakening'     => '🟡 ZAYIFLAMA',
        'neutral'       => '⚪ BELİRSİZ',
    ];
    $signal_hints = [
        'healthy'       => 'Yeni katılımcı + büyüyen portföy → uyumlu büyüme.',
        'alert'         => 'Yeni katılımcı geliyor, ama para çıkıyor. Büyük yatırımcı satışı olabilir.',
        'consolidating' => 'Katılımcı azalıyor ama portföy büyüyor → büyük yatırımcı girişi.',
        'weakening'     => 'Hem katılımcı hem büyüklük düşüyor → genel ilgi kaybı.',
        'neutral'       => 'Veri yetersiz veya değişim eşik altında.',
    ];
    ?>
    <?php foreach ($divergence_summary as $code => $s): ?>
        <?php
            $kc  = $s['k_chg']; $pc = $s['p_chg']; $uc = $s['u_chg']; $ppc = $s['pp_chg'];
            $k_cls = ($kc !== null && $kc >= 0) ? 'pos-return' : 'neg-return';
            $p_cls = ($pc !== null && $pc >= 0) ? 'pos-return' : 'neg-return';
            $u_cls = ($uc !== null && $uc >= 0) ? 'pos-return' : 'neg-return';
        ?>
        <div class="signal-card signal-<?php echo $s['signal']; ?>">
            <div class="sc-code"><?php echo htmlspecialchars($code); ?></div>
            <div class="sc-status"><?php echo $signal_labels[$s['signal']]; ?></div>
            <div class="sc-row">
                <span>Katılımcı Δ</span>
                <strong class="<?php echo $k_cls; ?>">
                    <?php echo $kc !== null ? (($kc >= 0 ? '+' : '') . number_format($kc, 2) . '%') : '—'; ?>
                </strong>
            </div>
            <div class="sc-row">
                <span>Büyüklük Δ</span>
                <strong class="<?php echo $p_cls; ?>">
                    <?php echo $pc !== null ? (($pc >= 0 ? '+' : '') . number_format($pc, 2) . '%') : '—'; ?>
                </strong>
            </div>
            <?php if ($uc !== null): ?>
            <div class="sc-row">
                <span>Pay Sayısı Δ</span>
                <strong class="<?php echo $u_cls; ?>">
                    <?php echo ($uc >= 0 ? '+' : '') . number_format($uc, 2) . '%'; ?>
                </strong>
            </div>
            <?php endif; ?>
            <?php if ($ppc !== null): ?>
            <div class="sc-row">
                <span>Pay Başı Değer Δ</span>
                <strong>
                    <?php echo ($ppc >= 0 ? '+' : '') . number_format($ppc, 2) . '%'; ?>
                </strong>
            </div>
            <?php endif; ?>
            <div class="sc-hint"><?php echo $signal_hints[$s['signal']]; ?></div>
        </div>
    <?php endforeach; ?>
    </div>

    <div class="meta" style="margin-top: 16px;">
        Aşağıdaki grafikte her fon için <strong>rolling <?php echo DIVERGENCE_WINDOW; ?> günlük log iraksaklık skoru</strong>
        gösterilir: <em>ln(K<sub>now</sub>/K<sub>prev</sub>) − ln(P<sub>now</sub>/P<sub>prev</sub>)</em>.
        Bu boyutsuz skor, K ve P büyüme oranlarının logaritmik farkıdır; çok büyük yüzdesel
        değişimleri makul aralığa sıkıştırır.
        İlk gün referans (0) kabul edilir; ilk <?php echo DIVERGENCE_WINDOW; ?> günde pencere
        adaptiftir, sonrasında tam <?php echo DIVERGENCE_WINDOW; ?> günlük rolling pencere kullanılır.
        <br>
        <strong style="color:var(--red)">Pozitif (kırmızı bant)</strong> = ALARM yönü
        (K, P'den hızlı büyüyor / küçülüyor).
        <strong style="color:var(--green)">Negatif</strong> = sağlıklı yön.
        <em>Ölçek:</em> |skor| ≈ 0.69 → ~2× hız farkı; ≈ 1.10 → ~3×; ≈ 1.61 → ~5×; ≈ 2.30 → ~10×.
    </div>
    <div id="divergence-container">
        <canvas id="divergenceChart"></canvas>
    </div>
    <?php endif; ?>

    <?php if (!empty($net_flow_pct_datasets)): ?>
    <div class="section-title">8) Tahmini Net Para Akışı</div>
    <div class="meta">
        Fiyat etkisi ayrıştırılmış yaklaşık para giriş/çıkışını gösterir.
        İlk grafik günlük akışı, ikinci grafik dönem içindeki kümülatif akışı gösterir.
        <strong>Net Akış(t) = Portföy Büyüklüğü(t) − Portföy Büyüklüğü(t−1) × Fiyat(t)/Fiyat(t−1)</strong>.
    </div>
    <div id="netflow-container">
        <canvas id="netFlowChart"></canvas>
    </div>
    <div id="netflow-cum-container">
        <canvas id="netFlowCumChart"></canvas>
    </div>
    <?php endif; ?>

    <?php if (!empty($avg_participant_size_datasets)): ?>
    <div class="section-title">9) Katılımcı Başına Ortalama Fon Büyüklüğü</div>
    <div class="meta">
        <strong>PORTFOYBUYUKLUK / KISISAYISI</strong> oranıdır. Katılımcı sayısı artarken bu değerin düşmesi,
        küçük yatırımcı girişiyle birlikte ortalama yatırımcı büyüklüğünün zayıfladığını gösterebilir.
    </div>
    <div class="chart-toolbar" data-toolbar="avgparticipant">
        <button type="button" class="chart-toggle-btn active" data-mode="tl">TL</button>
        <button type="button" class="chart-toggle-btn" data-mode="norm">Normalize 100</button>
    </div>
    <div id="avgparticipant-container">
        <canvas id="avgParticipantChart"></canvas>
    </div>
    <?php endif; ?>

    <?php if (!empty($flow_score_datasets)): ?>
    <div class="section-title">10) Akıllı Para Alarm Skoru (0–100)</div>
    <div class="meta">
        Skor; katılımcı değişimi, tahmini net para akışı, tedavüldeki pay sayısı ve katılımcı başına büyüklük değişimini birlikte değerlendirir.
        <strong><?php echo ALARM_SCORE_WATCH; ?>+</strong> izleme, <strong><?php echo ALARM_SCORE_RED; ?>+</strong> kırmızı alarm bölgesidir.
    </div>
    <div class="chart-toolbar" data-toolbar="flowscore">
        <button type="button" class="chart-toggle-btn <?php echo $score_view_param === 'raw' ? 'active' : ''; ?>" data-mode="raw">Günlük</button>
        <button type="button" class="chart-toggle-btn <?php echo $score_view_param === 'ma7' ? 'active' : ''; ?>" data-mode="ma7">7G Ortalama</button>
        <button type="button" class="chart-toggle-btn <?php echo $score_view_param === 'ma14' ? 'active' : ''; ?>" data-mode="ma14">14G Ortalama</button>
    </div>
    <div id="flowscore-container">
        <canvas id="flowScoreChart"></canvas>
    </div>

    <div class="section-title">10.a) Net Akış ve Alarm Özeti</div>
    <div class="export-toolbar"><button type="button" class="export-btn" data-export-table="flow-table" data-export-name="net_akis_alarm_ozeti.csv">Alarm özetini CSV indir</button></div>
    <div class="table-wrap">
    <table id="flow-table">
        <thead>
        <tr>
            <th class="sortable" data-col="0" data-type="str">Fon</th>
            <th class="sortable" data-col="1" data-type="str">Durum</th>
            <th class="sortable" data-col="2" data-type="num">Son Skor</th>
            <th class="sortable" data-col="3" data-type="num">Maks. Skor</th>
            <th class="sortable" data-col="4" data-type="num">Son Net Akış (%)</th>
            <th class="sortable" data-col="5" data-type="num">Son Net Akış (TL)</th>
            <th class="sortable" data-col="6" data-type="num">Toplam Net Akış (TL)</th>
            <th class="sortable" data-col="7" data-type="num">Negatif Akış Günü</th>
            <th class="sortable" data-col="8" data-type="num">Alarm Günü</th>
            <th class="sortable" data-col="9" data-type="num">Alarm Oranı (%)</th>
            <th class="sortable" data-col="10" data-type="num">Katılımcı Başına Son TL</th>
            <th class="sortable" data-col="11" data-type="num">Katılımcı Başına Δ (%)</th>
            <th class="sortable" data-col="12" data-type="str">Son Akış Tarihi</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($metrics_by_fund as $code => $m): ?>
            <?php
                $status = $m['flow_status'] ?? 'NORMAL';
                $status_cls = match ($status) {
                    'KRİTİK' => 'status-critical',
                    'KIRMIZI ALARM' => 'status-red',
                    'UYARI' => 'status-warn',
                    'İZLEME' => 'status-watch',
                    default => 'status-normal',
                };
                $nf_cls = (($m['latest_net_flow_pct'] ?? null) !== null && $m['latest_net_flow_pct'] >= 0) ? 'pos-return' : 'neg-return';
                $aps_cls = (($m['avg_participant_size_chg'] ?? null) !== null && $m['avg_participant_size_chg'] >= 0) ? 'pos-return' : 'neg-return';
            ?>
            <tr>
                <td><?php echo htmlspecialchars($code); ?></td>
                <td><span class="status-pill <?php echo $status_cls; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                <td><?php echo $m['latest_flow_score'] !== null ? number_format($m['latest_flow_score'], 1) : '—'; ?></td>
                <td><?php echo $m['max_flow_score'] !== null ? number_format($m['max_flow_score'], 1) : '—'; ?></td>
                <td class="<?php echo $nf_cls; ?>"><?php echo $m['latest_net_flow_pct'] !== null ? (($m['latest_net_flow_pct'] >= 0 ? '+' : '') . number_format($m['latest_net_flow_pct'], 2) . '%') : '—'; ?></td>
                <td class="<?php echo $nf_cls; ?>"><?php echo $m['latest_net_flow'] !== null ? number_format($m['latest_net_flow'], 0) : '—'; ?></td>
                <td><?php echo $m['sum_net_flow'] !== null ? number_format($m['sum_net_flow'], 0) : '—'; ?></td>
                <td><?php echo (int)($m['negative_flow_days'] ?? 0); ?></td>
                <td><?php echo (int)($m['alert_days'] ?? 0); ?></td>
                <td><?php echo $m['alert_ratio'] !== null ? number_format($m['alert_ratio'], 2) : '—'; ?></td>
                <td><?php echo $m['last_avg_participant_size'] !== null ? number_format($m['last_avg_participant_size'], 0) : '—'; ?></td>
                <td class="<?php echo $aps_cls; ?>"><?php echo $m['avg_participant_size_chg'] !== null ? (($m['avg_participant_size_chg'] >= 0 ? '+' : '') . number_format($m['avg_participant_size_chg'], 2) . '%') : '—'; ?></td>
                <td><?php echo htmlspecialchars($m['last_flow_date'] ?? '—'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($backtest_summary)): ?>
    <div class="section-title">11) Alarm Sinyali Backtest Özeti</div>
    <div class="meta">
        Bu tablo, <strong><?php echo ALARM_SCORE_RED; ?>+</strong> kırmızı alarm skorunun başladığı günleri tekil sinyal kabul eder.
        Ardışık alarm günleri tek sinyal kümesi sayılır. Getiriler alarm gününden sonraki yaklaşık işlem günü ufukları için hesaplanır.
    </div>
    <div class="export-toolbar"><button type="button" class="export-btn" data-export-table="backtest-table" data-export-name="alarm_backtest_ozeti.csv">Backtest tablosunu CSV indir</button></div>
    <div class="table-wrap">
    <table id="backtest-table">
        <thead>
        <tr>
            <th class="sortable" data-col="0" data-type="str">Fon</th>
            <th class="sortable" data-col="1" data-type="num">Sinyal Sayısı</th>
            <th class="sortable" data-col="2" data-type="str">Son Sinyal</th>
            <th class="sortable" data-col="3" data-type="num">Ort. 5G Getiri (%)</th>
            <th class="sortable" data-col="4" data-type="num">Ort. 10G Getiri (%)</th>
            <th class="sortable" data-col="5" data-type="num">Ort. 20G Getiri (%)</th>
            <th class="sortable" data-col="6" data-type="num">20G İsabet (%)</th>
            <th class="sortable" data-col="7" data-type="num">Ort. 20G Max Olumsuz (%)</th>
            <th class="sortable" data-col="8" data-type="str">Yorum</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($backtest_summary as $code => $bt): ?>
            <?php
                $r20_cls = (($bt['avg_ret_20d'] ?? null) !== null && $bt['avg_ret_20d'] >= 0) ? 'pos-return' : 'neg-return';
                $mdd_cls = (($bt['avg_mdd_20d'] ?? null) !== null && $bt['avg_mdd_20d'] >= 0) ? 'pos-return' : 'neg-return';
            ?>
            <tr>
                <td><?php echo htmlspecialchars($code); ?></td>
                <td><?php echo (int)$bt['signal_count']; ?></td>
                <td><?php echo htmlspecialchars($bt['last_signal_date'] ?? '—'); ?></td>
                <td><?php echo $bt['avg_ret_5d'] !== null ? number_format($bt['avg_ret_5d'], 2) : '—'; ?></td>
                <td><?php echo $bt['avg_ret_10d'] !== null ? number_format($bt['avg_ret_10d'], 2) : '—'; ?></td>
                <td class="<?php echo $r20_cls; ?>"><?php echo $bt['avg_ret_20d'] !== null ? number_format($bt['avg_ret_20d'], 2) : '—'; ?></td>
                <td><?php echo $bt['hit_rate_20d'] !== null ? number_format($bt['hit_rate_20d'], 1) : '—'; ?></td>
                <td class="<?php echo $mdd_cls; ?>"><?php echo $bt['avg_mdd_20d'] !== null ? number_format($bt['avg_mdd_20d'], 2) : '—'; ?></td>
                <td><?php echo htmlspecialchars($bt['comment'] ?? '—'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
    </main>
</div>

<script>
const labels               = <?php echo json_encode($all_dates ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const priceDatasets        = <?php echo json_encode($price_datasets ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const participantsDatasets = <?php echo json_encode($participant_datasets ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const sizeDatasets         = <?php echo json_encode($size_datasets ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const sizeNormDatasets     = <?php echo json_encode($size_norm_datasets ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const avgParticipantSizeDatasets = <?php echo json_encode($avg_participant_size_datasets ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const avgParticipantSizeNormDatasets = <?php echo json_encode($avg_participant_size_norm_datasets ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const netFlowPctDatasets   = <?php echo json_encode($net_flow_pct_datasets ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const netFlowCumPctDatasets = <?php echo json_encode($net_flow_cum_pct_datasets ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const flowScoreDatasets    = <?php echo json_encode($flow_score_datasets ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const flowScoreMa7Datasets = <?php echo json_encode($flow_score_ma7_datasets ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const flowScoreMa14Datasets = <?php echo json_encode($flow_score_ma14_datasets ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const divergenceDatasets   = <?php echo json_encode($divergence_datasets ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const histLabels           = <?php echo json_encode($hist_labels ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const histDatasets         = <?php echo json_encode($hist_datasets ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const histFundCode         = <?php echo json_encode($hist_fund_code ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const lastDayData = <?php
    $ld_out = [];
    foreach ($last_day_returns as $code => $ldr) {
        $ld_out[] = ['code' => $code, 'return_pct' => round($ldr['return_pct'], 4)];
    }
    echo json_encode($ld_out, JSON_UNESCAPED_UNICODE);
?>;
const alarmWatchThreshold    = <?php echo (int)ALARM_SCORE_WATCH; ?>;
const alarmRedThreshold      = <?php echo (int)ALARM_SCORE_RED; ?>;
const alarmCriticalThreshold = <?php echo (int)ALARM_SCORE_CRITICAL; ?>;
const defaultScoreView       = <?php echo json_encode($score_view_param, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const metricsByFund          = <?php echo json_encode($metrics_by_fund ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const lastDayReturnsFull     = <?php echo json_encode($last_day_returns ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="assets/app.js"></script>
</body>
</html>

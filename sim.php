<?php
// sim.php
require_once 'historical_information.php';

$results = [];
$fund_prices_json = [];
$error_message = '';
$sync_warnings = [];
$sync_last_run_ts = tefas_latest_sync_timestamp();

// Varsayılan değerler
$default_fund_type = $_POST['fund_type'] ?? 'YAT';
$auto_usd_rate = 45.0; // TCMB'den çekilemezse kullanılacak yedek oran
$auto_inf_rate = 65.0; // Manuel girilecek varsayılan enflasyon

/* ===== TCMB OTOMATİK DOLAR KURU ÇEKİCİ ===== */
function get_tcmb_usd($date_str) {
    $date = new DateTime($date_str);
    
    // Hafta sonu/Tatil durumunda XML olmadığı için en fazla 5 gün geriye tarama yap
    for ($i = 0; $i < 5; $i++) {
        $yearMonth = $date->format('Ym');
        $dayMonthYear = $date->format('dmY');
        
        // TCMB URL Formatı
        $url = "https://www.tcmb.gov.tr/kurlar/{$yearMonth}/{$dayMonthYear}.xml";
        
        $headers = @get_headers($url);
        if($headers && strpos($headers[0], '200') !== false) {
            $xml_content = @file_get_contents($url);
            if ($xml_content) {
                $xml = simplexml_load_string($xml_content);
                foreach($xml->Currency as $currency) {
                    if($currency['CurrencyCode'] == 'USD') {
                        return (float)$currency->ForexBuying;
                    }
                }
            }
        }
        $date->modify('-1 day'); // Veri yoksa 1 gün geriye git
    }
    return null; 
}
/* ============================================ */

// Form gönderildiğinde çalışacak mantık
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start_str = $_POST['start_date'] ?? '';
    $end_str   = $_POST['end_date'] ?? '';
    $funds_str = $_POST['funds'] ?? '';
    $fund_type = $_POST['fund_type'] ?? 'YAT';

    if (!empty($start_str) && !empty($end_str) && !empty($funds_str)) {
        try {
            $start_date = new DateTime($start_str);
            $end_date   = new DateTime($end_str);
            
            // --- OTOMATİK DÖVİZ ARTIŞI HESAPLAMA ---
            $usd_start = get_tcmb_usd($start_str);
            $usd_end = get_tcmb_usd($end_str);
            
            if ($usd_start && $usd_end && $usd_start > 0) {
                // (Bitiş - Başlangıç) / Başlangıç * 100
                $auto_usd_rate = (($usd_end - $usd_start) / $usd_start) * 100;
            }
            // ----------------------------------------
            
            // Fon kodlarını temizle
            $fund_codes = array_map('trim', explode(',', $funds_str));
            $fund_codes = array_filter($fund_codes);

            foreach ($fund_codes as $code) {
                $code = strtoupper($code);
                
                $historical_data = historical_information_db_only_compat('BindHistoryInfo', $fund_type, $start_date, $end_date, $code);

                if (!empty($historical_data)) {
                    // 1. KRONOLOJİK SIRALAMA
                    usort($historical_data, function($a, $b) {
                        return strcmp($a['TARIH'], $b['TARIH']);
                    });

                    $first_row = reset($historical_data); 
                    $last_row  = end($historical_data); 
                    
                    $first_price = isset($first_row['FIYAT']) ? (float)$first_row['FIYAT'] : 1; 
                    $last_price  = isset($last_row['FIYAT']) ? (float)$last_row['FIYAT'] : 1;

                    // 2. ORTAK TARİH İÇİN GÜNLÜK DİZİ (Anahtarlar Tarih Olmalı)
                    $daily_prices = [];
                    foreach ($historical_data as $row) {
                        if (isset($row['FIYAT']) && isset($row['TARIH'])) {
                            $daily_prices[$row['TARIH']] = (float)$row['FIYAT'];
                        }
                    }
                    
                    $fund_prices_json[$code] = $daily_prices;

                    $missing_dates = tefas_missing_weekday_dates($code, $start_date, $end_date);
                    if (!empty($missing_dates)) {
                        $sync_warnings[] = sprintf(
                            '%s için eksik iş günü verisi: %d gün (%s ... %s)',
                            $code,
                            count($missing_dates),
                            reset($missing_dates),
                            end($missing_dates)
                        );
                    }

                    $sync_state = tefas_sync_state($code, $fund_type);
                    if ($sync_state && !empty($sync_state['last_error'])) {
                        $sync_warnings[] = $code . ' son senkron hata: ' . (string)$sync_state['last_error'];
                    }

                    // 3. DOĞRU GETİRİ HESABI
                    if ($first_price > 0) {
                        $return_pct = (($last_price - $first_price) / $first_price) * 100;
                        $results[$code] = $return_pct;
                    }
                } else {
                    $sync_warnings[] = $code . ' için bu tarih aralığında veritabanında kayıt yok.';
                }
            }
        } catch (Exception $e) {
            $error_message = "Veri okuma hatası: " . $e->getMessage();
        }
    } else {
        $error_message = "Lütfen tüm alanları doldurun.";
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Akıllı Portföy Simülasyonu & Kıyaslama</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #111827; color: #f3f4f6; font-family: sans-serif; padding: 2rem; }
        .container { max-width: 1100px; margin: 0 auto; background-color: #1f2937; padding: 2rem; border-radius: 0.5rem; border: 1px solid #374151; }
        .form-group { margin-bottom: 1rem; }
        label { display: block; margin-bottom: 0.5rem; color: #d1d5db; font-size: 0.9rem;}
        input[type="text"], input[type="date"], input[type="number"], select { width: 100%; padding: 0.75rem; border-radius: 0.25rem; border: 1px solid #4b5563; background-color: #374151; color: white; box-sizing: border-box; }
        button.btn-primary { background-color: #3b82f6; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 0.25rem; cursor: pointer; font-weight: bold; width: 100%; transition: background 0.3s; }
        button.btn-primary:hover { background-color: #2563eb; }
        
        button.btn-smart { background: linear-gradient(135deg, #8b5cf6, #ec4899); color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 0.5rem; cursor: pointer; font-weight: bold; width: 100%; margin-bottom: 1.5rem; transition: transform 0.2s, shadow 0.2s; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        button.btn-smart:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }
        
        .result-grid { display: flex; flex-wrap: wrap; gap: 2rem; margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #374151; }
        .sliders-container { flex: 1; min-width: 300px; }
        .chart-container { flex: 1; min-width: 300px; display: flex; flex-direction: column; align-items: center; justify-content: center;}
        
        /* Kıyaslama Bölümü Stilleri */
        .benchmark-section { margin-top: 2rem; padding-top: 2rem; border-top: 1px solid #374151; display: flex; flex-wrap: wrap; gap: 2rem; }
        .benchmark-inputs { flex: 1; min-width: 300px; background: #111827; padding: 1.5rem; border-radius: 0.5rem; border: 1px solid #4b5563; }
        .benchmark-chart { flex: 2; min-width: 400px; height: 300px; }
        .stat-box { background: #374151; padding: 1rem; border-radius: 0.5rem; text-align: center; margin-bottom: 1rem; }
        
        .nav-link { color: #60a5fa; text-decoration: none; margin-bottom: 1rem; display: inline-block; }
    </style>
</head>
<body>

<div class="container">
    <a href="index.php" class="nav-link">← Ana Panele Dön</a>
    <h2>Dinamik Portföy Simülasyonu & Reel Getiri Kıyaslaması</h2>
    <p style="color:#9ca3af; margin-bottom: 2rem;">Tarih aralığını seçin, akıllı optimizasyon ile ideal ağırlıkları bulun ve enflasyon/döviz karşısındaki reel alım gücünüzü analiz edin.</p>

    <?php if ($error_message): ?>
        <div style="background: #7f1d1d; color: white; padding: 1rem; border-radius: 0.25rem; margin-bottom: 1rem;">
            <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($sync_warnings)): ?>
        <div style="background: #78350f; color: #fef3c7; padding: 1rem; border-radius: 0.25rem; margin-bottom: 1rem;">
            <strong>Veritabanında eksik veri var</strong><br>
            <?php foreach ($sync_warnings as $warn): ?>
                <div style="margin-top:0.35rem;">• <?= htmlspecialchars($warn) ?></div>
            <?php endforeach; ?>
            <div style="margin-top:0.5rem; font-size:0.85rem;">
                Bu ekran yalnızca SQLite verisini kullanır.
                <?php if (!empty($sync_last_run_ts)): ?>
                    Son senkron: <?= htmlspecialchars(date('Y-m-d H:i:s', (int)$sync_last_run_ts)) ?>
                <?php else: ?>
                    Henüz başarılı senkron kaydı yok.
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
            <div class="form-group" style="flex: 1; min-width: 150px;">
                <label>Fon Türü</label>
                <select name="fund_type">
                    <option value="YAT" <?= ($default_fund_type === 'YAT') ? 'selected' : '' ?>>Yatırım Fonu (YAT)</option>
                    <option value="EMK" <?= ($default_fund_type === 'EMK') ? 'selected' : '' ?>>Emeklilik Fonu (EMK)</option>
                    <option value="BYF" <?= ($default_fund_type === 'BYF') ? 'selected' : '' ?>>Borsa Yatırım Fonu (BYF)</option>
                </select>
            </div>
            <div class="form-group" style="flex: 1; min-width: 150px;">
                <label>Başlangıç Tarihi</label>
                <input type="date" name="start_date" value="<?= htmlspecialchars($_POST['start_date'] ?? date('Y-m-d', strtotime('-1 year'))) ?>" required>
            </div>
            <div class="form-group" style="flex: 1; min-width: 150px;">
                <label>Bitiş Tarihi</label>
                <input type="date" name="end_date" value="<?= htmlspecialchars($_POST['end_date'] ?? date('Y-m-d')) ?>" required>
            </div>
            <div class="form-group" style="flex: 2; min-width: 250px;">
                <label>Fon Kodları (Örn: MAC, TCD veya CFA, AVR)</label>
                <input type="text" name="funds" value="<?= htmlspecialchars($_POST['funds'] ?? 'MAC, TCD, YAS') ?>" required>
            </div>
        </div>
        <button type="submit" class="btn-primary">Verileri Çek ve Simülasyonu Başlat</button>
    </form>

    <?php if (!empty($results)): ?>
    <div class="result-grid">
        <div class="sliders-container" id="sliderGroup">
            <button id="optimizeBtn" class="btn-smart">✨ Akıllı Optimizasyon (Max Sharpe)</button>
            <h3 style="margin-bottom: 1.5rem; color: #e5e7eb;">Ağırlık Belirle</h3>
            <?php 
            $default_weight = floor(100 / count($results)); 
            foreach ($results as $code => $return): 
            ?>
                <div style="margin-bottom: 1.5rem;">
                    <label style="display: flex; justify-content: space-between;">
                        <span><?= htmlspecialchars($code) ?> (Nominal Getiri: %<?= number_format($return, 2, ',', '.') ?>)</span>
                        <span id="label-<?= htmlspecialchars($code) ?>" style="font-weight: bold; color: #60a5fa;">%<?= $default_weight ?></span>
                    </label>
                    <input type="range" class="dynamic-slider" 
                           data-code="<?= htmlspecialchars($code) ?>" 
                           data-return="<?= (float)$return ?>" 
                           min="0" max="100" value="<?= $default_weight ?>" style="width: 100%;">
                </div>
            <?php endforeach; ?>
        </div>

        <div class="chart-container">
            <div style="width: 280px; height: 280px;">
                <canvas id="simChart"></canvas>
            </div>
            <div style="text-align: center; margin-top: 1.5rem;">
                <div id="totalSimReturn" style="font-size: 2.5rem; font-weight: bold; color: #10b981;">%0.00</div>
                <div style="color: #9ca3af;">Nominal Dönem Getirisi</div>
                <div id="sharpeDisplay" style="color: #a78bfa; font-size: 0.9rem; margin-top: 0.5rem; font-weight: bold;"></div>
            </div>
        </div>
    </div>

    <div class="benchmark-section">
        <div class="benchmark-inputs">
            <h3 style="margin-top:0; color:#e5e7eb; border-bottom: 1px solid #374151; padding-bottom: 0.5rem;">Dönem Makro Verileri</h3>
            <p style="font-size: 0.8rem; color: #9ca3af;">Seçilen tarihler arasındaki enflasyonu ve döviz artışını kullanarak gerçek getirinizi hesaplayın.</p>
            
            <div class="form-group">
                <label>Dönem İçi Enflasyon (TÜFE) Oranı (%) <span style="color:#60a5fa; font-size: 0.75rem;">(Manuel)</span></label>
                <input type="number" id="infInput" value="<?= number_format($auto_inf_rate, 2, '.', '') ?>" step="0.1">
            </div>
            <div class="form-group">
                <label>Dönem İçi Dolar/TL Artış Oranı (%) <span style="color:#10b981; font-size: 0.75rem;">(TCMB'den Otomatik)</span></label>
                <input type="number" id="usdInput" value="<?= number_format($auto_usd_rate, 2, '.', '') ?>" step="0.1">
            </div>

            <div class="stat-box" style="margin-top: 1.5rem;">
                <div style="font-size: 0.8rem; color: #9ca3af;">Enflasyondan Arındırılmış Net (Reel) Getiri</div>
                <div id="realReturnDisplay" style="font-size: 1.5rem; font-weight: bold; color: #10b981;">%0.00</div>
            </div>
            <div class="stat-box">
                <div style="font-size: 0.8rem; color: #9ca3af;">Dolar Bazlı Net Getiri</div>
                <div id="usdReturnDisplay" style="font-size: 1.5rem; font-weight: bold; color: #60a5fa;">%0.00</div>
            </div>
        </div>

        <div class="benchmark-chart">
            <canvas id="benchmarkChart"></canvas>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const sliders = document.querySelectorAll('.dynamic-slider');
            const totalReturnEl = document.getElementById('totalSimReturn');
            const sharpeDisplayEl = document.getElementById('sharpeDisplay');
            
            const infInput = document.getElementById('infInput');
            const usdInput = document.getElementById('usdInput');
            const realReturnDisplay = document.getElementById('realReturnDisplay');
            const usdReturnDisplay = document.getElementById('usdReturnDisplay');

            const optimizeBtn = document.getElementById('optimizeBtn');
            
            const historicalPrices = <?= json_encode($fund_prices_json) ?>;
            const colors = ['#fbbf24', '#60a5fa', '#a78bfa', '#34d399', '#f87171', '#fb923c', '#f472b6', '#2dd4bf'];
            
            let chartLabels = [];
            let chartColors = [];
            let chartData = [];

            sliders.forEach((slider, index) => {
                chartLabels.push(slider.getAttribute('data-code'));
                chartColors.push(colors[index % colors.length]);
                chartData.push(parseFloat(slider.value));
            });

            // Pasta Grafiği
            let simChart = new Chart(document.getElementById('simChart').getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: chartLabels,
                    datasets: [{ data: chartData, backgroundColor: chartColors, borderWidth: 0, hoverOffset: 5 }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: '#e5e7eb' } } }, cutout: '70%' }
            });

            // Bar Grafiği
            let benchChart = new Chart(document.getElementById('benchmarkChart').getContext('2d'), {
                type: 'bar',
                data: {
                    labels: ['Portföy (Nominal)', 'Enflasyon (TÜFE)', 'Dolar/TL', 'Portföy (Reel)', 'Portföy (Dolar)'],
                    datasets: [{
                        data: [0, 0, 0, 0, 0],
                        backgroundColor: ['#a78bfa', '#ef4444', '#f59e0b', '#10b981', '#3b82f6'],
                        borderRadius: 4
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => ' %' + ctx.parsed.y.toFixed(2) } } },
                    scales: {
                        y: { ticks: { color: '#9ca3af' }, grid: { color: 'rgba(75,85,99,0.3)' } },
                        x: { ticks: { color: '#e5e7eb', font: {size: 11} }, grid: { display: false } }
                    }
                }
            });

            // ORTAK GÜNLER VE GÜNLÜK GETİRİLER (Sharpe için)
            const dailyReturns = {};
            let commonDates = null;
            for (let code in historicalPrices) {
                let dates = Object.keys(historicalPrices[code]);
                if (commonDates === null) {
                    commonDates = dates;
                } else {
                    commonDates = commonDates.filter(d => dates.includes(d));
                }
            }
            
            let minDays = 0;
            if (commonDates && commonDates.length > 1) {
                commonDates.sort(); 
                minDays = commonDates.length - 1;
                for (let code in historicalPrices) {
                    let rets = [];
                    for (let i = 1; i < commonDates.length; i++) {
                        let priceToday = historicalPrices[code][commonDates[i]];
                        let pricePrev = historicalPrices[code][commonDates[i-1]];
                        rets.push((priceToday - pricePrev) / pricePrev);
                    }
                    dailyReturns[code] = rets;
                }
            }

            function updateSim() {
                let totalSliderValue = 0;
                sliders.forEach(s => totalSliderValue += parseInt(s.value) || 0);
                if (totalSliderValue === 0) return;

                let totalWeightedReturn = 0;
                let newData = [];
                let currentWeights = {};

                sliders.forEach((slider) => {
                    let actualPercent = (parseInt(slider.value) / totalSliderValue) * 100;
                    let code = slider.getAttribute('data-code');
                    let fundReturn = parseFloat(slider.getAttribute('data-return'));

                    document.getElementById('label-' + code).innerText = '%' + actualPercent.toFixed(1);
                    newData.push(actualPercent);
                    totalWeightedReturn += (fundReturn * (actualPercent / 100));
                    currentWeights[code] = actualPercent / 100;
                });

                totalReturnEl.innerText = '%' + totalWeightedReturn.toLocaleString('tr-TR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                simChart.data.datasets[0].data = newData;
                simChart.update();

                calculateAndDisplaySharpe(currentWeights);
                calculateBenchmarks(totalWeightedReturn);
            }

            // REEL GETİRİ HESAPLAMALARI
            function calculateBenchmarks(nominalReturn) {
                let inf = parseFloat(infInput.value) || 0;
                let usd = parseFloat(usdInput.value) || 0;

                let realReturn = (((1 + (nominalReturn / 100)) / (1 + (inf / 100))) - 1) * 100;
                let usdReturn = (((1 + (nominalReturn / 100)) / (1 + (usd / 100))) - 1) * 100;

                realReturnDisplay.innerText = '%' + realReturn.toFixed(2);
                realReturnDisplay.style.color = realReturn >= 0 ? '#10b981' : '#ef4444'; 

                usdReturnDisplay.innerText = '%' + usdReturn.toFixed(2);
                usdReturnDisplay.style.color = usdReturn >= 0 ? '#60a5fa' : '#ef4444';

                benchChart.data.datasets[0].data = [nominalReturn, inf, usd, realReturn, usdReturn];
                let bgColors = ['#a78bfa', '#ef4444', '#f59e0b', (realReturn >= 0 ? '#10b981' : '#f87171'), (usdReturn >= 0 ? '#3b82f6' : '#f87171')];
                benchChart.data.datasets[0].backgroundColor = bgColors;
                benchChart.update();
            }

            // SHARPE ORANI HESAPLAMASI
            function calculateAndDisplaySharpe(weights) {
                if (minDays === 0) {
                    sharpeDisplayEl.innerText = "Yeterli ortak tarih verisi bulunamadı.";
                    return;
                }
                
                let portDailyReturns = [];
                for (let day = 0; day < minDays; day++) {
                    let dailyRet = 0;
                    for (let code in weights) {
                        dailyRet += weights[code] * dailyReturns[code][day];
                    }
                    portDailyReturns.push(dailyRet);
                }

                let mean = portDailyReturns.reduce((a,b) => a+b, 0) / minDays;
                let variance = portDailyReturns.reduce((a,b) => a + Math.pow(b - mean, 2), 0) / minDays;
                let stdDev = Math.sqrt(variance);

                let annReturn = mean * 252;
                let annStdDev = stdDev * Math.sqrt(252);
                let sharpe = annStdDev === 0 ? 0 : (annReturn / annStdDev); 

                let annReturnPct = (annReturn * 100).toFixed(2);
                let annStdDevPct = (annStdDev * 100).toFixed(2);

                sharpeDisplayEl.innerHTML = `Sharpe Oranı: <span style="color:#fff">${sharpe.toFixed(2)}</span> ` + 
                (sharpe > 1.5 ? " 🔥" : (sharpe > 1.0 ? " 👍" : "")) +
                `<br><span style="font-size: 0.8rem; color: #6b7280; font-weight: normal; display: block; margin-top: 0.4rem;">(Ort. Yıllık Büyüme: %${annReturnPct} | Yıllık Dalgalanma/Risk: %${annStdDevPct})</span>`;
            }

            // MONTE CARLO OPTİMİZASYONU
            optimizeBtn.addEventListener('click', function() {
                const ITERATIONS = 5000;
                const codes = Object.keys(dailyReturns);
                if (codes.length === 0 || minDays === 0) {
                    alert("Optimizasyon için yeterli ortak fiyat verisi bulunamadı.");
                    return;
                }

                const originalText = optimizeBtn.innerText;
                optimizeBtn.innerText = "⏳ Hesaplanıyor...";
                optimizeBtn.style.opacity = "0.7";

                setTimeout(() => {
                    let bestSharpe = -Infinity;
                    let bestWeights = {};

                    for (let i = 0; i < ITERATIONS; i++) {
                        let w = codes.map(() => Math.random());
                        let sum = w.reduce((a,b) => a+b, 0);
                        let normW = w.map(val => val / sum);

                        let portDailyReturns = [];
                        for (let day = 0; day < minDays; day++) {
                            let dailyRet = 0;
                            for (let j = 0; j < codes.length; j++) {
                                dailyRet += normW[j] * dailyReturns[codes[j]][day];
                            }
                            portDailyReturns.push(dailyRet);
                        }

                        let mean = portDailyReturns.reduce((a,b) => a+b, 0) / minDays;
                        let variance = portDailyReturns.reduce((a,b) => a + Math.pow(b - mean, 2), 0) / minDays;
                        let stdDev = Math.sqrt(variance);

                        let annReturn = mean * 252;
                        let annStdDev = stdDev * Math.sqrt(252);
                        let sharpe = annStdDev === 0 ? 0 : (annReturn / annStdDev);

                        if (sharpe > bestSharpe) {
                            bestSharpe = sharpe;
                            for (let k = 0; k < codes.length; k++) {
                                bestWeights[codes[k]] = normW[k];
                            }
                        }
                    }

                    sliders.forEach((slider) => {
                        let code = slider.getAttribute('data-code');
                        if (bestWeights[code] !== undefined) {
                            slider.value = Math.round(bestWeights[code] * 100); 
                        }
                    });

                    updateSim();
                    
                    optimizeBtn.innerText = "✅ Optimizasyon Tamamlandı!";
                    optimizeBtn.style.background = "#10b981"; 
                    setTimeout(() => {
                        optimizeBtn.innerText = originalText;
                        optimizeBtn.style.background = ""; 
                        optimizeBtn.style.opacity = "1";
                    }, 2000);

                }, 100);
            });

            // Olay Dinleyicileri (Event Listeners)
            sliders.forEach(s => s.addEventListener('input', updateSim));
            infInput.addEventListener('input', updateSim);
            usdInput.addEventListener('input', updateSim);
            
            updateSim(); // İlk yüklemede grafikleri ve hesapları başlat
        });
    </script>
    <?php endif; ?>
</div>

</body>
</html>


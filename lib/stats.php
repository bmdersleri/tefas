<?php
declare(strict_types=1);

/**
 * TEFAS İstatistik ve Yardımcı Fonksiyonlar
 * PHP 8.4 uyumlu
 */

/** Basit ortalama */
function mean(array $values): ?float
{
    $n = count($values);
    return $n === 0 ? null : array_sum($values) / $n;
}

/** Örnek standart sapma (n-1) */
function stddev(array $values): ?float
{
    $n = count($values);
    if ($n < 2) return null;

    $m = mean($values);
    if ($m === null) return null;

    $sum = 0.0;
    foreach ($values as $v) {
        $d = $v - $m;
        $sum += $d * $d;
    }
    return sqrt($sum / ($n - 1));
}

/** Sadece negatif getiriler için standart sapma (downside volatility) */
function downside_stddev(array $returns): ?float
{
    $neg = array_filter($returns, fn(float $r): bool => $r < 0.0);
    $neg = array_values($neg);
    return count($neg) === 0 ? null : stddev($neg);
}

/** Basit quantile hesabı (0–1 arası q) */
function quantile(array $values, float $q): ?float
{
    $n = count($values);
    if ($n === 0) return null;

    sort($values);
    if ($q <= 0) return $values[0];
    if ($q >= 1) return $values[$n - 1];

    $pos   = ($n - 1) * $q;
    $lower = (int)floor($pos);
    $upper = (int)ceil($pos);

    if ($lower === $upper) return $values[$lower];

    $weight = $pos - $lower;
    return $values[$lower] * (1 - $weight) + $values[$upper] * $weight;
}

/** İki dizi (aynı uzunlukta) arasındaki korelasyon */
function correlation(array $xs, array $ys): ?float
{
    $n = count($xs);
    if ($n !== count($ys) || $n < 2) return null;

    $mx = mean($xs);
    $my = mean($ys);
    if ($mx === null || $my === null) return null;

    $num = 0.0;
    $sx  = 0.0;
    $sy  = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dx = $xs[$i] - $mx;
        $dy = $ys[$i] - $my;
        $num += $dx * $dy;
        $sx  += $dx * $dx;
        $sy  += $dy * $dy;
    }

    if ($sx <= 0 || $sy <= 0) return null;
    return $num / sqrt($sx * $sy);
}

/** İki tarih→getiri sözlüğü için korelasyon (ortak tarihler üzerinden) */
function correlation_assoc(array $a, array $b): ?float
{
    $common = array_intersect(array_keys($a), array_keys($b));
    $xs = [];
    $ys = [];
    foreach ($common as $d) {
        $xs[] = $a[$d];
        $ys[] = $b[$d];
    }
    return correlation($xs, $ys);
}

/** Güvenli yüzde değişim hesabı */
function pct_change_safe($new_value, $old_value): ?float
{
    if ($old_value === null || $new_value === null) return null;
    $old_value = (float)$old_value;
    $new_value = (float)$new_value;
    if ($old_value <= 0) return null;
    return (($new_value / $old_value) - 1.0) * 100.0;
}

/** Sayıyı belirli aralıkta tutar */
function clamp_float(float $value, float $min_value, float $max_value): float
{
    return max($min_value, min($max_value, $value));
}

/**
 * Net para akışı, katılımcı davranışı ve pay sayısı değişimlerinden 0–100 arası alarm skoru üretir.
 */
function smart_flow_alarm_score(
    ?float $participant_chg_pct,
    ?float $net_flow_pct,
    ?float $unit_chg_pct,
    ?float $avg_size_chg_pct
): ?float {
    if ($participant_chg_pct === null || $net_flow_pct === null) return null;

    $raw = 0.0;

    if ($participant_chg_pct > 0) {
        $raw += clamp_float($participant_chg_pct / 2.0, 0.0, 2.0) * 1.0;
    }
    if ($net_flow_pct < 0) {
        $raw += clamp_float(abs($net_flow_pct) / 1.0, 0.0, 5.0) * 1.8;
    }
    if ($unit_chg_pct !== null && $unit_chg_pct < 0) {
        $raw += clamp_float(abs($unit_chg_pct) / 1.0, 0.0, 4.0) * 1.6;
    }
    if ($avg_size_chg_pct !== null && $avg_size_chg_pct < 0) {
        $raw += clamp_float(abs($avg_size_chg_pct) / 1.0, 0.0, 4.0) * 1.2;
    }

    $score = 100.0 / (1.0 + exp(-($raw - 3.0)));

    if (!($participant_chg_pct > 0 && $net_flow_pct < 0)) {
        $score = min($score, 45.0);
    }

    if ($participant_chg_pct > 0 && $net_flow_pct < 0 && $unit_chg_pct !== null && $unit_chg_pct < 0) {
        $score = max($score, 72.0);
    }

    return round($score, 2);
}

/** Ortalama değer. Boş dizide null döner. */
function mean_nullable(array $values): ?float
{
    return count($values) === 0 ? null : array_sum($values) / count($values);
}

/** Tarih=>değer serisi için hareketli ortalama üretir. */
function rolling_mean_assoc(array $series, int $window): array
{
    if ($window <= 1) return $series;
    ksort($series);
    $dates = array_keys($series);
    $out = [];
    $count = count($dates);
    for ($i = 0; $i < $count; $i++) {
        $vals = [];
        $start = max(0, $i - $window + 1);
        for ($j = $start; $j <= $i; $j++) {
            $v = $series[$dates[$j]];
            if ($v !== null && is_numeric($v)) $vals[] = (float)$v;
        }
        $out[$dates[$i]] = !empty($vals) ? array_sum($vals) / count($vals) : null;
    }
    return $out;
}

/** Tarih=>değer serisini ilk pozitif değeri 100 olacak şekilde normalize eder. */
function normalize_series_to_100(array $series): array
{
    ksort($series);
    $base = null;
    foreach ($series as $v) {
        if ($v !== null && is_numeric($v) && (float)$v > 0) {
            $base = (float)$v;
            break;
        }
    }
    $out = [];
    foreach ($series as $d => $v) {
        $out[$d] = ($base !== null && $v !== null && is_numeric($v))
            ? ((float)$v / $base) * 100.0
            : null;
    }
    return $out;
}

/** Net akışları kümülatif yüzdeye çevirir. */
function cumulative_flow_pct_series(array $net_flow_series, array $size_series): array
{
    ksort($net_flow_series);
    ksort($size_series);
    $base_size = null;
    foreach ($size_series as $v) {
        if ($v !== null && is_numeric($v) && (float)$v > 0) {
            $base_size = (float)$v;
            break;
        }
    }
    $out = [];
    $cum = 0.0;
    foreach ($net_flow_series as $d => $nf) {
        if ($nf === null || !is_numeric($nf) || $base_size === null || $base_size <= 0) {
            $out[$d] = null;
            continue;
        }
        $cum += (float)$nf;
        $out[$d] = ($cum / $base_size) * 100.0;
    }
    return $out;
}

/** Backtest sonuçlarını kısa yoruma çevirir. */
function backtest_comment(int $signal_count, ?float $avg_ret_20d, ?float $hit_rate_20d): string
{
    return match (true) {
        $signal_count < 2 => 'Veri yetersiz',
        $avg_ret_20d !== null && $avg_ret_20d < 0 && $hit_rate_20d !== null && $hit_rate_20d >= 60 => 'Alarm etkili',
        $avg_ret_20d !== null && $avg_ret_20d > 2 => 'Yanlış pozitif olabilir',
        default => 'İzlenmeli',
    };
}

/** GET parametresinden güvenli tamsayı okur. */
function request_int_param(string $name, int $default, int $min, int $max): int
{
    if (!isset($_GET[$name])) return $default;
    $raw = filter_var($_GET[$name], FILTER_VALIDATE_INT);
    if ($raw === false) return $default;
    return max($min, min($max, (int)$raw));
}

/** GET parametresinden izinli seçenek okur. */
function request_choice_param(string $name, string $default, array $allowed): string
{
    if (!isset($_GET[$name])) return $default;
    $raw = (string)$_GET[$name];
    return in_array($raw, $allowed, true) ? $raw : $default;
}

/** Hücreye verilecek CSS sınıfını belirle (en iyi -> yeşil, en kötü -> kırmızı) */
function metric_class(string $metric_key, $value, array $metric_stats): string
{
    if ($value === null || !isset($metric_stats[$metric_key])) return '';

    $best  = $metric_stats[$metric_key]['best'];
    $worst = $metric_stats[$metric_key]['worst'];

    if ($best === null || $worst === null) return '';

    $eps = 1e-9;
    if (abs($value - $best)  < $eps) return 'best-value';
    if (abs($value - $worst) < $eps) return 'worst-value';
    return '';
}

/**
 * CVaR (Conditional Value at Risk / Expected Shortfall)
 * VaR eşiğinin altındaki kayıpların ortalaması.
 * $confidence: 0.95 veya 0.99
 * Returns: kayıp yüzdesi olarak pozitif değer
 */
function cvar(array $returns, float $confidence = 0.95): ?float
{
    $n = count($returns);
    if ($n === 0) return null;

    $var_value = quantile($returns, 1.0 - $confidence);
    if ($var_value === null) return null;

    $tail_losses = array_filter($returns, fn(float $r): bool => $r <= $var_value);
    if (empty($tail_losses)) return max(0.0, -100.0 * $var_value);

    $avg_tail = array_sum($tail_losses) / count($tail_losses);
    return max(0.0, -100.0 * $avg_tail);
}

/**
 * Drawdown serisi hesapla (tarih => drawdown %)
 * Her noktadaki zirveden uzaklığı yüzde olarak döner.
 */
function drawdown_series(array $price_series): array
{
    ksort($price_series);
    $peak = -INF;
    $dd = [];
    foreach ($price_series as $d => $p) {
        if ($p > $peak) $peak = $p;
        $dd[$d] = ($peak > 0) ? (($peak - $p) / $peak) * 100.0 : 0.0;
    }
    return $dd;
}

/**
 * Mevcut drawdown süresini hesapla (iş günü olarak).
 * Zirveden bu yana kaç gün geçti.
 */
function current_drawdown_duration(array $price_series): ?int
{
    ksort($price_series);
    $dates = array_keys($price_series);
    $n = count($dates);
    if ($n < 2) return null;

    $peak_idx = 0;
    $peak_val = (float)$price_series[$dates[0]];
    for ($i = 1; $i < $n; $i++) {
        $p = (float)$price_series[$dates[$i]];
        if ($p > $peak_val) {
            $peak_val = $p;
            $peak_idx = $i;
        }
    }

    $last_idx = $n - 1;
    if ($last_idx <= $peak_idx) return 0;

    return $last_idx - $peak_idx;
}

/**
 * Drawdown'dan kurtarma süresini hesapla (iş günü olarak).
 * Max drawdown'dan sonra fiyatın zirveye geri döndüğü günü bulur.
 * Hâlâ kurtarılmamışsa null döner.
 */
function recovery_time(array $price_series): ?int
{
    ksort($price_series);
    $dates = array_keys($price_series);
    $n = count($dates);
    if ($n < 2) return null;

    $peak_val = -INF;
    $max_dd = 0.0;
    $max_dd_peak_idx = 0;

    for ($i = 0; $i < $n; $i++) {
        $p = (float)$price_series[$dates[$i]];
        if ($p > $peak_val) $peak_val = $p;
        $dd = ($peak_val > 0) ? ($peak_val - $p) / $peak_val : 0.0;
        if ($dd > $max_dd) {
            $max_dd = $dd;
            $max_dd_peak_idx = $i;
        }
    }

    if ($max_dd <= 0.0) return 0;

    $recovery_price = $peak_val;
    for ($i = $max_dd_peak_idx; $i < $n; $i++) {
        if ((float)$price_series[$dates[$i]] >= $recovery_price) {
            return $i - $max_dd_peak_idx;
        }
    }

    return null;
}

/**
 * Beta katsayısı hesapla (piyasa/benchmark getirilerine göre).
 * Beta > 1: fon piyasadan daha değişken
 * Beta < 1: fon piyasadan daha az değişken
 * Beta = 1: fon piyasayla aynı hareket ediyor
 */
function beta(array $fund_returns, array $benchmark_returns): ?float
{
    $common = array_intersect(array_keys($fund_returns), array_keys($benchmark_returns));
    $fund_vals = [];
    $bench_vals = [];
    foreach ($common as $d) {
        $fund_vals[] = $fund_returns[$d];
        $bench_vals[] = $benchmark_returns[$d];
    }

    $n = count($fund_vals);
    if ($n < 10) return null;

    $cov = correlation($fund_vals, $bench_vals);
    if ($cov === null) return null;

    $bench_std = stddev($bench_vals);
    $fund_std = stddev($fund_vals);

    if ($bench_std === null || $bench_std <= 0 || $fund_std === null || $fund_std <= 0) return null;

    return $cov * ($fund_std / $bench_std);
}

/**
 * Mevcut drawdown yüzdesi (son fiyatın zirveye uzaklığı).
 */
function current_drawdown_pct(array $price_series): ?float
{
    ksort($price_series);
    $peak = -INF;
    $last_price = null;
    foreach ($price_series as $d => $p) {
        if ($p > $peak) $peak = $p;
        $last_price = $p;
    }
    if ($last_price === null || $peak <= 0) return null;
    return max(0.0, (($peak - $last_price) / $peak) * 100.0);
}

<?php
declare(strict_types=1);

/**
 * TEFAS Fon Analiz Motoru
 * Veri çekme, hesaplama ve dataset hazırlama mantığını barındırır.
 * PHP 8.4 uyumlu
 */

class FundAnalyzer
{
    /** @var array<string, array> Fon bazında normalize fiyat serileri */
    public array $series_by_fund = [];

    /** @var array<string, array> Ham fiyat serileri */
    public array $raw_price_by_fund = [];

    /** @var array<string, array> Katılımcı sayısı serileri */
    public array $participants_by_fund = [];

    /** @var array<string, array> PORTFOYBUYUKLUK serileri */
    public array $size_by_fund = [];

    /** @var array<string, array> TEDPAYSAYISI serileri */
    public array $units_by_fund = [];

    /** @var array<string, array> Kişi başı ortalama büyüklük */
    public array $avg_participant_size_by_fund = [];

    /** @var array<string, array> Net para akışı (TL) */
    public array $net_flow_by_fund = [];

    /** @var array<string, array> Net para akışı (%) */
    public array $net_flow_pct_by_fund = [];

    /** @var array<string, array> Kümülatif net para akışı (%) */
    public array $net_flow_cum_pct_by_fund = [];

    /** @var array<string, array> Alarm skoru */
    public array $flow_score_by_fund = [];

    /** @var array<string, array> 7G ortalamalı alarm skoru */
    public array $flow_score_ma7_by_fund = [];

    /** @var array<string, array> 14G ortalamalı alarm skoru */
    public array $flow_score_ma14_by_fund = [];

    /** @var array<string, array> Metrikler */
    public array $metrics_by_fund = [];

    /** @var array<string, array> Günlük getiriler */
    public array $daily_returns_by_fund = [];

    /** @var string[] Tüm tarihler */
    public array $all_dates = [];

    /** @var string[] Hata mesajları */
    public array $chunk_warnings = [];

    /** @var ?string Kritik hata */
    public ?string $error_msg = null;

    /** @var int Son senkron zaman damgası */
    public int $sync_last_run_ts;

    /** @var array Hata → hata mesajları */
    private array $errors = [];

    public function __construct()
    {
        $this->sync_last_run_ts = tefas_latest_sync_timestamp() ?? 0;
    }

    /**
     * Tek bir fonu analiz et
     */
    public function analyzeFund(
        string $code,
        string $fund_type,
        DateTime $start_date,
        DateTime $end_date,
        int $flow_window,
        int $divergence_window,
        array $alarm_thresholds,
    ): void {
        try {
            $tefas_result = historical_information_db_only(
                'BindHistoryInfo',
                $fund_type,
                $start_date,
                $end_date,
                $code,
            );
        } catch (\Exception $e) {
            $this->error_msg = 'Veri okunurken kritik hata (' . htmlspecialchars($code) . '): ' . $e->getMessage();
            return;
        }

        $data = $tefas_result->data;

        $price_series        = [];
        $participants_series = [];
        $size_series         = [];
        $units_series        = [];

        foreach ($data as $row) {
            if (!isset($row['TARIH'], $row['FIYAT'])) continue;

            $date  = $row['TARIH'];
            $price = (float)$row['FIYAT'];
            if ($price <= 0) continue;

            $price_series[$date] = $price;
            $this->all_dates[] = $date;

            if (isset($row['KISISAYISI'])) {
                $participants_series[$date] = (int)$row['KISISAYISI'];
            }
            if (isset($row['PORTFOYBUYUKLUK']) && $row['PORTFOYBUYUKLUK'] !== null) {
                $size_series[$date] = (float)$row['PORTFOYBUYUKLUK'];
            }
            if (isset($row['TEDPAYSAYISI']) && $row['TEDPAYSAYISI'] !== null) {
                $units_series[$date] = (float)$row['TEDPAYSAYISI'];
            }
        }

        if (empty($price_series)) {
            $this->chunk_warnings[$code][] = 'Bu tarih aralığında veritabanında kayıt yok.';
            return;
        }

        ksort($price_series);
        ksort($participants_series);
        ksort($size_series);
        ksort($units_series);

        // Uyarıları topla
        $this->collectWarnings($code, $fund_type, $start_date, $end_date, $price_series, $participants_series, $size_series, $units_series);

        // Temel fiyat bilgileri
        $firstDate  = array_key_first($price_series);
        $lastDate   = array_key_last($price_series);
        $base_price = $price_series[$firstDate];
        $last_price = $price_series[$lastDate];

        if ($base_price <= 0) return;

        // Günlük getiriler
        $daily_returns = [];
        $prev_price = null;
        foreach ($price_series as $d => $p) {
            if ($prev_price !== null && $prev_price > 0) {
                $daily_returns[$d] = $p / $prev_price - 1.0;
            }
            $prev_price = $p;
        }
        $this->daily_returns_by_fund[$code] = $daily_returns;

        // Net akış ve katılımcı başı büyüklük hesaplamaları
        $flow_data = $this->calculateFlowMetrics(
            $code, $price_series, $participants_series, $size_series, $units_series,
            $flow_window, $alarm_thresholds
        );

        // Normalize fiyat serisi ve drawdown
        $norm = [];
        $peak = -INF;
        $max_drawdown = 0.0;
        $min_price = INF;
        $max_price = -INF;

        foreach ($price_series as $d => $p) {
            $norm_val = ($p / $base_price) * 100.0;
            $norm[$d] = $norm_val;
            if ($p > $peak) $peak = $p;
            $dd = ($peak - $p) / $peak * 100.0;
            if ($dd > $max_drawdown) $max_drawdown = $dd;
            if ($p < $min_price) $min_price = $p;
            if ($p > $max_price) $max_price = $p;
        }

        // Metrikleri hesapla
        $metrics = $this->calculateMetrics(
            $daily_returns, $price_series, $base_price, $last_price,
            $firstDate, $lastDate, $min_price, $max_price, $max_drawdown,
            $flow_data
        );

        // Serileri kaydet
        $this->series_by_fund[$code]       = $norm;
        $this->raw_price_by_fund[$code]    = $price_series;
        $this->participants_by_fund[$code] = $participants_series;
        $this->size_by_fund[$code]         = $size_series;
        $this->units_by_fund[$code]        = $units_series;
        $this->avg_participant_size_by_fund[$code] = $flow_data['avg_participant_size'];
        $this->net_flow_by_fund[$code]     = $flow_data['net_flow'];
        $this->net_flow_pct_by_fund[$code] = $flow_data['net_flow_pct'];
        $this->net_flow_cum_pct_by_fund[$code] = $flow_data['net_flow_cum_pct'];
        $this->flow_score_by_fund[$code]   = $flow_data['flow_score'];
        $this->flow_score_ma7_by_fund[$code] = $flow_data['flow_score_ma7'];
        $this->flow_score_ma14_by_fund[$code] = $flow_data['flow_score_ma14'];
        $this->metrics_by_fund[$code]      = $metrics;
    }

    /**
     * Tüm datasetleri hazırla (grafikler için)
     */
    public function prepareDatasets(): array
    {
        $this->all_dates = array_values(array_unique($this->all_dates));
        sort($this->all_dates);

        return [
            'price'            => $this->buildDataset($this->series_by_fund, 0.15, 1.5),
            'participants'     => $this->buildDataset($this->participants_by_fund, 0.15, 1.5),
            'size'             => $this->buildDataset($this->size_by_fund, 0.15, 1.5),
            'size_norm'        => $this->buildNormalizedDataset($this->size_by_fund),
            'avg_participant'  => $this->buildDataset($this->avg_participant_size_by_fund, 0.15, 1.5),
            'avg_participant_norm' => $this->buildNormalizedDataset($this->avg_participant_size_by_fund),
            'net_flow_pct'     => $this->buildDataset($this->net_flow_pct_by_fund, 0.15, 1.5, 4),
            'net_flow_cum_pct' => $this->buildDataset($this->net_flow_cum_pct_by_fund, 0.18, 1.3, 4),
            'flow_score'       => $this->buildDataset($this->flow_score_by_fund, 0.15, 1.5, 2),
            'flow_score_ma7'   => $this->buildDataset($this->flow_score_ma7_by_fund, 0.18, 1.2, 2),
            'flow_score_ma14'  => $this->buildDataset($this->flow_score_ma14_by_fund, 0.20, 1.1, 2),
            'divergence'       => [],
            'histogram'        => $this->buildHistogram(),
            'correlation'      => $this->buildCorrelationMatrix(),
            'last_day_returns' => $this->buildLastDayReturns(),
        ];
    }

    /**
     * Dashboard özetini hesapla
     */
    public function buildDashboardSummary(): array
    {
        if (empty($this->metrics_by_fund)) return [];

        $red_count = 0;
        $watch_count = 0;
        $top_alarm_code = null;
        $top_alarm_score = null;
        $top_negative_flow_code = null;
        $top_negative_flow_pct = null;
        $top_negative_flow_tl = null;
        $strongest_code = null;
        $strongest_score = null;
        $best_return_code = null;
        $best_return = null;

        $watch_threshold  = ALARM_SCORE_WATCH;
        $red_threshold    = ALARM_SCORE_RED;

        foreach ($this->metrics_by_fund as $code => $m) {
            $score = $m['latest_flow_score'] ?? null;
            if ($score !== null) {
                if ($score >= $red_threshold) $red_count++;
                elseif ($score >= $watch_threshold) $watch_count++;

                if ($top_alarm_score === null || $score > $top_alarm_score) {
                    $top_alarm_score = $score;
                    $top_alarm_code = $code;
                }
            }

            $nf_pct = $m['latest_net_flow_pct'] ?? null;
            if ($nf_pct !== null && $nf_pct < 0) {
                if ($top_negative_flow_pct === null || $nf_pct < $top_negative_flow_pct) {
                    $top_negative_flow_pct = $nf_pct;
                    $top_negative_flow_tl = $m['latest_net_flow'] ?? null;
                    $top_negative_flow_code = $code;
                }
            }

            $period = $m['period_return'] ?? null;
            if ($period !== null && ($best_return === null || $period > $best_return)) {
                $best_return = $period;
                $best_return_code = $code;
            }

            $composite = 0.0;
            if (($m['period_return'] ?? null) !== null) $composite += $m['period_return'] * 0.35;
            if (($m['sharpe'] ?? null) !== null) $composite += $m['sharpe'] * 1.50;
            if (($m['max_drawdown'] ?? null) !== null) $composite -= $m['max_drawdown'] * 0.40;
            if (($m['latest_flow_score'] ?? null) !== null) $composite -= $m['latest_flow_score'] * 0.08;
            if ($strongest_score === null || $composite > $strongest_score) {
                $strongest_score = $composite;
                $strongest_code = $code;
            }
        }

        return [
            'fund_count'              => count($this->metrics_by_fund),
            'red_count'               => $red_count,
            'watch_count'             => $watch_count,
            'top_alarm_code'          => $top_alarm_code,
            'top_alarm_score'         => $top_alarm_score,
            'top_negative_flow_code'  => $top_negative_flow_code,
            'top_negative_flow_pct'   => $top_negative_flow_pct,
            'top_negative_flow_tl'    => $top_negative_flow_tl,
            'strongest_code'          => $strongest_code,
            'strongest_score'         => $strongest_score,
            'best_return_code'        => $best_return_code,
            'best_return'             => $best_return,
        ];
    }

    /**
     * Iraksaklık analizini hesapla
     */
    public function calculateDivergence(int $divergence_window, array $all_dates): array
    {
        $divergence_datasets = [];
        $divergence_summary  = [];

        foreach ($this->participants_by_fund as $code => $part_series) {
            $sz_series = $this->size_by_fund[$code] ?? [];
            $un_series = $this->units_by_fund[$code] ?? [];

            if (empty($part_series) || empty($sz_series)) continue;

            $common_kp = array_values(array_intersect(array_keys($part_series), array_keys($sz_series)));
            sort($common_kp);
            if (count($common_kp) < 2) continue;

            // Özet hesapla
            $divergence_summary[$code] = $this->calculateDivergenceSummary($part_series, $sz_series, $un_series, $common_kp);

            // Rolling iraksaklık skoru
            $rolling = $this->calculateRollingDivergence($part_series, $sz_series, $common_kp, $divergence_window);

            $rpts = [];
            foreach ($all_dates as $d) {
                $rpts[] = isset($rolling[$d]) ? round($rolling[$d], 4) : null;
            }
            $divergence_datasets[] = [
                'label'       => $code,
                'data'        => $rpts,
                'tension'     => 0.20,
                'pointRadius' => 1.2,
            ];
        }

        return ['datasets' => $divergence_datasets, 'summary' => $divergence_summary];
    }

    /**
     * Alarm backtest hesapla
     */
    public function calculateBacktest(array $backtest_horizons): array
    {
        $backtest_summary = [];

        foreach ($this->flow_score_by_fund as $code => $score_series) {
            if (empty($score_series) || !isset($this->raw_price_by_fund[$code])) continue;

            $price_series = $this->raw_price_by_fund[$code];
            $price_dates  = array_keys($price_series);
            sort($price_dates);
            $date_to_idx = array_flip($price_dates);

            $score_dates = array_keys($score_series);
            sort($score_dates);

            $signal_dates = [];
            $prev_alert = false;
            foreach ($score_dates as $d) {
                $is_alert = ($score_series[$d] >= ALARM_SCORE_RED);
                if ($is_alert && !$prev_alert && isset($date_to_idx[$d])) {
                    $signal_dates[] = $d;
                }
                $prev_alert = $is_alert;
            }

            $horizon_returns = array_fill_keys($backtest_horizons, []);
            $mdd_20_values = [];

            foreach ($signal_dates as $sd) {
                $idx = $date_to_idx[$sd];
                $entry_price = (float)$price_series[$sd];
                if ($entry_price <= 0) continue;

                foreach ($backtest_horizons as $h) {
                    $future_idx = $idx + $h;
                    if (isset($price_dates[$future_idx])) {
                        $fd = $price_dates[$future_idx];
                        $future_price = (float)$price_series[$fd];
                        if ($future_price > 0) {
                            $horizon_returns[$h][] = (($future_price / $entry_price) - 1.0) * 100.0;
                        }
                    }
                }

                $end_idx = min(count($price_dates) - 1, $idx + 20);
                $min_future = $entry_price;
                for ($j = $idx; $j <= $end_idx; $j++) {
                    $pd = $price_dates[$j];
                    $min_future = min($min_future, (float)$price_series[$pd]);
                }
                $mdd_20_values[] = (($min_future / $entry_price) - 1.0) * 100.0;
            }

            $ret5  = mean_nullable($horizon_returns[5]  ?? []);
            $ret10 = mean_nullable($horizon_returns[10] ?? []);
            $ret20 = mean_nullable($horizon_returns[20] ?? []);

            $hit_rate_20 = null;
            if (!empty($horizon_returns[20])) {
                $hits = count(array_filter($horizon_returns[20], fn($r) => $r < 0));
                $hit_rate_20 = ($hits / count($horizon_returns[20])) * 100.0;
            }

            $backtest_summary[$code] = [
                'signal_count'    => count($signal_dates),
                'last_signal_date' => !empty($signal_dates) ? end($signal_dates) : null,
                'avg_ret_5d'      => $ret5,
                'avg_ret_10d'     => $ret10,
                'avg_ret_20d'     => $ret20,
                'avg_mdd_20d'     => mean_nullable($mdd_20_values),
                'hit_rate_20d'    => $hit_rate_20,
                'comment'         => backtest_comment(count($signal_dates), $ret20, $hit_rate_20),
            ];
        }

        return $backtest_summary;
    }

    // ── Özel metodlar ──────────────────────────────────────────

    private function collectWarnings(
        string $code, string $fund_type, DateTime $start_date, DateTime $end_date,
        array $price_series, array $participants_series, array $size_series, array $units_series
    ): void {
        $missing_dates = tefas_missing_weekday_dates($code, $start_date, $end_date);
        if (!empty($missing_dates)) {
            $this->chunk_warnings[$code][] = sprintf(
                'Eksik iş günü verisi: %d gün (%s ... %s)',
                count($missing_dates),
                reset($missing_dates),
                end($missing_dates)
            );
        }

        $sync_state = tefas_sync_state($code, $fund_type);
        if ($sync_state && !empty($sync_state['mode']) && $sync_state['mode'] !== 'syncing') {
            $this->chunk_warnings[$code][] = 'Senkron durumu: keşif aşamasında (discovering).';
        }
        if ($sync_state && !empty($sync_state['last_error'])) {
            $this->chunk_warnings[$code][] = 'Son senkron hata: ' . (string)$sync_state['last_error'];
        }
    }

    private function calculateFlowMetrics(
        string $code,
        array $price_series,
        array $participants_series,
        array $size_series,
        array $units_series,
        int $flow_window,
        array $alarm_thresholds
    ): array {
        $avg_participant_size_series = [];
        $net_flow_series = [];
        $net_flow_pct_series = [];
        $participant_change_pct_series = [];
        $unit_change_pct_series = [];
        $avg_participant_size_change_pct_series = [];
        $flow_score_series = [];

        // Kişi başı ortalama büyüklük
        foreach ($size_series as $d => $sz) {
            if (isset($participants_series[$d]) && $participants_series[$d] > 0) {
                $avg_participant_size_series[$d] = $sz / $participants_series[$d];
            }
        }

        // Net akış hesaplama
        $price_dates_for_flow = array_keys($price_series);
        sort($price_dates_for_flow);
        $flow_count = count($price_dates_for_flow);

        for ($i = 1; $i < $flow_count; $i++) {
            $d_prev = $price_dates_for_flow[$i - 1];
            $d_now  = $price_dates_for_flow[$i];

            if (!isset($price_series[$d_prev], $price_series[$d_now], $size_series[$d_prev], $size_series[$d_now])) {
                continue;
            }

            $p_prev  = (float)$price_series[$d_prev];
            $p_now   = (float)$price_series[$d_now];
            $sz_prev = (float)$size_series[$d_prev];
            $sz_now  = (float)$size_series[$d_now];

            if ($p_prev <= 0 || $sz_prev <= 0) continue;

            $expected_size = $sz_prev * ($p_now / $p_prev);
            $net_flow     = $sz_now - $expected_size;
            $net_flow_pct = ($net_flow / $sz_prev) * 100.0;

            $net_flow_series[$d_now]     = $net_flow;
            $net_flow_pct_series[$d_now] = $net_flow_pct;

            if (isset($participants_series[$d_prev], $participants_series[$d_now])) {
                $participant_change_pct_series[$d_now] = pct_change_safe($participants_series[$d_now], $participants_series[$d_prev]);
            }

            if (isset($units_series[$d_prev], $units_series[$d_now])) {
                $unit_change_pct_series[$d_now] = pct_change_safe($units_series[$d_now], $units_series[$d_prev]);
            }

            if (isset($avg_participant_size_series[$d_prev], $avg_participant_size_series[$d_now])) {
                $avg_participant_size_change_pct_series[$d_now] = pct_change_safe($avg_participant_size_series[$d_now], $avg_participant_size_series[$d_prev]);
            }
        }

        // Alarm skoru hesaplama (rolling pencere ile)
        $flow_common_dates = array_values(array_intersect(array_keys($price_series), array_keys($size_series)));
        sort($flow_common_dates);
        $n_common = count($flow_common_dates);

        for ($i = 1; $i < $n_common; $i++) {
            $d_now = $flow_common_dates[$i];
            $start_idx = max(1, $i - $flow_window + 1);
            $d_ref = $flow_common_dates[$start_idx - 1];

            $rolling_net_flow = 0.0;
            $has_flow = false;
            for ($j = $start_idx; $j <= $i; $j++) {
                $dj = $flow_common_dates[$j];
                if (isset($net_flow_series[$dj]) && is_numeric($net_flow_series[$dj])) {
                    $rolling_net_flow += (float)$net_flow_series[$dj];
                    $has_flow = true;
                }
            }

            $rolling_net_flow_pct = null;
            if ($has_flow && isset($size_series[$d_ref]) && (float)$size_series[$d_ref] > 0) {
                $rolling_net_flow_pct = ($rolling_net_flow / (float)$size_series[$d_ref]) * 100.0;
            }

            $participant_chg_pct_roll = null;
            if (isset($participants_series[$d_ref], $participants_series[$d_now])) {
                $participant_chg_pct_roll = pct_change_safe($participants_series[$d_now], $participants_series[$d_ref]);
            }

            $unit_chg_pct_roll = null;
            if (isset($units_series[$d_ref], $units_series[$d_now])) {
                $unit_chg_pct_roll = pct_change_safe($units_series[$d_now], $units_series[$d_ref]);
            }

            $avg_size_chg_pct_roll = null;
            if (isset($avg_participant_size_series[$d_ref], $avg_participant_size_series[$d_now])) {
                $avg_size_chg_pct_roll = pct_change_safe($avg_participant_size_series[$d_now], $avg_participant_size_series[$d_ref]);
            }

            $flow_score = smart_flow_alarm_score(
                $participant_chg_pct_roll,
                $rolling_net_flow_pct,
                $unit_chg_pct_roll,
                $avg_size_chg_pct_roll
            );
            if ($flow_score !== null) {
                $flow_score_series[$d_now] = $flow_score;
            }
        }

        $flow_score_ma7_series  = rolling_mean_assoc($flow_score_series, 7);
        $flow_score_ma14_series = rolling_mean_assoc($flow_score_series, 14);
        $net_flow_cum_pct_series = cumulative_flow_pct_series($net_flow_series, $size_series);

        // Akış özeti metrikleri
        $latest_net_flow = null;
        $latest_net_flow_pct = null;
        $latest_flow_score = null;
        $latest_participant_chg_pct = null;
        $latest_unit_chg_pct = null;
        $latest_avg_size_chg_pct = null;
        $last_flow_date = null;

        if (!empty($net_flow_pct_series)) {
            $flow_dates = array_keys($net_flow_pct_series);
            sort($flow_dates);
            $last_flow_date = end($flow_dates);
            $latest_net_flow = $net_flow_series[$last_flow_date] ?? null;
            $latest_net_flow_pct = $net_flow_pct_series[$last_flow_date] ?? null;
            $latest_flow_score = $flow_score_series[$last_flow_date] ?? null;
            $latest_participant_chg_pct = $participant_change_pct_series[$last_flow_date] ?? null;
            $latest_unit_chg_pct = $unit_change_pct_series[$last_flow_date] ?? null;
            $latest_avg_size_chg_pct = $avg_participant_size_change_pct_series[$last_flow_date] ?? null;
        }

        $sum_net_flow = !empty($net_flow_series) ? array_sum($net_flow_series) : null;
        $avg_net_flow_pct = !empty($net_flow_pct_series) ? mean_nullable(array_values($net_flow_pct_series)) : null;

        $negative_flow_days = 0;
        $positive_flow_days = 0;
        foreach ($net_flow_series as $nf) {
            if ($nf < 0) $negative_flow_days++;
            elseif ($nf > 0) $positive_flow_days++;
        }

        $alert_days = 0;
        $watch_days = 0;
        $max_flow_score = null;
        foreach ($flow_score_series as $sc) {
            if ($max_flow_score === null || $sc > $max_flow_score) $max_flow_score = $sc;
            if ($sc >= ALARM_SCORE_RED) $alert_days++;
            elseif ($sc >= ALARM_SCORE_WATCH) $watch_days++;
        }
        $score_days = count($flow_score_series);
        $alert_ratio = $score_days > 0 ? ($alert_days / $score_days) * 100.0 : null;

        $flow_status = match (true) {
            $latest_flow_score !== null && $latest_flow_score >= ALARM_SCORE_CRITICAL => 'KRİTİK',
            $latest_flow_score !== null && $latest_flow_score >= ALARM_SCORE_RED => 'KIRMIZI ALARM',
            ($latest_flow_score !== null && $latest_flow_score >= ALARM_SCORE_WATCH) || ($max_flow_score !== null && $max_flow_score >= ALARM_SCORE_RED) => 'UYARI',
            $max_flow_score !== null && $max_flow_score >= ALARM_SCORE_WATCH => 'İZLEME',
            default => 'NORMAL',
        };

        $last_avg_participant_size = null;
        $avg_participant_size_chg = null;
        if (!empty($avg_participant_size_series)) {
            $aps_dates = array_keys($avg_participant_size_series);
            sort($aps_dates);
            $aps_first_d = $aps_dates[0];
            $aps_last_d  = end($aps_dates);
            $last_avg_participant_size = $avg_participant_size_series[$aps_last_d];
            $avg_participant_size_chg = pct_change_safe(
                $avg_participant_size_series[$aps_last_d],
                $avg_participant_size_series[$aps_first_d]
            );
        }

        return [
            'avg_participant_size'           => $avg_participant_size_series,
            'net_flow'                       => $net_flow_series,
            'net_flow_pct'                   => $net_flow_pct_series,
            'net_flow_cum_pct'               => $net_flow_cum_pct_series,
            'flow_score'                     => $flow_score_series,
            'flow_score_ma7'                 => $flow_score_ma7_series,
            'flow_score_ma14'                => $flow_score_ma14_series,
            'latest_net_flow'                => $latest_net_flow,
            'latest_net_flow_pct'            => $latest_net_flow_pct,
            'latest_flow_score'              => $latest_flow_score,
            'last_flow_date'                 => $last_flow_date,
            'latest_participant_chg_pct'     => $latest_participant_chg_pct,
            'latest_unit_chg_pct'            => $latest_unit_chg_pct,
            'latest_avg_size_chg_pct'        => $latest_avg_size_chg_pct,
            'sum_net_flow'                   => $sum_net_flow,
            'avg_net_flow_pct'               => $avg_net_flow_pct,
            'negative_flow_days'             => $negative_flow_days,
            'positive_flow_days'             => $positive_flow_days,
            'alert_days'                     => $alert_days,
            'watch_days'                     => $watch_days,
            'max_flow_score'                 => $max_flow_score,
            'alert_ratio'                    => $alert_ratio,
            'flow_status'                    => $flow_status,
            'last_avg_participant_size'      => $last_avg_participant_size,
            'avg_participant_size_chg'       => $avg_participant_size_chg,
        ];
    }

    private function calculateMetrics(
        array $daily_returns, array $price_series,
        float $base_price, float $last_price,
        string $firstDate, string $lastDate,
        float $min_price, float $max_price, float $max_drawdown,
        array $flow_data
    ): array {
        $returns_values = array_values($daily_returns);
        $n_ret = count($returns_values);

        $annual_return = null;
        $daily_vol = null;
        $annual_vol = null;
        $downside_vol = null;
        $annual_downside_vol = null;
        $sharpe = null;
        $sortino = null;
        $calmar = null;
        $var95 = null;
        $var99 = null;
        $cvar95 = null;
        $cvar99 = null;

        if ($n_ret > 1) {
            $sum_log = 0.0;
            foreach ($returns_values as $r) {
                $sum_log += log(1.0 + $r);
            }
            $mean_log_daily = $sum_log / $n_ret;
            $annual_return = exp($mean_log_daily * 252.0) - 1.0;

            $daily_vol = stddev($returns_values);
            if ($daily_vol !== null) {
                $annual_vol = $daily_vol * sqrt(252.0);
            }

            $downside_vol = downside_stddev($returns_values);
            if ($downside_vol !== null) {
                $annual_downside_vol = $downside_vol * sqrt(252.0);
            }

            if ($annual_vol !== null && $annual_vol > 0) {
                $sharpe = $annual_return / $annual_vol;
            }
            if ($annual_downside_vol !== null && $annual_downside_vol > 0) {
                $sortino = $annual_return / $annual_downside_vol;
            }
            if ($max_drawdown > 0) {
                $calmar = $annual_return / ($max_drawdown / 100.0);
            }
        }

        if ($n_ret > 0) {
            $q05 = quantile($returns_values, 0.05);
            $q01 = quantile($returns_values, 0.01);
            if ($q05 !== null) $var95 = max(0.0, -100.0 * $q05);
            if ($q01 !== null) $var99 = max(0.0, -100.0 * $q01);
            $cvar95 = cvar($returns_values, 0.95);
            $cvar99 = cvar($returns_values, 0.99);
        }

        // Drawdown analizi
        $dd_duration = current_drawdown_duration($price_series);
        $dd_recovery = recovery_time($price_series);
        $dd_current  = current_drawdown_pct($price_series);

        // Beta (benchmark: tüm fonların ortalama getirisi)
        $beta_val = null;
        if (count($this->daily_returns_by_fund) > 1) {
            $avg_benchmark = $this->calculateBenchmarkReturns();
            if (!empty($avg_benchmark)) {
                $beta_val = beta($daily_returns, $avg_benchmark);
            }
        }

        // Momentum
        $prices_list = array_values($price_series);
        $n_prices = count($prices_list);
        $mom_3m = $mom_6m = $mom_12m = null;

        $horizons = ['mom_3m' => 63, 'mom_6m' => 126, 'mom_12m' => 252];
        foreach ($horizons as $key => $h) {
            $val = null;
            if ($n_prices > $h) {
                $p_end   = $prices_list[$n_prices - 1];
                $p_start = $prices_list[$n_prices - 1 - $h];
                if ($p_start > 0) $val = $p_end / $p_start - 1.0;
            }
            $$key = $val;
        }

        $period_return = ($last_price / $base_price - 1.0) * 100.0;

        // Yeni metrikler
        $skew = skewness($returns_values);
        $kurt = kurtosis($returns_values);
        $omega = omega_ratio($returns_values);
        $win_pct = win_rate($returns_values);
        $best = best_day($returns_values);
        $worst = worst_day($returns_values);
        $pos_streak = max_streak($returns_values, true);
        $neg_streak = max_streak($returns_values, false);

        // Benchmark bazlı metrikler
        $info_ratio = null;
        $treynor = null;
        $te = null;
        $r2 = null;
        $real_ret = null;

        if (count($this->daily_returns_by_fund) > 1) {
            $avg_benchmark = $this->calculateBenchmarkReturns();
            if (!empty($avg_benchmark)) {
                $info_ratio = information_ratio($daily_returns, $avg_benchmark);
                $te = tracking_error($daily_returns, $avg_benchmark);
                $r2 = r_squared($daily_returns, $avg_benchmark);
            }
        }

        $treynor = treynor_ratio($annual_return, $beta_val);

        // Enflasyona göre düzeltme (yıllık ~%40 TÜFE proxy)
        if ($annual_return !== null) {
            $real_ret = real_return($annual_return, 0.40);
        }

        return [
            'start_date'         => $firstDate,
            'end_date'           => $lastDate,
            'base_price'         => $base_price,
            'last_price'         => $last_price,
            'period_return'      => $period_return,
            'annual_return'      => $annual_return,
            'daily_vol'          => $daily_vol,
            'annual_vol'         => $annual_vol,
            'sharpe'             => $sharpe,
            'sortino'            => $sortino,
            'calmar'             => $calmar,
            'max_drawdown'       => $max_drawdown,
            'min_price'          => $min_price,
            'max_price'          => $max_price,
            'mom_3m'             => $mom_3m,
            'mom_6m'             => $mom_6m,
            'mom_12m'            => $mom_12m,
            'var95'              => $var95,
            'var99'              => $var99,
            'cvar95'             => $cvar95,
            'cvar99'             => $cvar99,
            'dd_duration'        => $dd_duration,
            'dd_recovery'        => $dd_recovery,
            'dd_current'         => $dd_current,
            'beta'               => $beta_val,
            'skewness'           => $skew,
            'kurtosis'           => $kurt,
            'omega'              => $omega,
            'information_ratio'  => $info_ratio,
            'treynor'            => $treynor,
            'tracking_error'     => $te,
            'r_squared'          => $r2,
            'win_rate'           => $win_pct,
            'best_day'           => $best,
            'worst_day'          => $worst,
            'pos_streak'         => $pos_streak,
            'neg_streak'         => $neg_streak,
            'real_return'        => $real_ret,
            'last_flow_date'     => $flow_data['last_flow_date'],
            'latest_net_flow'    => $flow_data['latest_net_flow'],
            'latest_net_flow_pct'=> $flow_data['latest_net_flow_pct'],
            'sum_net_flow'       => $flow_data['sum_net_flow'],
            'avg_net_flow_pct'   => $flow_data['avg_net_flow_pct'],
            'negative_flow_days' => $flow_data['negative_flow_days'],
            'positive_flow_days' => $flow_data['positive_flow_days'],
            'latest_flow_score'  => $flow_data['latest_flow_score'],
            'max_flow_score'     => $flow_data['max_flow_score'],
            'alert_days'         => $flow_data['alert_days'],
            'watch_days'         => $flow_data['watch_days'],
            'alert_ratio'        => $flow_data['alert_ratio'],
            'flow_status'        => $flow_data['flow_status'],
            'latest_participant_chg_pct'  => $flow_data['latest_participant_chg_pct'],
            'latest_unit_chg_pct'         => $flow_data['latest_unit_chg_pct'],
            'latest_avg_size_chg_pct'     => $flow_data['latest_avg_size_chg_pct'],
            'last_avg_participant_size'   => $flow_data['last_avg_participant_size'],
            'avg_participant_size_chg'    => $flow_data['avg_participant_size_chg'],
        ];
    }

    private function buildDataset(array $source, float $tension = 0.15, float $pointRadius = 1.5, int $decimals = 3): array
    {
        $datasets = [];
        foreach ($source as $code => $series) {
            $points = [];
            foreach ($this->all_dates as $d) {
                $val = $series[$d] ?? null;
                $points[] = $val !== null ? round($val, $decimals) : null;
            }
            $datasets[] = [
                'label'       => $code,
                'data'        => $points,
                'tension'     => $tension,
                'pointRadius' => $pointRadius,
            ];
        }
        return $datasets;
    }

    private function buildNormalizedDataset(array $source): array
    {
        $datasets = [];
        foreach ($source as $code => $series) {
            $norm_series = normalize_series_to_100($series);
            $norm_points = [];
            foreach ($this->all_dates as $d) {
                $norm_points[] = isset($norm_series[$d]) ? round($norm_series[$d], 3) : null;
            }
            $datasets[] = [
                'label'       => $code,
                'data'        => $norm_points,
                'tension'     => 0.15,
                'pointRadius' => 1.5,
            ];
        }
        return $datasets;
    }

    private function buildHistogram(): array
    {
        if (empty($this->daily_returns_by_fund)) {
            return ['labels' => [], 'datasets' => [], 'fund_code' => null, 'var95' => null, 'var99' => null];
        }

        $hist_fund_code = array_key_first($this->daily_returns_by_fund);

        $all_hist_returns = [];
        foreach ($this->daily_returns_by_fund as $rets) {
            $all_hist_returns = array_merge($all_hist_returns, array_values($rets));
        }

        if (count($all_hist_returns) === 0) {
            return ['labels' => [], 'datasets' => [], 'fund_code' => null, 'var95' => null, 'var99' => null];
        }

        $min_r = min($all_hist_returns);
        $max_r = max($all_hist_returns);

        if ($max_r <= $min_r) {
            return ['labels' => [], 'datasets' => [], 'fund_code' => null, 'var95' => null, 'var99' => null];
        }

        $bins = 20;
        $bin_width = ($max_r - $min_r) / $bins;
        $hist_labels = [];
        $hist_datasets = [];

        for ($i = 0; $i < $bins; $i++) {
            $start_bin = $min_r + $i * $bin_width;
            $end_bin   = $start_bin + $bin_width;
            $hist_labels[] = sprintf('%.2f%%–%.2f%%', 100.0 * $start_bin, 100.0 * $end_bin);
        }

        foreach ($this->daily_returns_by_fund as $code => $rets) {
            $counts = array_fill(0, $bins, 0);
            foreach ($rets as $r) {
                $idx = (int)floor(($r - $min_r) / $bin_width);
                $idx = max(0, min($bins - 1, $idx));
                $counts[$idx]++;
            }
            $hist_datasets[] = ['label' => $code, 'data' => $counts, 'borderWidth' => 1];
        }

        $hist_var95 = null;
        $hist_var99 = null;
        $hist_returns_first = array_values($this->daily_returns_by_fund[$hist_fund_code]);
        if (count($hist_returns_first) > 0) {
            $q05 = quantile($hist_returns_first, 0.05);
            $q01 = quantile($hist_returns_first, 0.01);
            if ($q05 !== null) $hist_var95 = max(0.0, -100.0 * $q05);
            if ($q01 !== null) $hist_var99 = max(0.0, -100.0 * $q01);
        }

        return [
            'labels'    => $hist_labels,
            'datasets'  => $hist_datasets,
            'fund_code' => $hist_fund_code,
            'var95'     => $hist_var95,
            'var99'     => $hist_var99,
        ];
    }

    private function buildCorrelationMatrix(): array
    {
        $matrix = [];
        $codes = array_keys($this->daily_returns_by_fund);
        foreach ($codes as $c1) {
            $matrix[$c1] = [];
            foreach ($codes as $c2) {
                $matrix[$c1][$c2] = ($c1 === $c2)
                    ? 1.0
                    : correlation_assoc($this->daily_returns_by_fund[$c1], $this->daily_returns_by_fund[$c2]);
            }
        }
        return $matrix;
    }

    private function buildLastDayReturns(): array
    {
        $result = [];
        foreach ($this->series_by_fund as $code => $norm_series) {
            if (!isset($this->metrics_by_fund[$code])) continue;
            $rets = $this->daily_returns_by_fund[$code] ?? [];
            if (empty($rets)) continue;

            $sorted_dates = array_keys($rets);
            sort($sorted_dates);
            $last_ret_date = end($sorted_dates);

            $result[$code] = [
                'date'       => $last_ret_date,
                'return_pct' => $rets[$last_ret_date] * 100.0,
                'last_price' => $this->metrics_by_fund[$code]['last_price'],
            ];
        }
        return $result;
    }

    private function calculateDivergenceSummary(array $part_series, array $sz_series, array $un_series, array $common_kp): array
    {
        $first_d = $common_kp[0];
        $last_d  = end($common_kp);

        $k_first = (float)$part_series[$first_d];
        $k_last  = (float)$part_series[$last_d];
        $p_first = (float)$sz_series[$first_d];
        $p_last  = (float)$sz_series[$last_d];

        $k_chg = ($k_first > 0) ? (($k_last / $k_first) - 1.0) * 100.0 : null;
        $p_chg = ($p_first > 0) ? (($p_last / $p_first) - 1.0) * 100.0 : null;

        $u_chg  = null;
        $pp_chg = null;
        if (!empty($un_series)) {
            $common_ku = array_values(array_intersect(array_keys($part_series), array_keys($un_series)));
            sort($common_ku);
            if (count($common_ku) >= 2) {
                $uf_d = $common_ku[0];
                $ul_d = end($common_ku);
                $u_first = (float)$un_series[$uf_d];
                $u_last  = (float)$un_series[$ul_d];
                if ($u_first > 0) {
                    $u_chg = (($u_last / $u_first) - 1.0) * 100.0;
                }
                if (isset($sz_series[$uf_d], $sz_series[$ul_d]) && $u_first > 0 && $u_last > 0) {
                    $pp_first = $sz_series[$uf_d] / $u_first;
                    $pp_last  = $sz_series[$ul_d] / $u_last;
                    if ($pp_first > 0) {
                        $pp_chg = (($pp_last / $pp_first) - 1.0) * 100.0;
                    }
                }
            }
        }

        $eps = 0.5;
        $signal = match (true) {
            $k_chg !== null && $p_chg !== null && $k_chg > $eps && $p_chg > $eps => 'healthy',
            $k_chg !== null && $p_chg !== null && $k_chg > $eps && $p_chg < -$eps => 'alert',
            $k_chg !== null && $p_chg !== null && $k_chg < -$eps && $p_chg > $eps => 'consolidating',
            $k_chg !== null && $p_chg !== null && $k_chg < -$eps && $p_chg < -$eps => 'weakening',
            default => 'neutral',
        };

        return [
            'k_chg'      => $k_chg,
            'p_chg'      => $p_chg,
            'u_chg'      => $u_chg,
            'pp_chg'     => $pp_chg,
            'signal'     => $signal,
            'first_date' => $first_d,
            'last_date'  => $last_d,
        ];
    }

    private function calculateRollingDivergence(array $part_series, array $sz_series, array $common_kp, int $window): array
    {
        $rolling = [];
        $n_kp = count($common_kp);

        for ($i = 0; $i < $n_kp; $i++) {
            $d_now = $common_kp[$i];

            if ($i === 0) {
                $rolling[$d_now] = 0.0;
                continue;
            }

            $look_back = ($i < $window) ? $i : $window;
            $d_prev    = $common_kp[$i - $look_back];

            $kn = (float)$part_series[$d_now];
            $kp = (float)$part_series[$d_prev];
            $pn = (float)$sz_series[$d_now];
            $pp = (float)$sz_series[$d_prev];

            if ($kp > 0 && $pp > 0 && $kn > 0 && $pn > 0) {
                $rolling[$d_now] = log($kn / $kp) - log($pn / $pp);
            } else {
                $rolling[$d_now] = null;
            }
        }

        return $rolling;
    }

    /**
     * Benchmark getirisi: tüm fonların günlük getirilerinin ortalaması
     */
    private function calculateBenchmarkReturns(): array
    {
        $all_dates_returns = [];

        foreach ($this->daily_returns_by_fund as $code => $rets) {
            foreach ($rets as $d => $r) {
                $all_dates_returns[$d][] = $r;
            }
        }

        $benchmark = [];
        foreach ($all_dates_returns as $d => $values) {
            if (count($values) >= 2) {
                $benchmark[$d] = array_sum($values) / count($values);
            }
        }

        return $benchmark;
    }
}

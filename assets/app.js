/* ===== LOADING OVERLAY ===== */
const overlay = document.getElementById('loading-overlay');
document.getElementById('main-form').addEventListener('submit', () => {
    overlay.classList.add('visible');
});

/* ===== THEME TOGGLE ===== */
(function initTheme() {
    const saved = localStorage.getItem('theme');
    if (saved === 'dark' || saved === 'light') {
        document.documentElement.setAttribute('data-theme', saved);
    }
})();

document.getElementById('theme-toggle').addEventListener('click', () => {
    const html = document.documentElement;
    const current = html.getAttribute('data-theme');
    const isDark = current === 'dark' || (!current && window.matchMedia('(prefers-color-scheme: dark)').matches);
    const next = isDark ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
});

/* ===== SKIP NAV ===== */
document.querySelector('.skip-nav')?.addEventListener('click', (e) => {
    e.preventDefault();
    const target = document.getElementById('main-content');
    if (target) { target.focus(); target.scrollIntoView({ behavior: 'smooth' }); }
});

/* ===== HIZLI TARİH BUTONLARI ===== */
function toYMD(d) { return d.toISOString().slice(0, 10); }
document.querySelectorAll('.quick-dates .quick-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const end   = new Date();
        const start = new Date();
        if (btn.dataset.ytd) {
            start.setMonth(0); start.setDate(1);
        } else {
            start.setDate(end.getDate() - parseInt(btn.dataset.days, 10));
        }
        document.getElementById('start_date').value = toYMD(start);
        document.getElementById('end_date').value   = toYMD(end);
        document.querySelectorAll('.quick-dates .quick-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('main-form').submit();
    });
});
(function() {
    const sd   = document.getElementById('start_date').value;
    const ed   = document.getElementById('end_date').value;
    if (!sd || !ed) return;
    const diff = Math.round((new Date(ed) - new Date(sd)) / 86400000);
    document.querySelectorAll('.quick-dates .quick-btn[data-days]').forEach(btn => {
        if (parseInt(btn.dataset.days) === diff) btn.classList.add('active');
    });
})();

/* ===== V4.1 AYAR PANELİ ===== */
const resetSettingsBtn = document.getElementById('reset-settings-btn');
if (resetSettingsBtn) {
    resetSettingsBtn.addEventListener('click', () => {
        const defaults = {
            alarm_watch: 55,
            alarm_red: 70,
            alarm_critical: 85,
            flow_window: 14,
            divergence_window: 14,
            score_view: 'ma7'
        };
        Object.entries(defaults).forEach(([id, value]) => {
            const el = document.getElementById(id);
            if (el) el.value = value;
        });
    });
}

/* ===== TABLO SIRALAMA ===== */
function initSortableTable(tableId) {
    const table = document.getElementById(tableId);
    if (!table) return;
    let sortCol = -1, sortDir = 1;
    table.querySelectorAll('th.sortable').forEach(th => {
        th.addEventListener('click', () => {
            const col  = parseInt(th.dataset.col);
            const type = th.dataset.type || 'str';
            if (sortCol === col) { sortDir *= -1; }
            else { sortCol = col; sortDir = 1; }
            table.querySelectorAll('th.sortable').forEach(h => h.classList.remove('sort-asc','sort-desc'));
            th.classList.add(sortDir === 1 ? 'sort-asc' : 'sort-desc');
            const tbody = table.querySelector('tbody');
            const rows  = Array.from(tbody.querySelectorAll('tr'));
            rows.sort((a, b) => {
                const av = a.cells[col]?.innerText.trim() ?? '';
                const bv = b.cells[col]?.innerText.trim() ?? '';
                if (type === 'num') {
                    const an = parseFloat(av.replace(/[^0-9.\-]/g, '')) || -Infinity;
                    const bn = parseFloat(bv.replace(/[^0-9.\-]/g, '')) || -Infinity;
                    return (an - bn) * sortDir;
                }
                return av.localeCompare(bv, 'tr') * sortDir;
            });
            rows.forEach(r => tbody.appendChild(r));
        });
    });
}
initSortableTable('metrics-table');
initSortableTable('var-table');
initSortableTable('flow-table');
initSortableTable('backtest-table');

function sortTableByColumn(tableId, col, type = 'num', dir = -1) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    rows.sort((a, b) => {
        const av = a.cells[col]?.innerText.trim() ?? '';
        const bv = b.cells[col]?.innerText.trim() ?? '';
        if (type === 'num') {
            const an = parseFloat(av.replace(/[^0-9.\-]/g, '')) || -Infinity;
            const bn = parseFloat(bv.replace(/[^0-9.\-]/g, '')) || -Infinity;
            return (an - bn) * dir;
        }
        return av.localeCompare(bv, 'tr') * dir;
    });
    rows.forEach(r => tbody.appendChild(r));
}
sortTableByColumn('flow-table', 2, 'num', -1);

/* ===== V4.1 CSV DIŞA AKTARMA ===== */
function csvEscape(value) {
    const text = String(value ?? '').replace(/\s+/g, ' ').trim();
    return '"' + text.replace(/"/g, '""') + '"';
}
function exportTableToCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;
    const rows = Array.from(table.querySelectorAll('tr'));
    const csv = rows.map(row => {
        return Array.from(row.querySelectorAll('th,td'))
            .map(cell => csvEscape(cell.innerText))
            .join(';');
    }).join('\n');
    const blob = new Blob(['\ufeff' + csv], {type: 'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename || (tableId + '.csv');
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
document.querySelectorAll('[data-export-table]').forEach(btn => {
    btn.addEventListener('click', () => exportTableToCSV(btn.dataset.exportTable, btn.dataset.exportName));
});

/* ===== V4.1 HIZLI BÖLÜM MENÜSÜ ===== */
(function buildQuickSectionNav() {
    const nav = document.getElementById('quick-section-nav');
    if (!nav) return;
    const titles = Array.from(document.querySelectorAll('.section-title'));
    if (titles.length === 0) {
        nav.style.display = 'none';
        return;
    }
    const label = document.createElement('span');
    label.textContent = 'Bölümler:';
    label.style.cssText = 'font-size:11px;color:var(--muted);margin-right:2px;';
    nav.appendChild(label);

    titles.forEach((title, idx) => {
        if (!title.id) title.id = 'section-' + idx;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'quick-nav-btn';
        btn.textContent = title.textContent.replace(/^[▾▸]\s*/, '').replace(/\s+/g, ' ').trim().slice(0, 34);
        btn.addEventListener('click', () => {
            title.scrollIntoView({behavior: 'smooth', block: 'start'});
            document.querySelectorAll('.quick-nav-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        });
        nav.appendChild(btn);
    });
})();

/* ===== RENK PALETİ ===== */
const PALETTE = [
    '#3b82f6','#22c55e','#f59e0b','#ef4444','#a855f7',
    '#06b6d4','#ec4899','#84cc16','#f97316','#14b8a6'
];

function getChartColors() {
    const s = getComputedStyle(document.documentElement);
    return {
        tick:     s.getPropertyValue('--chart-tick').trim() || '#9ca3af',
        grid:     s.getPropertyValue('--chart-grid').trim() || 'rgba(75,85,99,0.3)',
        label:    s.getPropertyValue('--chart-label').trim() || '#e5e7eb',
        text:     s.getPropertyValue('--text').trim() || '#e5e7eb',
    };
}

function applyColors(datasets) {
    datasets.forEach((ds, i) => {
        const c = PALETTE[i % PALETTE.length];
        if (!ds.borderColor)     ds.borderColor     = c;
        if (!ds.backgroundColor) ds.backgroundColor = c + '33';
        ds.pointBackgroundColor = c;
    });
    return datasets;
}

function cloneDatasets(datasets) {
    return JSON.parse(JSON.stringify(datasets || []));
}
function wireToolbar(toolbarName, callback) {
    document.querySelectorAll(`[data-toolbar="${toolbarName}"] .chart-toggle-btn`).forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll(`[data-toolbar="${toolbarName}"] .chart-toggle-btn`).forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            callback(btn.dataset.mode);
        });
    });
}

/* ===== SON GÜNLÜK GETİRİ BAR ===== */
if (lastDayData.length > 0) {
    const cc = getChartColors();
    const ldLabels  = lastDayData.map(d => d.code);
    const ldValues  = lastDayData.map(d => d.return_pct);
    const ldColors  = ldValues.map(v => v >= 0 ? 'rgba(34,197,94,0.8)' : 'rgba(239,68,68,0.8)');
    const ldBorders = ldValues.map(v => v >= 0 ? '#22c55e' : '#ef4444');
    new Chart(document.getElementById('lastDayChart').getContext('2d'), {
        type: 'bar',
        data: { labels: ldLabels, datasets: [{ label: 'Son Günlük Getiri (%)',
            data: ldValues, backgroundColor: ldColors, borderColor: ldBorders,
            borderWidth: 1.5, borderRadius: 6 }] },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { labels: { color: cc.text } },
                tooltip: { callbacks: { label: ctx => ' ' + (ctx.parsed.y >= 0 ? '+' : '') + ctx.parsed.y.toFixed(4) + '%' } } },
            scales: {
                x: { ticks: { color: cc.tick }, grid: { color: cc.grid } },
                y: { ticks: { color: cc.tick, callback: v => (v >= 0 ? '+' : '') + v.toFixed(2) + '%' },
                    grid: { color: cc.grid },
                    title: { display: true, text: 'Günlük Getiri (%)', color: cc.label } }
            }
        }
    });
}

/* ===== NORMALİZE FİYAT GRAFİĞİ ===== */
if (labels.length > 0 && priceDatasets.length > 0) {
    const cc = getChartColors();
    applyColors(priceDatasets);
    new Chart(document.getElementById('fundChart').getContext('2d'), {
        type: 'line',
        data: { labels, datasets: priceDatasets },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { labels: { color: cc.text } },
                tooltip: { callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ' + (ctx.parsed.y != null ? ctx.parsed.y.toFixed(2) : '—') } } },
            scales: {
                x: { ticks: { color: cc.tick, maxRotation: 45, minRotation: 45 }, grid: { color: cc.grid } },
                y: { ticks: { color: cc.tick, callback: v => v.toFixed(0) },
                    grid: { color: cc.grid },
                    title: { display: true, text: 'Normalize Fiyat (Başlangıç = 100)', color: cc.label } }
            }
        }
    });
}

/* ===== KATILIMCI GRAFİĞİ ===== */
if (labels.length > 0 && participantsDatasets.length > 0) {
    const cc = getChartColors();
    applyColors(participantsDatasets);
    new Chart(document.getElementById('participantsChart').getContext('2d'), {
        type: 'line',
        data: { labels, datasets: participantsDatasets },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { labels: { color: cc.text } },
                tooltip: { callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ' + (ctx.parsed.y != null ? ctx.parsed.y.toLocaleString('tr-TR') : '—') } } },
            scales: {
                x: { ticks: { color: cc.tick, maxRotation: 45, minRotation: 45 }, grid: { color: cc.grid } },
                y: { ticks: { color: cc.tick, callback: v => v.toLocaleString('tr-TR') },
                    grid: { color: cc.grid },
                    title: { display: true, text: 'Katılımcı Sayısı', color: cc.label } }
            }
        }
    });
}

/* ===== HİSTOGRAM ===== */
if (histLabels.length > 0 && histDatasets.length > 0 && histFundCode !== null) {
    const cc = getChartColors();
    applyColors(histDatasets);
    new Chart(document.getElementById('histChart').getContext('2d'), {
        type: 'bar',
        data: { labels: histLabels, datasets: histDatasets },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { labels: { color: cc.text } } },
            scales: {
                x: { ticks: { color: cc.tick, maxRotation: 60, minRotation: 60 }, grid: { color: cc.grid } },
                y: { ticks: { color: cc.tick, callback: v => v.toFixed(0) },
                    grid: { color: cc.grid },
                    title: { display: true, text: 'Gün Sayısı', color: cc.label } }
            }
        }
    });
}

/* ===== TL FORMAT YARDIMCI ===== */
function formatTRL(v) {
    if (v == null || isNaN(v)) return '—';
    const a = Math.abs(v);
    if (a >= 1e9) return (v/1e9).toFixed(2) + ' Mr';
    if (a >= 1e6) return (v/1e6).toFixed(2) + ' M';
    if (a >= 1e3) return (v/1e3).toFixed(0) + ' K';
    return v.toFixed(0);
}

/* ===== FON BÜYÜKLÜĞÜ GRAFİĞİ ===== */
let sizeChart = null;
function buildSizeChart(mode = 'tl') {
    const canvas = document.getElementById('sizeChart');
    if (!canvas || labels.length === 0 || sizeDatasets.length === 0) return;
    const cc = getChartColors();
    const datasets = cloneDatasets(mode === 'norm' ? sizeNormDatasets : sizeDatasets);
    applyColors(datasets);
    if (sizeChart) sizeChart.destroy();
    sizeChart = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            spanGaps: true,
            plugins: { legend: { labels: { color: cc.text } },
                tooltip: { callbacks: {
                    label: ctx => {
                        if (ctx.parsed.y == null) return ' ' + ctx.dataset.label + ': —';
                        return mode === 'norm'
                            ? ` ${ctx.dataset.label}: ${ctx.parsed.y.toFixed(2)}`
                            : ' ' + ctx.dataset.label + ': ₺' + ctx.parsed.y.toLocaleString('tr-TR', {maximumFractionDigits: 0});
                    }
                } } },
            scales: {
                x: { ticks: { color: cc.tick, maxRotation: 45, minRotation: 45 }, grid: { color: cc.grid } },
                y: { ticks: { color: cc.tick, callback: v => mode === 'norm' ? v.toFixed(0) : '₺' + formatTRL(v) },
                    grid: { color: cc.grid },
                    title: { display: true, text: mode === 'norm' ? 'Portföy Büyüklüğü (Başlangıç = 100)' : 'Portföy Büyüklüğü (TL)', color: cc.label } }
            }
        }
    });
}
buildSizeChart('tl');
wireToolbar('size', buildSizeChart);

/* ===== NET PARA AKIŞI GRAFİĞİ ===== */
const zeroRiskBandPlugin = {
    id: 'zeroRiskBand',
    beforeDatasetsDraw(chart) {
        const {ctx, chartArea, scales} = chart;
        if (!chartArea || !scales || !scales.y) return;
        const yScale = scales.y;
        const yMin = yScale.min;
        const yMax = yScale.max;
        if (yMin >= 0 || yMax <= 0) return;
        const zeroPx = yScale.getPixelForValue(0);
        ctx.save();
        ctx.fillStyle = 'rgba(239,68,68,0.08)';
        ctx.fillRect(chartArea.left, zeroPx,
                     chartArea.right - chartArea.left,
                     chartArea.bottom - zeroPx);
        ctx.strokeStyle = 'rgba(239,68,68,0.55)';
        ctx.setLineDash([4, 4]);
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(chartArea.left, zeroPx);
        ctx.lineTo(chartArea.right, zeroPx);
        ctx.stroke();
        ctx.restore();
    }
};
if (labels.length > 0 && netFlowPctDatasets.length > 0) {
    const cc = getChartColors();
    const dailyFlow = cloneDatasets(netFlowPctDatasets).map(ds => ({...ds, type: 'bar', borderWidth: 1, borderRadius: 3}));
    applyColors(dailyFlow);
    new Chart(document.getElementById('netFlowChart').getContext('2d'), {
        type: 'bar',
        data: { labels, datasets: dailyFlow },
        plugins: [zeroRiskBandPlugin],
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels: { color: cc.text } },
                tooltip: { callbacks: {
                    label: ctx => {
                        if (ctx.parsed.y == null) return ' ' + ctx.dataset.label + ': —';
                        const v = ctx.parsed.y;
                        const tag = v < 0 ? '  ⚠️ günlük net çıkış' : '  ✓ günlük net giriş';
                        return ` ${ctx.dataset.label}: ${(v >= 0 ? '+' : '') + v.toFixed(3)}%${tag}`;
                    }
                } }
            },
            scales: {
                x: { ticks: { color: cc.tick, maxRotation: 45, minRotation: 45 }, grid: { color: cc.grid } },
                y: { ticks: { color: cc.tick, callback: v => (v >= 0 ? '+' : '') + v.toFixed(2) + '%' },
                    grid: { color: cc.grid },
                    title: { display: true, text: 'Günlük Net Akış / Önceki Büyüklük (%)', color: cc.label } }
            }
        }
    });
}
if (labels.length > 0 && netFlowCumPctDatasets.length > 0) {
    const cc = getChartColors();
    const cumFlow = cloneDatasets(netFlowCumPctDatasets);
    applyColors(cumFlow);
    new Chart(document.getElementById('netFlowCumChart').getContext('2d'), {
        type: 'line',
        data: { labels, datasets: cumFlow },
        plugins: [zeroRiskBandPlugin],
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            spanGaps: true,
            plugins: {
                legend: { labels: { color: cc.text } },
                tooltip: { callbacks: {
                    label: ctx => {
                        if (ctx.parsed.y == null) return ' ' + ctx.dataset.label + ': —';
                        const v = ctx.parsed.y;
                        const tag = v < 0 ? '  ⚠️ kümülatif çıkış' : '  ✓ kümülatif giriş';
                        return ` ${ctx.dataset.label}: ${(v >= 0 ? '+' : '') + v.toFixed(2)}%${tag}`;
                    }
                } }
            },
            scales: {
                x: { ticks: { color: cc.tick, maxRotation: 45, minRotation: 45 }, grid: { color: cc.grid } },
                y: { ticks: { color: cc.tick, callback: v => (v >= 0 ? '+' : '') + v.toFixed(1) + '%' },
                    grid: { color: cc.grid },
                    title: { display: true, text: 'Kümülatif Net Akış / Başlangıç Büyüklüğü (%)', color: cc.label } }
            }
        }
    });
}

/* ===== KATILIMCI BAŞINA ORTALAMA BÜYÜKLÜK ===== */
let avgParticipantChart = null;
function buildAvgParticipantChart(mode = 'tl') {
    const canvas = document.getElementById('avgParticipantChart');
    if (!canvas || labels.length === 0 || avgParticipantSizeDatasets.length === 0) return;
    const cc = getChartColors();
    const datasets = cloneDatasets(mode === 'norm' ? avgParticipantSizeNormDatasets : avgParticipantSizeDatasets);
    applyColors(datasets);
    if (avgParticipantChart) avgParticipantChart.destroy();
    avgParticipantChart = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: { labels, datasets },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            spanGaps: true,
            plugins: { legend: { labels: { color: cc.text } },
                tooltip: { callbacks: {
                    label: ctx => {
                        if (ctx.parsed.y == null) return ' ' + ctx.dataset.label + ': —';
                        return mode === 'norm'
                            ? ` ${ctx.dataset.label}: ${ctx.parsed.y.toFixed(2)}`
                            : ' ' + ctx.dataset.label + ': ₺' + ctx.parsed.y.toLocaleString('tr-TR', {maximumFractionDigits: 0});
                    }
                } } },
            scales: {
                x: { ticks: { color: cc.tick, maxRotation: 45, minRotation: 45 }, grid: { color: cc.grid } },
                y: { ticks: { color: cc.tick, callback: v => mode === 'norm' ? v.toFixed(0) : '₺' + formatTRL(v) },
                    grid: { color: cc.grid },
                    title: { display: true, text: mode === 'norm' ? 'Katılımcı Başına Büyüklük (Başlangıç = 100)' : 'Katılımcı Başına Ortalama Büyüklük (TL)', color: cc.label } }
            }
        }
    });
}
buildAvgParticipantChart('tl');
wireToolbar('avgparticipant', buildAvgParticipantChart);

/* ===== AKILLI PARA ALARM SKORU ===== */
const scoreBandPlugin = {
    id: 'scoreBand',
    beforeDatasetsDraw(chart) {
        const {ctx, chartArea, scales} = chart;
        if (!chartArea || !scales || !scales.y) return;
        const yScale = scales.y;
        const watchY = yScale.getPixelForValue(alarmWatchThreshold);
        const redY   = yScale.getPixelForValue(alarmRedThreshold);
        const criticalY = yScale.getPixelForValue(alarmCriticalThreshold);
        ctx.save();
        ctx.fillStyle = 'rgba(245,158,11,0.08)';
        ctx.fillRect(chartArea.left, redY,
                     chartArea.right - chartArea.left,
                     watchY - redY);
        ctx.fillStyle = 'rgba(239,68,68,0.10)';
        ctx.fillRect(chartArea.left, criticalY,
                     chartArea.right - chartArea.left,
                     redY - criticalY);
        ctx.fillStyle = 'rgba(127,29,29,0.18)';
        ctx.fillRect(chartArea.left, chartArea.top,
                     chartArea.right - chartArea.left,
                     criticalY - chartArea.top);
        [alarmWatchThreshold, alarmRedThreshold, alarmCriticalThreshold].forEach(v => {
            const y = yScale.getPixelForValue(v);
            ctx.strokeStyle = v === alarmCriticalThreshold ? 'rgba(248,113,113,0.80)' : (v === alarmRedThreshold ? 'rgba(239,68,68,0.65)' : 'rgba(245,158,11,0.65)');
            ctx.setLineDash([4, 4]);
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(chartArea.left, y);
            ctx.lineTo(chartArea.right, y);
            ctx.stroke();
            ctx.setLineDash([]);
            ctx.fillStyle = v === alarmCriticalThreshold ? 'rgba(254,226,226,0.95)' : (v === alarmRedThreshold ? 'rgba(254,202,202,0.95)' : 'rgba(253,230,138,0.95)');
            ctx.font = '11px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            const label = v === alarmCriticalThreshold ? 'Kritik ' + v : (v === alarmRedThreshold ? 'Kırmızı ' + v : 'İzleme ' + v);
            ctx.fillText(label, chartArea.left + 6, y - 5);
        });
        ctx.restore();
    }
};
let flowScoreChart = null;
function buildFlowScoreChart(mode = 'ma7') {
    const canvas = document.getElementById('flowScoreChart');
    if (!canvas || labels.length === 0 || flowScoreDatasets.length === 0) return;
    const cc = getChartColors();
    const source = mode === 'ma14' ? flowScoreMa14Datasets : (mode === 'ma7' ? flowScoreMa7Datasets : flowScoreDatasets);
    const datasets = cloneDatasets(source);
    applyColors(datasets);
    if (flowScoreChart) flowScoreChart.destroy();
    const titleMap = { raw: 'Alarm Skoru (Günlük)', ma7: 'Alarm Skoru (7 Günlük Ortalama)', ma14: 'Alarm Skoru (14 Günlük Ortalama)' };
    flowScoreChart = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: { labels, datasets },
        plugins: [scoreBandPlugin],
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            spanGaps: true,
            plugins: { legend: { labels: { color: cc.text } },
                tooltip: { callbacks: {
                    label: ctx => {
                        if (ctx.parsed.y == null) return ' ' + ctx.dataset.label + ': —';
                        const v = ctx.parsed.y;
                        const tag = v >= alarmCriticalThreshold ? '  🔥 KRİTİK' : (v >= alarmRedThreshold ? '  🚨 KIRMIZI' : (v >= alarmWatchThreshold ? '  ⚠️ İZLEME' : '  ✓ normal'));
                        return ` ${ctx.dataset.label}: ${v.toFixed(1)} / 100${tag}`;
                    }
                } } },
            scales: {
                x: { ticks: { color: cc.tick, maxRotation: 45, minRotation: 45 }, grid: { color: cc.grid } },
                y: { min: 0, max: 100,
                    ticks: { color: cc.tick, callback: v => v.toFixed(0) },
                    grid: { color: cc.grid },
                    title: { display: true, text: titleMap[mode] || titleMap.ma7, color: cc.label } }
            }
        }
    });
}
buildFlowScoreChart(defaultScoreView || 'ma7');
wireToolbar('flowscore', buildFlowScoreChart);

/* ===== IRAKSAKLIK (DIVERGENCE) GRAFİĞİ ===== */
if (labels.length > 0 && divergenceDatasets.length > 0) {
    const cc = getChartColors();
    applyColors(divergenceDatasets);

    const alarmBandPlugin = {
        id: 'alarmBand',
        beforeDatasetsDraw(chart) {
            const {ctx, chartArea, scales} = chart;
            if (!chartArea || !scales || !scales.y) return;
            const yScale  = scales.y;
            const yMin    = yScale.min;
            const yMax    = yScale.max;
            if (yMax <= 0) return;
            const zeroPx  = yScale.getPixelForValue(Math.max(0, yMin));
            const topPx   = chartArea.top;
            ctx.save();
            ctx.fillStyle = 'rgba(239,68,68,0.08)';
            ctx.fillRect(chartArea.left, topPx,
                         chartArea.right - chartArea.left,
                         zeroPx - topPx);
            ctx.strokeStyle = 'rgba(239,68,68,0.55)';
            ctx.setLineDash([4, 4]);
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(chartArea.left, zeroPx);
            ctx.lineTo(chartArea.right, zeroPx);
            ctx.stroke();
            ctx.restore();
        }
    };

    new Chart(document.getElementById('divergenceChart').getContext('2d'), {
        type: 'line',
        data: { labels, datasets: divergenceDatasets },
        plugins: [alarmBandPlugin],
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            spanGaps: true,
            plugins: {
                legend: { labels: { color: cc.text } },
                tooltip: { callbacks: {
                    label: ctx => {
                        if (ctx.parsed.y == null) return ' ' + ctx.dataset.label + ': —';
                        const v = ctx.parsed.y;
                        const ratio = Math.exp(Math.abs(v));
                        let interp;
                        if (Math.abs(v) < 0.01) {
                            interp = 'eşit hızda';
                        } else if (v > 0) {
                            interp = `K, P'den ~${ratio.toFixed(2)}× hızlı`;
                        } else {
                            interp = `P, K'dan ~${ratio.toFixed(2)}× hızlı`;
                        }
                        const tag = v > 0 ? '  ⚠️ ALARM' : '  ✓ sağlıklı';
                        return ` ${ctx.dataset.label}: ${(v >= 0 ? '+' : '') + v.toFixed(3)} (${interp})${tag}`;
                    }
                } }
            },
            scales: {
                x: { ticks: { color: cc.tick, maxRotation: 45, minRotation: 45 }, grid: { color: cc.grid } },
                y: { ticks: { color: cc.tick, callback: v => (v >= 0 ? '+' : '') + v.toFixed(2) },
                    grid: { color: cc.grid },
                    title: { display: true,
                             text: 'Log Iraksaklık Skoru: ln(KΔ) − ln(PΔ)  →  Pozitif = ALARM',
                             color: cc.label } }
            }
        }
    });
}

/* ===== BÖLÜM AÇ/KAPAT ===== */
document.querySelectorAll('.section-title').forEach(title => {
    title.title = 'Bölümü aç/kapat';
    title.addEventListener('click', (ev) => {
        const collapsed = title.classList.toggle('collapsed');
        let el = title.nextElementSibling;
        while (el && !el.classList.contains('section-title')) {
            el.style.display = collapsed ? 'none' : '';
            el = el.nextElementSibling;
        }
    });
});

/* ===== FON KARŞILAŞTIRMA ===== */
(function initCompare() {
    const cbs = document.querySelectorAll('.compare-cb');
    const bar = document.getElementById('compare-bar');
    const countEl = document.getElementById('compare-count-num');
    const compareBtn = document.getElementById('compare-btn');
    const clearBtn = document.getElementById('compare-clear-btn');
    if (!bar || !compareBtn) return;

    function getSelected() {
        return Array.from(document.querySelectorAll('.compare-cb:checked')).map(cb => cb.value);
    }
    function updateBar() {
        const sel = getSelected();
        countEl.textContent = sel.length;
        bar.classList.toggle('hidden', sel.length === 0);
    }
    cbs.forEach(cb => cb.addEventListener('change', updateBar));

    clearBtn.addEventListener('click', () => {
        cbs.forEach(cb => { cb.checked = false; });
        updateBar();
        document.getElementById('compare-section')?.classList.add('hidden');
        if (window._compareChartInstance) { window._compareChartInstance.destroy(); window._compareChartInstance = null; }
    });

    compareBtn.addEventListener('click', () => {
        const sel = getSelected();
        if (sel.length < 2) { alert('En az 2 fon seçin.'); return; }
        buildCompareView(sel);
    });
})();

function buildCompareView(codes) {
    const section = document.getElementById('compare-section');
    section.classList.remove('hidden');

    const allMetrics = metricsByFund;
    const allLastDay = lastDayReturnsFull;
    const cc = getChartColors();

    const metrics = [
        ['Son Fiyat',       code => { const d = allLastDay[code]; return d ? d.last_price.toLocaleString('tr-TR', {minimumFractionDigits:4, maximumFractionDigits:4}) : '—'; }],
        ['Son Getiri (%)',  code => { const d = allLastDay[code]; return d ? (d.return_pct >= 0 ? '+' : '') + d.return_pct.toFixed(4) + '%' : '—'; }],
        ['Dönem Getiri (%)',code => { const m = allMetrics[code] || {}; return m.period_return != null ? m.period_return.toFixed(2) + '%' : '—'; }],
        ['Yıllık Getiri (%)',code => { const m = allMetrics[code] || {}; return m.annual_return != null ? m.annual_return.toFixed(2) + '%' : '—'; }],
        ['Yıllık Vol (%)',  code => { const m = allMetrics[code] || {}; return m.annual_vol != null ? (m.annual_vol * 100).toFixed(2) + '%' : '—'; }],
        ['Sharpe',          code => { const m = allMetrics[code] || {}; return m.sharpe != null ? m.sharpe.toFixed(2) : '—'; }],
        ['Sortino',         code => { const m = allMetrics[code] || {}; return m.sortino != null ? m.sortino.toFixed(2) : '—'; }],
        ['Calmar',          code => { const m = allMetrics[code] || {}; return m.calmar != null ? m.calmar.toFixed(2) : '—'; }],
        ['Max DD (%)',      code => { const m = allMetrics[code] || {}; return m.max_drawdown != null ? m.max_drawdown.toFixed(2) + '%' : '—'; }],
        ['CVaR95',          code => { const m = allMetrics[code] || {}; return m.cvar95 != null ? m.cvar95.toFixed(2) + '%' : '—'; }],
        ['CVaR99',          code => { const m = allMetrics[code] || {}; return m.cvar99 != null ? m.cvar99.toFixed(2) + '%' : '—'; }],
        ['Beta',            code => { const m = allMetrics[code] || {}; return m.beta != null ? m.beta.toFixed(2) : '—'; }],
        ['Treynor',         code => { const m = allMetrics[code] || {}; return m.treynor != null ? m.treynor.toFixed(2) : '—'; }],
        ['Info Ratio',      code => { const m = allMetrics[code] || {}; return m.information_ratio != null ? m.information_ratio.toFixed(2) : '—'; }],
        ['R² (%)',          code => { const m = allMetrics[code] || {}; return m.r_squared != null ? m.r_squared.toFixed(1) : '—'; }],
        ['Omega',           code => { const m = allMetrics[code] || {}; return m.omega != null ? m.omega.toFixed(2) : '—'; }],
        ['Gerçek Getiri (%)', code => { const m = allMetrics[code] || {}; return m.real_return != null ? (m.real_return * 100).toFixed(2) + '%' : '—'; }],
        ['Çarpıklık',       code => { const m = allMetrics[code] || {}; return m.skewness != null ? m.skewness.toFixed(2) : '—'; }],
        ['Basıklık',        code => { const m = allMetrics[code] || {}; return m.kurtosis != null ? m.kurtosis.toFixed(2) : '—'; }],
        ['Kazanma (%)',     code => { const m = allMetrics[code] || {}; return m.win_rate != null ? m.win_rate.toFixed(1) + '%' : '—'; }],
        ['En İyi Gün (%)',  code => { const m = allMetrics[code] || {}; return m.best_day != null ? m.best_day.toFixed(2) + '%' : '—'; }],
        ['En Kötü Gün (%)', code => { const m = allMetrics[code] || {}; return m.worst_day != null ? m.worst_day.toFixed(2) + '%' : '—'; }],
        ['Kazanma Serisi',  code => { const m = allMetrics[code] || {}; return m.pos_streak != null ? m.pos_streak + ' gün' : '—'; }],
        ['Kaybetme Serisi', code => { const m = allMetrics[code] || {}; return m.neg_streak != null ? m.neg_streak + ' gün' : '—'; }],
        ['DD Süresi (gün)', code => { const m = allMetrics[code] || {}; return m.dd_duration != null ? m.dd_duration : '—'; }],
        ['Kurtarma (gün)',  code => { const m = allMetrics[code] || {}; return m.dd_recovery != null ? m.dd_recovery : '—'; }],
        ['Mevcut DD (%)',   code => { const m = allMetrics[code] || {}; return m.dd_current != null ? m.dd_current.toFixed(2) + '%' : '—'; }],
    ];

    const thead = document.querySelector('#compare-table thead tr');
    thead.innerHTML = '<th>Metrik</th>' + codes.map(c => `<th>${c}</th>`).join('');
    const tbody = document.querySelector('#compare-table tbody');
    tbody.innerHTML = metrics.map(([label, fn]) => {
        const cells = codes.map(c => `<td>${fn(c)}</td>`).join('');
        return `<tr><td>${label}</td>${cells}</tr>`;
    }).join('');

    const filteredDs = priceDatasets.filter(ds => codes.includes(ds.label));
    applyColors(filteredDs);

    const canvas = document.getElementById('compareChart');
    if (window._compareChartInstance) window._compareChartInstance.destroy();
    window._compareChartInstance = new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: { labels, datasets: filteredDs },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { labels: { color: cc.text } },
                tooltip: { callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ' + (ctx.parsed.y != null ? ctx.parsed.y.toFixed(2) : '—') } } },
            scales: {
                x: { ticks: { color: cc.tick, maxRotation: 45, minRotation: 45 }, grid: { color: cc.grid } },
                y: { ticks: { color: cc.tick }, grid: { color: cc.grid },
                    title: { display: true, text: 'Normalize Fiyat (100 = Başlangıç)', color: cc.label } }
            }
        }
    });

    section.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/* ===== YAZDIRMA ===== */
document.getElementById('print-btn')?.addEventListener('click', () => window.print());

/* ===== TÜM TABLOLARI CSV İNDİR ===== */
document.getElementById('export-all-csv-btn')?.addEventListener('click', () => {
    ['metrics-table', 'var-table', 'flow-table', 'backtest-table'].forEach(tid => {
        const table = document.getElementById(tid);
        if (table) exportTableToCSV(tid, tid + '.csv');
    });
});

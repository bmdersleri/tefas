<?php

return [
    'timezone' => 'Europe/Istanbul',
    'window_start' => '08:00',
    'window_end' => '23:59',
    'cron_interval_minutes' => 15,

    // Alt sınır: fon bundan eskiyse bile en fazla bu tarihe kadar geri taranır.
    'global_floor_date' => '2024-01-01',

    // Hibrit keşif: önce kaba arama, sonra ince sınır bulma.
    'discovery_coarse_days' => 90,
    'sync_chunk_days' => 7,

    // Her cron run'ında tüm whitelist fonları dolaşılır; fon başına tek chunk işlenir.
    'fund_whitelist' => [
        'TLY:YAT',
        'PHE:YAT',
        'PBR:YAT',
        'DFI:YAT',
        'KZL:YAT',
        'KUT:YAT',
        'YZG:YAT',
        'MJG:YAT',
        'BSM:YAT',
        'MT2:YAT',
        'GBJ:YAT',
        'KHA:YAT',
        'TRJ:YAT'
    ],
];


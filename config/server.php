<?php

return [
    'host' => '0.0.0.0',
    'port' => 2345,
    'worker_count' => 4, // 多进程模式（BusinessWorker 进程数）
    'sqlite' => [
        'db_path' => __DIR__ . '/../storage/shared.db',
        'prefix' => 'qiuqiu:',
    ],
    'game' => [
        'max_players' => 50,
        'min_players' => 1,
        'map_width' => 2000,
        'map_height' => 2000,
        'food_count' => 100,
        'respawn_time' => 30,
        'player_start_size' => 30,
        'player_speed' => 5,
        'food_size_min' => 5,
        'food_size_max' => 15,
    ]
];


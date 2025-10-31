<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\GameServer;
use PfinalClub\AsyncioGamekit\Logger\LoggerFactory;
use PfinalClub\AsyncioGamekit\Logger\LogLevel;

// 加载配置
$config = require __DIR__ . '/config/server.php';

// 配置日志
LoggerFactory::configure([
    'min_level' => LogLevel::INFO,
    'console' => ['enabled' => true, 'color' => true],
    'file' => [
        'enabled' => true,
        'path' => __DIR__ . '/logs/game.log',
        'max_size' => 10 * 1024 * 1024, // 10MB
    ],
]);

LoggerFactory::info('Starting QiuQiu Game Server');

// 创建并启动服务器
$server = new GameServer($config);
$server->run();


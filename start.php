<?php

// 抑制第三方库的 Deprecated 警告（GatewayWorker 在 PHP 8.4 下的兼容性问题）
error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/vendor/autoload.php';

use PfinalClub\AsyncioGamekit\Logger\LoggerFactory;
use PfinalClub\AsyncioGamekit\Logger\LogLevel;
use Workerman\Worker;

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

// 启动 GatewayWorker（固定使用 GW 架构）
LoggerFactory::info('Starting QiuQiu GatewayWorker Server');

// 1) Register（服务注册）
$register = new \GatewayWorker\Register('text://0.0.0.0:1238');

// 2) Gateway（对外 WebSocket，统一接收连接）
$gateway = new \GatewayWorker\Gateway('websocket://' . ($config['host'] ?? '0.0.0.0') . ':' . ($config['port'] ?? 2345));
$gateway->name = 'QiuQiuGateway';
$gateway->count = 1; // 单网关进程即可
$gateway->lanIp = '127.0.0.1';
$gateway->startPort = 2900; // 内部通讯起始端口
$gateway->pingInterval = 25;
$gateway->pingNotResponseLimit = 2;
$gateway->pingData = "\n";
$gateway->registerAddress = '127.0.0.1:1238';

// 将所有客户端消息路由到同一个 BusinessWorker（实现“真正单房间”）
$gateway->router = function($worker_connections, $client_connection, $cmd, $buffer) {
    // 必须返回 BusinessWorker 的连接对象，而非键名
    foreach ($worker_connections as $connection) {
        return $connection;
    }
    return null;
};

// 3) BusinessWorker（业务进程，处理游戏逻辑）
$bw = new \GatewayWorker\BusinessWorker();
$bw->name = 'QiuQiuBW';
$bw->count = $config['worker_count'] ?? 4; // 多进程业务
$bw->registerAddress = '127.0.0.1:1238';
$bw->eventHandler = App\Events::class;

// 运行所有服务
Worker::runAll();


#!/usr/bin/env php
<?php
declare(strict_types=1);

define('APP_PATH', dirname(__DIR__) . '/app');
define('ROOT_PATH', dirname(__DIR__));
define('RUNTIME_PATH', ROOT_PATH . '/runtime');

require ROOT_PATH . '/vendor/autoload.php';

use think\App;
use app\swoole\WebSocketServer;

$app = new App();
$app->initialize();

echo "========================================\n";
echo "  Swoole WebSocket Server\n";
echo "========================================\n";
echo "  Host: " . config_get('swoole.host', '0.0.0.0') . "\n";
echo "  Port: " . config_get('swoole.port', 9501) . "\n";
echo "  Worker Num: " . config_get('swoole.worker_num', 4) . "\n";
echo "========================================\n";

try {
    $server = new WebSocketServer();
    $server->start();
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

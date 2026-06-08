<?php
/**
 * Cambio WebSocket Server
 * 
 * Usage: php bin/websocket-server.php [port]
 * Default port: 8080
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Cambio\Websocket\Server;
use Cambio\Storage\Database;

$port = $argv[1] ?? 8080;

echo "Starting Cambio WebSocket Server on port {$port}...\n";

$db = new Database();
$gameServer = new Server($db);

$server = IoServer::factory(
    new HttpServer(
        new WsServer($gameServer)
    ),
    $port
);

echo "WebSocket Server running on ws://0.0.0.0:{$port}\n";
echo "Press Ctrl+C to stop\n";

$server->run();

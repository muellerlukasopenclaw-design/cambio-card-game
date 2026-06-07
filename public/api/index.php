<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Cambio\Storage\Database;
use Cambio\Api\LobbyController;
use Cambio\Api\GameController;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Error handling
set_error_handler(function ($severity, $message, $file, $line) {
    error_log("PHP Error: $message in $file:$line");
    return true;
});

set_exception_handler(function ($e) {
    error_log("Exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal Server Error']);
    exit;
});

try {
    $db = new Database();
    $db->initSchema();
    
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($requestUri, PHP_URL_PATH);
    $path = str_replace('/api', '', $path);
    
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    $lobbyController = new LobbyController($db);
    $gameController = new GameController($db);
    
    // Rate limiting (simple in-memory)
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateKey = 'rate_' . md5($clientIp . $path);
    $rateFile = sys_get_temp_dir() . '/' . $rateKey;
    $rateLimit = 60; // requests per minute
    
    $rateData = @json_decode(@file_get_contents($rateFile) ?: '{}', true);
    $now = time();
    if (($rateData['time'] ?? 0) < $now - 60) {
        $rateData = ['time' => $now, 'count' => 0];
    }
    $rateData['count'] = ($rateData['count'] ?? 0) + 1;
    @file_put_contents($rateFile, json_encode($rateData));
    
    if ($rateData['count'] > $rateLimit) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Rate limit exceeded']);
        exit;
    }
    
    $response = null;
    
    switch ($path) {
        case '/health':
            $response = ['success' => true, 'status' => 'ok', 'time' => time()];
            break;
            
        case '/lobby/create':
            if ($method !== 'POST') {
                http_response_code(405);
                $response = ['success' => false, 'error' => 'Method not allowed'];
                break;
            }
            $response = $lobbyController->create($input);
            break;
            
        case '/lobby/join':
            if ($method !== 'POST') {
                http_response_code(405);
                $response = ['success' => false, 'error' => 'Method not allowed'];
                break;
            }
            $response = $lobbyController->join($input);
            break;
            
        case '/lobby/state':
            $lobbyId = $input['lobbyId'] ?? $_GET['lobbyId'] ?? '';
            $token = $input['token'] ?? $_GET['token'] ?? '';
            if (!$lobbyId || !$token) {
                $response = ['success' => false, 'error' => 'Lobby ID und Token erforderlich'];
                break;
            }
            $response = $lobbyController->getState($lobbyId, hash('sha256', $token));
            break;
            
        case '/lobby/ready':
            if ($method !== 'POST') {
                http_response_code(405);
                $response = ['success' => false, 'error' => 'Method not allowed'];
                break;
            }
            $response = $lobbyController->setReady(
                $input['lobbyId'] ?? '',
                $input['playerId'] ?? '',
                $input['ready'] ?? false
            );
            break;
            
        case '/lobby/leave':
            if ($method !== 'POST') {
                http_response_code(405);
                $response = ['success' => false, 'error' => 'Method not allowed'];
                break;
            }
            $response = $lobbyController->leave(
                $input['lobbyId'] ?? '',
                $input['playerId'] ?? ''
            );
            break;
            
        case '/lobby/add-bot':
            if ($method !== 'POST') {
                http_response_code(405);
                $response = ['success' => false, 'error' => 'Method not allowed'];
                break;
            }
            $response = $lobbyController->addBot(
                $input['lobbyId'] ?? '',
                $input['difficulty'] ?? 'medium'
            );
            break;
            
        case '/lobby/remove-bot':
            if ($method !== 'POST') {
                http_response_code(405);
                $response = ['success' => false, 'error' => 'Method not allowed'];
                break;
            }
            $response = $lobbyController->removeBot(
                $input['lobbyId'] ?? '',
                $input['botId'] ?? ''
            );
            break;
            
        case '/lobby/start':
            if ($method !== 'POST') {
                http_response_code(405);
                $response = ['success' => false, 'error' => 'Method not allowed'];
                break;
            }
            $startResult = $lobbyController->startGame(
                $input['lobbyId'] ?? '',
                $input['playerId'] ?? ''
            );
            
            if ($startResult['success']) {
                $config = $input['config'] ?? [];
                $gameResult = $gameController->createGame(
                    $input['lobbyId'] ?? '',
                    $startResult['players'],
                    $config
                );
                $response = $gameResult;
            } else {
                $response = $startResult;
            }
            break;
            
        case '/game/state':
            $gameId = $input['gameId'] ?? $_GET['gameId'] ?? '';
            $playerId = $input['playerId'] ?? $_GET['playerId'] ?? '';
            if (!$gameId) {
                $response = ['success' => false, 'error' => 'Game ID erforderlich'];
                break;
            }
            $response = $gameController->getState($gameId, $playerId);
            break;
            
        case '/game/action':
            if ($method !== 'POST') {
                http_response_code(405);
                $response = ['success' => false, 'error' => 'Method not allowed'];
                break;
            }
            $response = $gameController->performAction(
                $input['gameId'] ?? '',
                $input['playerId'] ?? '',
                $input['action'] ?? []
            );
            break;
            
        case '/game/peek':
            if ($method !== 'POST') {
                http_response_code(405);
                $response = ['success' => false, 'error' => 'Method not allowed'];
                break;
            }
            $response = $gameController->performInitialPeek(
                $input['gameId'] ?? '',
                $input['playerId'] ?? '',
                $input['cardIndex'] ?? 0
            );
            break;
            
        case '/game/new-round':
            if ($method !== 'POST') {
                http_response_code(405);
                $response = ['success' => false, 'error' => 'Method not allowed'];
                break;
            }
            $response = $gameController->startNewRound(
                $input['gameId'] ?? '',
                $input['playerId'] ?? ''
            );
            break;
            
        case '/game/stream':
            // Server-Sent Events endpoint
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            
            $gameId = $_GET['gameId'] ?? '';
            $playerId = $_GET['playerId'] ?? '';
            
            if (!$gameId) {
                echo "event: error\ndata: " . json_encode(['error' => 'Game ID erforderlich']) . "\n\n";
                exit;
            }
            
            $lastState = null;
            $startTime = time();
            $maxTime = 30; // 30 seconds per connection
            
            while (time() - $startTime < $maxTime) {
                $state = $gameController->getState($gameId, $playerId);
                $stateJson = json_encode($state);
                
                if ($stateJson !== $lastState) {
                    echo "data: " . $stateJson . "\n\n";
                    $lastState = $stateJson;
                    ob_flush();
                    flush();
                }
                
                sleep(1);
            }
            
            echo "event: close\ndata: {}\n\n";
            exit;
            
        default:
            http_response_code(404);
            $response = ['success' => false, 'error' => 'Endpoint nicht gefunden'];
    }
    
    echo json_encode($response ?? ['success' => false, 'error' => 'Unbekannter Fehler']);
    
} catch (Throwable $e) {
    error_log("API Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Serverfehler']);
}

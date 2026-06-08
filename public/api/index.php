<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Cambio\Storage\Database;
use Cambio\Api\LobbyController;
use Cambio\Api\GameController;

header('Content-Type: application/json; charset=utf-8');
$allowedOrigins = ['https://cabo.müller-lukas.de', 'https://cabo.xn--mller-lukas-thb.de', 'http://localhost:8080', 'http://localhost:3000'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self';");

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
    error_log("Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Serverfehler: ' . $e->getMessage()]);
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
    
    // Auto-cleanup expired lobbies
    $lobbyController->cleanupExpired();
    
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
            $version = trim(file_get_contents(__DIR__ . '/../../VERSION') ?: '0.0.0');
            $response = ['success' => true, 'status' => 'ok', 'time' => time(), 'version' => $version];
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
                $input['ready'] ?? false,
                hash('sha256', $input['token'] ?? '')
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
                $input['playerId'] ?? '',
                hash('sha256', $input['token'] ?? '')
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
                $input['playerId'] ?? '',
                hash('sha256', $input['token'] ?? ''),
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
                $input['playerId'] ?? '',
                hash('sha256', $input['token'] ?? ''),
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
                $input['playerId'] ?? '',
                hash('sha256', $input['token'] ?? '')
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
            
        case '/game/create':
            if ($method !== 'POST') {
                http_response_code(405);
                $response = ['success' => false, 'error' => 'Method not allowed'];
                break;
            }
            $response = $gameController->createGame(
                $input['lobbyId'] ?? 'local_' . uniqid(),
                $input['players'] ?? [],
                $input['config'] ?? []
            );
            break;
            
        case '/game/state':
            $gameId = $input['gameId'] ?? $_GET['gameId'] ?? '';
            $playerId = $input['playerId'] ?? $_GET['playerId'] ?? '';
            $token = $input['token'] ?? $_GET['token'] ?? '';
            if (!$gameId) {
                $response = ['success' => false, 'error' => 'Game ID erforderlich'];
                break;
            }
            $response = $gameController->getState($gameId, $playerId, hash('sha256', $token));
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
                $input['action'] ?? [],
                hash('sha256', $input['token'] ?? '')
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
                $input['cardIndex'] ?? 0,
                hash('sha256', $input['token'] ?? '')
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
                $input['playerId'] ?? '',
                hash('sha256', $input['token'] ?? '')
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
    
    // Set HTTP status code based on response
    if (isset($response['success']) && $response['success'] === false) {
        if (isset($response['error'])) {
            $error = $response['error'];
            if (str_contains($error, 'nicht gefunden') || str_contains($error, 'nicht verfügbar')) {
                http_response_code(404);
            } elseif (str_contains($error, 'Token') || str_contains($error, 'Authentifizierung') || str_contains($error, 'Host')) {
                http_response_code(403);
            } elseif (str_contains($error, 'voll') || str_contains($error, 'bereits') || str_contains($error, 'Mindestens')) {
                http_response_code(409);
            } elseif (str_contains($error, 'erford')) {
                http_response_code(400);
            } else {
                http_response_code(400);
            }
        }
    }
    
    echo json_encode($response ?? ['success' => false, 'error' => 'Unbekannter Fehler']);
    
} catch (Throwable $e) {
    error_log("API Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Serverfehler']);
}

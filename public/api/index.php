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

// WebSocket upgrade endpoint
if ($_SERVER['REQUEST_URI'] === '/ws' || $_SERVER['REQUEST_URI'] === '/api/ws') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'WebSocket not available on this server. Use polling instead.',
        'fallback' => true
    ]);
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
    echo json_encode(['success' => false, 'error' => 'Serverfehler']);
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
    
    // Rate limiting with SQLite (thread-safe, persistent)
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $now = time();
    $window = 60; // 1 minute window
    
    // Different limits for different endpoints
    $limits = [
        '/lobby/create' => 5,
        '/lobby/join' => 10,
        '/game/action' => 30,
        'default' => 60
    ];
    $rateLimit = $limits[$path] ?? $limits['default'];
    
    try {
        $pdo = $db->getConnection();
        $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (
            ip TEXT PRIMARY KEY,
            path TEXT,
            count INTEGER,
            window_start INTEGER
        )');
        
        $stmt = $pdo->prepare('SELECT count, window_start FROM rate_limits WHERE ip = ? AND path = ?');
        $stmt->execute([$clientIp, $path]);
        $row = $stmt->fetch();
        
        if ($row && $row['window_start'] > $now - $window) {
            $count = $row['count'] + 1;
            if ($count > $rateLimit) {
                http_response_code(429);
                echo json_encode(['success' => false, 'error' => 'Rate limit exceeded']);
                exit;
            }
            $stmt = $pdo->prepare('UPDATE rate_limits SET count = ? WHERE ip = ? AND path = ?');
            $stmt->execute([$count, $clientIp, $path]);
        } else {
            $stmt = $pdo->prepare('INSERT OR REPLACE INTO rate_limits (ip, path, count, window_start) VALUES (?, ?, 1, ?)');
            $stmt->execute([$clientIp, $path, $now]);
        }
    } catch (\Exception $e) {
        // If rate limiting fails, allow the request
        error_log('Rate limiting error: ' . $e->getMessage());
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
            if (!$lobbyId) {
                $response = ['success' => false, 'error' => 'Lobby ID erforderlich'];
                break;
            }
            // Token optional for spectator mode
            $tokenHash = $token ? hash('sha256', $token) : '';
            $response = $lobbyController->getState($lobbyId, $tokenHash);
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
                    $config,
                    $startResult['gameId'] ?? null
                );
                
                // Atomar: Lobby auf playing setzen + game_id speichern
                if ($gameResult['success']) {
                    $db->getConnection()->exec('BEGIN IMMEDIATE');
                    try {
                        $stmt = $db->getConnection()->prepare('UPDATE lobbies SET status = ?, game_id = ?, updated_at = ? WHERE id = ?');
                        $stmt->execute(['playing', $gameResult['gameId'], time(), $input['lobbyId'] ?? '']);
                        $db->getConnection()->exec('COMMIT');
                    } catch (\Exception $e) {
                        $db->getConnection()->exec('ROLLBACK');
                        error_log('Lobby update failed: ' . $e->getMessage());
                    }
                }
                
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
            // Server-Sent Events endpoint — disabled due to security concerns
            // Token validation required but not implemented for SSE
            http_response_code(501);
            $response = ['success' => false, 'error' => 'SSE endpoint not implemented'];
            break;
            
        default:
            http_response_code(404);
            $response = ['success' => false, 'error' => 'Endpoint nicht gefunden'];
    }
    
    // Set HTTP status code based on response
    if (isset($response['success']) && $response['success'] === false) {
        // Use structured httpStatus from controller if available
        if (isset($response['httpStatus']) && is_int($response['httpStatus'])) {
            http_response_code($response['httpStatus']);
        } elseif (isset($response['error'])) {
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

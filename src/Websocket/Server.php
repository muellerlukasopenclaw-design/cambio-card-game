<?php
declare(strict_types=1);

namespace Cambio\Websocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Cambio\Storage\Database;
use Cambio\Api\GameController;
use Cambio\Api\LobbyController;

/**
 * WebSocket Server for real-time game updates
 * Implements Ratchet's MessageComponentInterface
 */
class Server implements MessageComponentInterface {
    private Database $db;
    private GameController $gameController;
    private LobbyController $lobbyController;
    
    /** @var \SplObjectStorage<ConnectionInterface> */
    private \SplObjectStorage $clients;
    
    /** @var array<string, string> */
    private array $playerConnections = [];
    
    public function __construct(Database $db) {
        $this->db = $db;
        $this->gameController = new GameController($db);
        $this->lobbyController = new LobbyController($db);
        $this->clients = new \SplObjectStorage();
    }
    
    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New connection: {$conn->resourceId}\n";
    }
    
    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        unset($this->playerConnections[$conn->resourceId]);
        echo "Connection closed: {$conn->resourceId}\n";
    }
    
    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }
    
    public function onMessage(ConnectionInterface $from, $msg) {
        $this->onMessage($from->resourceId, $msg);
    }
    
    /**
     * Broadcast game state update to all connected players in a game
     */
    public function broadcastGameState(string $gameId, array $state): void {
        $message = json_encode([
            'type' => 'game_state',
            'gameId' => $gameId,
            'state' => $state
        ]);
        
        foreach ($this->clients as $clientId => $client) {
            if (isset($this->playerConnections[$clientId]) && 
                $this->playerConnections[$clientId] === $gameId) {
                $this->send($client, $message);
            }
        }
    }
    
    /**
     * Broadcast lobby update to all connected players in a lobby
     */
    public function broadcastLobbyState(string $lobbyId, array $state): void {
        $message = json_encode([
            'type' => 'lobby_state',
            'lobbyId' => $lobbyId,
            'state' => $state
        ]);
        
        foreach ($this->clients as $clientId => $client) {
            if (isset($this->playerConnections[$clientId]) && 
                $this->playerConnections[$clientId] === $lobbyId) {
                $this->send($client, $message);
            }
        }
    }
    
    /**
     * Send chat message to specific player or broadcast
     */
    public function sendChatMessage(string $from, string $to, string $message): void {
        $payload = json_encode([
            'type' => 'chat',
            'from' => $from,
            'message' => $message,
            'timestamp' => time()
        ]);
        
        if ($to === 'all') {
            foreach ($this->clients as $client) {
                $this->send($client, $payload);
            }
        } else {
            // Send to specific player
            foreach ($this->clients as $clientId => $client) {
                if (isset($this->playerConnections[$clientId]) && 
                    $this->playerConnections[$clientId] === $to) {
                    $this->send($client, $payload);
                }
            }
        }
    }
    
    /**
     * Send reaction to specific player
     */
    public function sendReaction(string $from, string $to, string $emoji): void {
        $payload = json_encode([
            'type' => 'reaction',
            'from' => $from,
            'emoji' => $emoji,
            'timestamp' => time()
        ]);
        
        foreach ($this->clients as $clientId => $client) {
            if (isset($this->playerConnections[$clientId]) && 
                $this->playerConnections[$clientId] === $to) {
                $this->send($client, $payload);
            }
        }
    }
    
    /**
     * Register a new client connection
     */
    public function onConnect(string $clientId, $client): void {
        $this->clients[$clientId] = $client;
    }
    
    /**
     * Handle client disconnection
     */
    public function onDisconnect(string $clientId): void {
        unset($this->clients[$clientId]);
        unset($this->playerConnections[$clientId]);
    }
    
    /**
     * Handle incoming message from client
     */
    public function onMessage(string $clientId, string $message): void {
        try {
            $data = json_decode($message, true);
            if (!$data) return;
            
            switch ($data['type'] ?? '') {
                case 'join_game':
                    $this->playerConnections[$clientId] = $data['gameId'];
                    break;
                    
                case 'join_lobby':
                    $this->playerConnections[$clientId] = $data['lobbyId'];
                    break;
                    
                case 'action':
                    // Handle game action via WebSocket
                    $this->handleAction($data);
                    break;
                    
                case 'chat':
                    $this->sendChatMessage(
                        $data['from'] ?? '',
                        $data['to'] ?? 'all',
                        $data['message'] ?? ''
                    );
                    break;
                    
                case 'reaction':
                    $this->sendReaction(
                        $data['from'] ?? '',
                        $data['to'] ?? '',
                        $data['emoji'] ?? ''
                    );
                    break;
            }
        } catch (\Exception $e) {
            error_log('WebSocket error: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle game action via WebSocket
     */
    private function handleAction(array $data): void {
        $gameId = $data['gameId'] ?? '';
        $playerId = $data['playerId'] ?? '';
        $action = $data['action'] ?? [];
        $token = $data['token'] ?? '';
        
        $result = $this->gameController->performAction(
            $gameId,
            $playerId,
            $action,
            hash('sha256', $token)
        );
        
        if ($result['success']) {
            // Broadcast updated state to all players
            $this->broadcastGameState($gameId, $result['state'] ?? []);
        }
    }
    
    /**
     * Send message to client
     */
    private function send($client, string $message): void {
        // Implementation depends on WebSocket library
        // For Ratchet: $client->send($message);
        // For Swoole: $client->push($message);
    }
    
    /**
     * Get connected client count
     */
    public function getClientCount(): int {
        return count($this->clients);
    }
    
    /**
     * Get clients in a specific game/lobby
     */
    public function getClientsInRoom(string $roomId): array {
        $clients = [];
        foreach ($this->playerConnections as $clientId => $room) {
            if ($room === $roomId) {
                $clients[] = $clientId;
            }
        }
        return $clients;
    }
}

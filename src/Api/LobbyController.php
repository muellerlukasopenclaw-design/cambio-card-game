<?php
declare(strict_types=1);

namespace Cambio\Api;

use Cambio\Storage\Database;
use Cambio\Game\Player;

class LobbyController {
    private Database $db;
    private int $lobbyExpiryHours;

    public function __construct(Database $db, int $lobbyExpiryHours = 24) {
        $this->db = $db;
        $this->lobbyExpiryHours = $lobbyExpiryHours;
    }

    public function create(array $data): array {
        $name = $data['name'] ?? 'Cambio-Runde';
        $hostName = $data['hostName'] ?? 'Host';
        $maxPlayers = min(5, max(2, (int)($data['maxPlayers'] ?? 4)));
        
        $lobbyId = uniqid('lobby_', true);
        $code = $this->generateCode();
        $hostId = uniqid('p_', true);
        $sessionToken = bin2hex(random_bytes(16));
        $tokenHash = hash('sha256', $sessionToken);
        $now = time();
        $expires = $now + ($this->lobbyExpiryHours * 3600);
        
        $pdo = $this->db->getConnection();
        
        $pdo->prepare('
            INSERT INTO lobbies (id, code, name, host_id, max_players, status, config, created_at, updated_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([$lobbyId, $code, $name, $hostId, $maxPlayers, 'waiting', null, $now, $now, $expires]);
        
        $pdo->prepare('
            INSERT INTO players (id, lobby_id, name, is_bot, is_host, ready, session_token, token_hash, joined_at)
            VALUES (?, ?, ?, 0, 1, 0, ?, ?, ?)
        ')->execute([$hostId, $lobbyId, $hostName, $sessionToken, $tokenHash, $now]);
        
        return [
            'success' => true,
            'lobbyId' => $lobbyId,
            'code' => $code,
            'playerId' => $hostId,
            'sessionToken' => $sessionToken,
            'expiresAt' => $expires
        ];
    }

    public function join(array $data): array {
        $code = strtoupper($data['code'] ?? '');
        $playerName = $data['name'] ?? 'Spieler';
        
        if (strlen($code) !== 6) {
            return ['success' => false, 'error' => 'Ungültiger Lobby-Code'];
        }
        
        $pdo = $this->db->getConnection();
        
        $stmt = $pdo->prepare('SELECT * FROM lobbies WHERE code = ? AND expires_at > ?');
        $stmt->execute([$code, time()]);
        $lobby = $stmt->fetch();
        
        if (!$lobby) {
            return ['success' => false, 'error' => 'Lobby nicht gefunden oder abgelaufen'];
        }
        
        if ($lobby['status'] !== 'waiting') {
            return ['success' => false, 'error' => 'Spiel läuft bereits'];
        }
        
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM players WHERE lobby_id = ?');
        $stmt->execute([$lobby['id']]);
        $playerCount = $stmt->fetchColumn();
        
        if ($playerCount >= $lobby['max_players']) {
            return ['success' => false, 'error' => 'Lobby ist voll'];
        }
        
        $playerId = uniqid('p_', true);
        $sessionToken = bin2hex(random_bytes(16));
        $tokenHash = hash('sha256', $sessionToken);
        $now = time();
        
        $pdo->prepare('
            INSERT INTO players (id, lobby_id, name, is_bot, is_host, ready, session_token, token_hash, joined_at)
            VALUES (?, ?, ?, 0, 0, 0, ?, ?, ?)
        ')->execute([$playerId, $lobby['id'], $playerName, $sessionToken, $tokenHash, $now]);
        
        $pdo->prepare('UPDATE lobbies SET updated_at = ? WHERE id = ?')
            ->execute([$now, $lobby['id']]);
        
        return [
            'success' => true,
            'lobbyId' => $lobby['id'],
            'playerId' => $playerId,
            'sessionToken' => $sessionToken,
            'isHost' => false
        ];
    }

    public function getState(string $lobbyId, string $tokenHash): array {
        $pdo = $this->db->getConnection();
        
        $stmt = $pdo->prepare('SELECT * FROM lobbies WHERE id = ?');
        $stmt->execute([$lobbyId]);
        $lobby = $stmt->fetch();
        
        if (!$lobby) {
            return ['success' => false, 'error' => 'Lobby nicht gefunden'];
        }
        
        $stmt = $pdo->prepare('
            SELECT id, name, is_bot, bot_difficulty, is_host, ready
            FROM players WHERE lobby_id = ?
        ');
        $stmt->execute([$lobbyId]);
        $players = $stmt->fetchAll();
        
        $stmt = $pdo->prepare('SELECT id, is_host FROM players WHERE lobby_id = ? AND token_hash = ?');
        $stmt->execute([$lobbyId, $tokenHash]);
        $currentPlayer = $stmt->fetch();
        
        return [
            'success' => true,
            'lobby' => [
                'id' => $lobby['id'],
                'code' => $lobby['code'],
                'name' => $lobby['name'],
                'status' => $lobby['status'],
                'maxPlayers' => $lobby['max_players'],
                'gameId' => $lobby['game_id'] ?? null,
                'players' => $players,
                'isHost' => $currentPlayer ? (bool)$currentPlayer['is_host'] : false,
                'playerId' => $currentPlayer['id'] ?? null
            ]
        ];
    }

    public function setReady(string $lobbyId, string $playerId, bool $ready): array {
        $pdo = $this->db->getConnection();
        
        $pdo->prepare('UPDATE players SET ready = ? WHERE id = ? AND lobby_id = ?')
            ->execute([$ready ? 1 : 0, $playerId, $lobbyId]);
        
        $pdo->prepare('UPDATE lobbies SET updated_at = ? WHERE id = ?')
            ->execute([time(), $lobbyId]);
        
        return ['success' => true];
    }

    public function leave(string $lobbyId, string $playerId): array {
        $pdo = $this->db->getConnection();
        
        $pdo->prepare('DELETE FROM players WHERE id = ? AND lobby_id = ?')
            ->execute([$playerId, $lobbyId]);
        
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM players WHERE lobby_id = ?');
        $stmt->execute([$lobbyId]);
        $remaining = $stmt->fetchColumn();
        
        if ($remaining == 0) {
            $pdo->prepare('DELETE FROM lobbies WHERE id = ?')
                ->execute([$lobbyId]);
        }
        
        return ['success' => true];
    }

    public function addBot(string $lobbyId, string $difficulty = 'medium'): array {
        $pdo = $this->db->getConnection();
        
        $stmt = $pdo->prepare('SELECT * FROM lobbies WHERE id = ?');
        $stmt->execute([$lobbyId]);
        $lobby = $stmt->fetch();
        
        if (!$lobby || $lobby['status'] !== 'waiting') {
            return ['success' => false, 'error' => 'Lobby nicht verfügbar'];
        }
        
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM players WHERE lobby_id = ?');
        $stmt->execute([$lobbyId]);
        $playerCount = $stmt->fetchColumn();
        
        if ($playerCount >= $lobby['max_players']) {
            return ['success' => false, 'error' => 'Lobby ist voll'];
        }
        
        $botNames = ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'];
        $botName = $botNames[$playerCount] ?? 'Bot ' . ($playerCount + 1);
        
        $botId = uniqid('bot_', true);
        $now = time();
        
        $pdo->prepare('
            INSERT INTO players (id, lobby_id, name, is_bot, bot_difficulty, is_host, ready, joined_at)
            VALUES (?, ?, ?, 1, ?, 0, 1, ?)
        ')->execute([$botId, $lobbyId, $botName, $difficulty, $now]);
        
        return ['success' => true, 'botId' => $botId, 'name' => $botName];
    }

    public function removeBot(string $lobbyId, string $botId): array {
        $pdo = $this->db->getConnection();
        
        $pdo->prepare('DELETE FROM players WHERE id = ? AND lobby_id = ? AND is_bot = 1')
            ->execute([$botId, $lobbyId]);
        
        return ['success' => true];
    }

    public function startGame(string $lobbyId, string $playerId): array {
        $pdo = $this->db->getConnection();
        
        $stmt = $pdo->prepare('SELECT * FROM lobbies WHERE id = ?');
        $stmt->execute([$lobbyId]);
        $lobby = $stmt->fetch();
        
        if (!$lobby) {
            return ['success' => false, 'error' => 'Lobby nicht gefunden'];
        }
        
        if ($lobby['host_id'] !== $playerId) {
            return ['success' => false, 'error' => 'Nur der Host kann das Spiel starten'];
        }
        
        $stmt = $pdo->prepare('
            SELECT id, name, is_bot, bot_difficulty
            FROM players WHERE lobby_id = ?
        ');
        $stmt->execute([$lobbyId]);
        $players = $stmt->fetchAll();
        
        if (count($players) < 2) {
            return ['success' => false, 'error' => 'Mindestens 2 Spieler benötigt'];
        }
        
        $gameId = 'game_' . uniqid('', true);
        $pdo->prepare('UPDATE lobbies SET status = ?, game_id = ?, updated_at = ? WHERE id = ?')
            ->execute(['playing', $gameId, time(), $lobbyId]);
        
        return ['success' => true, 'gameId' => $gameId, 'players' => $players];
    }

    public function cleanupExpired(): int {
        $pdo = $this->db->getConnection();
        
        $stmt = $pdo->prepare('DELETE FROM lobbies WHERE expires_at < ?');
        $stmt->execute([time()]);
        
        return $stmt->rowCount();
    }

    private function generateCode(): string {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            
            $stmt = $this->db->getConnection()->prepare('SELECT 1 FROM lobbies WHERE code = ?');
            $stmt->execute([$code]);
            $exists = $stmt->fetch();
        } while ($exists);
        
        return $code;
    }
}

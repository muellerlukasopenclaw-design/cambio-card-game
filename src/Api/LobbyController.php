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
        
        $lobbyId = 'lobby_' . bin2hex(random_bytes(16));
        $code = $this->generateCode();
        $hostId = 'p_' . bin2hex(random_bytes(16));
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
        
        $playerId = 'p_' . bin2hex(random_bytes(16));
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

    public function setReady(string $lobbyId, string $playerId, bool $ready, string $tokenHash): array {
        $pdo = $this->db->getConnection();
        
        // Verify token (mandatory)
        if (!$tokenHash) {
            return ['success' => false, 'error' => 'Token erforderlich'];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM players WHERE id = ? AND lobby_id = ? AND token_hash = ?');
        $stmt->execute([$playerId, $lobbyId, $tokenHash]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Ungültiges Token'];
        }
        
        $pdo->prepare('UPDATE players SET ready = ? WHERE id = ? AND lobby_id = ?')
            ->execute([$ready ? 1 : 0, $playerId, $lobbyId]);
        
        $pdo->prepare('UPDATE lobbies SET updated_at = ? WHERE id = ?')
            ->execute([time(), $lobbyId]);
        
        return ['success' => true];
    }

    public function leave(string $lobbyId, string $playerId, string $tokenHash): array {
        $pdo = $this->db->getConnection();
        
        // Verify token (mandatory)
        if (!$tokenHash) {
            return ['success' => false, 'error' => 'Token erforderlich'];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM players WHERE id = ? AND lobby_id = ? AND token_hash = ?');
        $stmt->execute([$playerId, $lobbyId, $tokenHash]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Ungültiges Token'];
        }
        
        // Check if player is host and transfer host role
        $stmt = $pdo->prepare('SELECT is_host FROM players WHERE id = ? AND lobby_id = ?');
        $stmt->execute([$playerId, $lobbyId]);
        $player = $stmt->fetch();
        
        $pdo->prepare('DELETE FROM players WHERE id = ? AND lobby_id = ?')
            ->execute([$playerId, $lobbyId]);
        
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM players WHERE lobby_id = ?');
        $stmt->execute([$lobbyId]);
        $remaining = $stmt->fetchColumn();
        
        if ($remaining == 0) {
            $pdo->prepare('DELETE FROM lobbies WHERE id = ?')
                ->execute([$lobbyId]);
        } elseif ($player && $player['is_host']) {
            // Transfer host to oldest human player
            $stmt = $pdo->prepare('SELECT id FROM players WHERE lobby_id = ? AND is_bot = 0 ORDER BY joined_at LIMIT 1');
            $stmt->execute([$lobbyId]);
            $newHost = $stmt->fetch();
            if ($newHost) {
                $pdo->prepare('UPDATE players SET is_host = 1 WHERE id = ?')->execute([$newHost['id']]);
                $pdo->prepare('UPDATE lobbies SET host_id = ? WHERE id = ?')->execute([$newHost['id'], $lobbyId]);
            }
        }
        
        return ['success' => true];
    }

    public function addBot(string $lobbyId, string $playerId, string $tokenHash = '', string $difficulty = 'medium'): array {
        $pdo = $this->db->getConnection();
        
        // Verify host
        $stmt = $pdo->prepare('SELECT is_host FROM players WHERE id = ? AND lobby_id = ? AND token_hash = ?');
        $stmt->execute([$playerId, $lobbyId, $tokenHash]);
        $player = $stmt->fetch();
        if (!$player || !$player['is_host']) {
            return ['success' => false, 'error' => 'Nur der Host kann Bots hinzufügen'];
        }
        
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
        
        $botId = 'bot_' . bin2hex(random_bytes(16));
        $now = time();
        
        $pdo->prepare('
            INSERT INTO players (id, lobby_id, name, is_bot, bot_difficulty, is_host, ready, joined_at)
            VALUES (?, ?, ?, 1, ?, 0, 1, ?)
        ')->execute([$botId, $lobbyId, $botName, $difficulty, $now]);
        
        return ['success' => true, 'botId' => $botId, 'name' => $botName];
    }

    public function removeBot(string $lobbyId, string $playerId, string $tokenHash, string $botId): array {
        $pdo = $this->db->getConnection();
        
        // Verify host
        $stmt = $pdo->prepare('SELECT is_host FROM players WHERE id = ? AND lobby_id = ? AND token_hash = ?');
        $stmt->execute([$playerId, $lobbyId, $tokenHash]);
        $player = $stmt->fetch();
        if (!$player || !$player['is_host']) {
            return ['success' => false, 'error' => 'Nur der Host kann Bots entfernen'];
        }
        
        $pdo->prepare('DELETE FROM players WHERE id = ? AND lobby_id = ? AND is_bot = 1')
            ->execute([$botId, $lobbyId]);
        
        return ['success' => true];
    }

    public function startGame(string $lobbyId, string $playerId, string $tokenHash = ''): array {
        $pdo = $this->db->getConnection();
        
        // Verify token and host
        $stmt = $pdo->prepare('SELECT is_host FROM players WHERE id = ? AND lobby_id = ? AND token_hash = ?');
        $stmt->execute([$playerId, $lobbyId, $tokenHash]);
        $player = $stmt->fetch();
        if (!$player || !$player['is_host']) {
            return ['success' => false, 'error' => 'Nur der Host kann das Spiel starten'];
        }
        
        $stmt = $pdo->prepare('SELECT * FROM lobbies WHERE id = ?');
        $stmt->execute([$lobbyId]);
        $lobby = $stmt->fetch();
        
        if (!$lobby) {
            return ['success' => false, 'error' => 'Lobby nicht gefunden'];
        }
        
        // Check all human players are ready
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM players WHERE lobby_id = ? AND is_bot = 0 AND ready = 0');
        $stmt->execute([$lobbyId]);
        $notReady = $stmt->fetchColumn();
        if ($notReady > 0) {
            return ['success' => false, 'error' => 'Nicht alle Spieler sind bereit'];
        }
        
        $stmt = $pdo->prepare('
            SELECT id, name, is_bot, bot_difficulty, is_host
            FROM players WHERE lobby_id = ?
        ');
        $stmt->execute([$lobbyId]);
        $players = $stmt->fetchAll();
        
        if (count($players) < 2) {
            return ['success' => false, 'error' => 'Mindestens 2 Spieler benötigt'];
        }
        
        $gameId = 'game_' . bin2hex(random_bytes(16));
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

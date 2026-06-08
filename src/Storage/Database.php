<?php
declare(strict_types=1);

namespace Cambio\Storage;

use PDO;
use PDOException;

class Database {
    private static ?PDO $instance = null;
    private string $dbPath;

    public function __construct(string $dbPath = __DIR__ . '/../../data/cambio.db') {
        $this->dbPath = $dbPath;
    }

    public function getConnection(): PDO {
        if (self::$instance === null) {
            $dir = dirname($this->dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            self::$instance = new PDO('sqlite:' . $this->dbPath);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$instance->exec('PRAGMA foreign_keys = ON');
            self::$instance->exec('PRAGMA journal_mode = WAL');
        }
        
        return self::$instance;
    }

    public function initSchema(): void {
        $db = $this->getConnection();
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS lobbies (
                id TEXT PRIMARY KEY,
                code TEXT UNIQUE NOT NULL,
                name TEXT NOT NULL,
                host_id TEXT NOT NULL,
                max_players INTEGER NOT NULL DEFAULT 5,
                status TEXT NOT NULL DEFAULT "waiting",
                game_id TEXT,
                config TEXT,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS players (
                id TEXT PRIMARY KEY,
                lobby_id TEXT NOT NULL,
                name TEXT NOT NULL,
                is_bot INTEGER NOT NULL DEFAULT 0,
                bot_difficulty TEXT,
                is_host INTEGER NOT NULL DEFAULT 0,
                ready INTEGER NOT NULL DEFAULT 0,
                token_hash TEXT,
                joined_at INTEGER NOT NULL,
                FOREIGN KEY (lobby_id) REFERENCES lobbies(id) ON DELETE CASCADE
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS games (
                id TEXT PRIMARY KEY,
                lobby_id TEXT NOT NULL,
                state TEXT NOT NULL,
                phase TEXT NOT NULL DEFAULT "setup",
                round INTEGER NOT NULL DEFAULT 1,
                current_player_index INTEGER NOT NULL DEFAULT 0,
                config TEXT,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )
        ');
        
        $db->exec('
            CREATE TABLE IF NOT EXISTS game_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                game_id TEXT NOT NULL,
                player_id TEXT,
                action TEXT NOT NULL,
                data TEXT,
                created_at INTEGER NOT NULL,
                FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
            )
        ');
        
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_lobbies_code ON lobbies(code)');
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_lobbies_expires ON lobbies(expires_at)');
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_players_lobby ON players(lobby_id)');
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_players_token ON players(token_hash)');
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_games_lobby ON games(lobby_id)');
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_events_game ON game_events(game_id)');
    }

    public function close(): void {
        self::$instance = null;
    }
}

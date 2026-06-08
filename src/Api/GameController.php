<?php
declare(strict_types=1);

namespace Cambio\Api;

use Cambio\Game\GameState;
use Cambio\Game\Player;
use Cambio\Game\BotEngine;
use Cambio\Storage\Database;

class GameController {
    private Database $db;
    private array $games = [];

    public function __construct(Database $db) {
        $this->db = $db;
    }

    public function createGame(string $lobbyId, array $playersData, array $config = []): array {
        $game = new GameState($config);
        
        foreach ($playersData as $p) {
            $player = new Player(
                $p['name'],
                (bool)($p['is_bot'] ?? false),
                $p['bot_difficulty'] ?? null
            );
            $player->id = $p['id'];
            $player->isHost = (bool)($p['is_host'] ?? false);
            $game->addPlayer($player);
        }
        
        $game->deal();
        
        // Store in memory
        $this->games[$game->id] = $game;
        
        // Store in DB
        $pdo = $this->db->getConnection();
        $now = time();

        $pdo->prepare('
            INSERT INTO games (id, lobby_id, state, phase, round, current_player_index, config, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $game->id,
            $lobbyId,
            json_encode($game->toPersistedArray()),
            $game->phase,
            $game->round,
            $game->currentPlayerIndex,
            json_encode($config),
            $now,
            $now
        ]);
        
        return [
            'success' => true,
            'gameId' => $game->id,
            'state' => $game->toArray()
        ];
    }

    public function getState(string $gameId, string $playerId, string $tokenHash = ''): array {
        $game = $this->getGame($gameId);
        if (!$game) {
            return ['success' => false, 'error' => 'Spiel nicht gefunden'];
        }
        
        // Verify player belongs to game
        $player = $game->getPlayerById($playerId);
        if (!$player) {
            return ['success' => false, 'error' => 'Spieler nicht im Spiel'];
        }
        
        // Verify token if provided
        if ($tokenHash && !$player->isBot) {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare('SELECT 1 FROM players WHERE id = ? AND token_hash = ?');
            $stmt->execute([$playerId, $tokenHash]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'error' => 'Ungültiges Token'];
            }
        }
        
        return [
            'success' => true,
            'state' => $game->toArray($playerId)
        ];
    }

    public function performAction(string $gameId, string $playerId, array $action, string $tokenHash = ''): array {
        $pdo = $this->db->getConnection();
        $pdo->beginTransaction();
        
        try {
            $game = $this->getGame($gameId);
            if (!$game) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Spiel nicht gefunden'];
            }
            
            $player = $game->getPlayerById($playerId);
            if (!$player) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Spieler nicht gefunden'];
            }
            
            // Verify token for non-bot players
            if (!$player->isBot && $tokenHash) {
                $stmt = $pdo->prepare('SELECT 1 FROM players WHERE id = ? AND token_hash = ?');
                $stmt->execute([$playerId, $tokenHash]);
                if (!$stmt->fetch()) {
                    $pdo->rollBack();
                    return ['success' => false, 'error' => 'Ungültiges Token'];
                }
            }
            
            $currentPlayer = $game->getCurrentPlayer();
            if (!$currentPlayer || $currentPlayer->id !== $playerId) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Nicht an der Reihe'];
            }
            
            $actionType = $action['action'] ?? '';
            $result = false;
            
            switch ($actionType) {
                case GameState::ACTION_DRAW_DECK:
                    $result = $game->drawFromDeck($playerId);
                    break;
                    
                case GameState::ACTION_DRAW_DISCARD:
                    $result = $game->takeFromDiscard($playerId);
                    break;
                    
                case GameState::ACTION_CALL_CABO:
                    $result = $game->callCabo($playerId);
                    break;
                    
                case GameState::ACTION_SWAP_WITH_HAND:
                    $result = $game->swapWithHand($playerId, $action['index'] ?? 0);
                    break;
                    
                case GameState::ACTION_DISCARD:
                    $result = $game->discardDrawn($playerId);
                    break;
                    
                case GameState::ACTION_PEEK:
                    $result = $game->performPeek($playerId, $action['index'] ?? 0);
                    break;
                    
                case GameState::ACTION_SPY:
                    $result = $game->performSpy($playerId, $action['targetId'] ?? '', $action['index'] ?? 0);
                    break;
                    
                case GameState::ACTION_SWAP:
                    $result = $game->performSwap(
                        $playerId,
                        $action['ownIndex'] ?? 0,
                        $action['targetId'] ?? '',
                        $action['targetIndex'] ?? 0
                    );
                    break;

                case GameState::ACTION_SKIP:
                    $result = $game->skipAction($playerId);
                    break;

                default:
                    $pdo->rollBack();
                    return ['success' => false, 'error' => 'Unbekannte Aktion'];
            }
            
            if (!$result) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Aktion nicht möglich'];
            }
            
            $this->saveGame($game);
            $this->logEvent($game->id, $playerId, $actionType, $action);
            
            // Process bot turns
            $this->processBotTurns($game);
            
            $pdo->commit();
            
            return [
                'success' => true,
                'state' => $game->toArray($playerId)
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('Game action error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Serverfehler bei der Aktion'];
        }
    }

    public function performInitialPeek(string $gameId, string $playerId, int $cardIndex, string $tokenHash = ''): array {
        $game = $this->getGame($gameId);
        if (!$game) {
            return ['success' => false, 'error' => 'Spiel nicht gefunden'];
        }
        
        $player = $game->getPlayerById($playerId);
        if (!$player) {
            return ['success' => false, 'error' => 'Spieler nicht gefunden'];
        }
        
        // Verify token for non-bot players
        if (!$player->isBot && $tokenHash) {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare('SELECT 1 FROM players WHERE id = ? AND token_hash = ?');
            $stmt->execute([$playerId, $tokenHash]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'error' => 'Ungültiges Token'];
            }
        }
        
        $result = $game->performInitialPeek($playerId, $cardIndex);
        
        if (!$result) {
            return ['success' => false, 'error' => 'Peek nicht möglich'];
        }
        
        $this->saveGame($game);
        
        // Process bot peeks
        while ($game->phase === GameState::PHASE_INITIAL_PEEK) {
            $currentPlayer = $game->getCurrentPlayer();
            if ($currentPlayer && $currentPlayer->isBot) {
                $this->processBotInitialPeek($game);
            } else {
                break;
            }
        }
        
        return [
            'success' => true,
            'state' => $game->toArray($playerId)
        ];
    }

    public function startNewRound(string $gameId, string $playerId, string $tokenHash = ''): array {
        $game = $this->getGame($gameId);
        if (!$game) {
            return ['success' => false, 'error' => 'Spiel nicht gefunden'];
        }
        
        $player = $game->getPlayerById($playerId);
        if (!$player) {
            return ['success' => false, 'error' => 'Spieler nicht gefunden'];
        }
        
        // Verify token for non-bot players
        if (!$player->isBot && $tokenHash) {
            $pdo = $this->db->getConnection();
            $stmt = $pdo->prepare('SELECT 1 FROM players WHERE id = ? AND token_hash = ?');
            $stmt->execute([$playerId, $tokenHash]);
            if (!$stmt->fetch()) {
                return ['success' => false, 'error' => 'Ungültiges Token'];
            }
        }
        
        // Only host can start new round
        if (!$player->isHost) {
            return ['success' => false, 'error' => 'Nur der Host kann eine neue Runde starten'];
        }
        
        if ($game->phase !== GameState::PHASE_ROUND_END) {
            return ['success' => false, 'error' => 'Runde kann nicht gestartet werden'];
        }
        
        $game->startNewRound();
        $this->saveGame($game);
        
        // Process bot initial peeks
        while ($game->phase === GameState::PHASE_INITIAL_PEEK) {
            $currentPlayer = $game->getCurrentPlayer();
            if ($currentPlayer && $currentPlayer->isBot) {
                $this->processBotInitialPeek($game);
            } else {
                break;
            }
        }
        
        return [
            'success' => true,
            'state' => $game->toArray($playerId)
        ];
    }

    private function processBotTurns(GameState $game): void {
        $maxIterations = 50; // Safety limit
        $iterations = 0;
        
        while ($iterations < $maxIterations) {
            $currentPlayer = $game->getCurrentPlayer();
            
            if (!$currentPlayer || !$currentPlayer->isBot) {
                break;
            }
            
            if ($game->phase === GameState::PHASE_GAME_OVER) {
                break;
            }
            
            if ($game->phase === GameState::PHASE_ROUND_END) {
                break;
            }
            
            $bot = new BotEngine($game, $currentPlayer);
            $decision = $bot->decideAction();
            
            $this->executeBotAction($game, $currentPlayer, $decision);
            $this->saveGame($game);
            
            $iterations++;
        }
    }

    private function processBotInitialPeek(GameState $game): void {
        $currentPlayer = $game->getCurrentPlayer();
        if (!$currentPlayer || !$currentPlayer->isBot) {
            return;
        }
        
        // Bot peeks at random cards
        $unknownIndices = [];
        for ($i = 0; $i < 4; $i++) {
            if (!isset($currentPlayer->knownCards[$i])) {
                $unknownIndices[] = $i;
            }
        }
        
        if (!empty($unknownIndices)) {
            $index = $unknownIndices[array_rand($unknownIndices)];
            $game->performInitialPeek($currentPlayer->id, $index);
        } else {
            // Force next player
            $game->nextPlayer();
            if ($game->currentPlayerIndex === 0) {
                $game->phase = GameState::PHASE_PLAYING;
            }
        }
    }

    private function executeBotAction(GameState $game, Player $bot, array $decision): void {
        $action = $decision['action'];
        
        switch ($action) {
            case GameState::ACTION_DRAW_DECK:
                $game->drawFromDeck($bot->id);
                
                // Immediately decide what to do with drawn card
                if ($game->drawnCard) {
                    $botEngine = new BotEngine($game, $bot);
                    $followUp = $botEngine->decideAction();
                    $this->executeBotAction($game, $bot, $followUp);
                }
                break;
                
            case GameState::ACTION_DRAW_DISCARD:
                $game->takeFromDiscard($bot->id);
                
                // Must swap
                if ($game->drawnCard) {
                    $botEngine = new BotEngine($game, $bot);
                    $followUp = $botEngine->decideAction();
                    $this->executeBotAction($game, $bot, $followUp);
                }
                break;
                
            case GameState::ACTION_CALL_CABO:
                $game->callCabo($bot->id);
                break;
                
            case GameState::ACTION_SWAP_WITH_HAND:
                $game->swapWithHand($bot->id, $decision['index'] ?? 0);
                break;
                
            case GameState::ACTION_DISCARD:
                $game->discardDrawn($bot->id);
                
                // If action card pending, handle it
                if ($game->pendingAction) {
                    $botEngine = new BotEngine($game, $bot);
                    $followUp = $botEngine->decideAction();
                    $this->executeBotAction($game, $bot, $followUp);
                }
                break;
                
            case GameState::ACTION_PEEK:
                $game->performPeek($bot->id, $decision['index'] ?? 0);
                break;
                
            case GameState::ACTION_SPY:
                $game->performSpy($bot->id, $decision['targetId'] ?? '', $decision['index'] ?? 0);
                break;
                
            case GameState::ACTION_SWAP:
                $game->performSwap(
                    $bot->id,
                    $decision['ownIndex'] ?? 0,
                    $decision['targetId'] ?? '',
                    $decision['targetIndex'] ?? 0
                );
                break;
        }
    }

    private function getGame(string $gameId): ?GameState {
        // Check memory first
        if (isset($this->games[$gameId])) {
            return $this->games[$gameId];
        }

        // Load from DB
        $pdo = $this->db->getConnection();
        $stmt = $pdo->prepare('SELECT * FROM games WHERE id = ?');
        $stmt->execute([$gameId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        // Reconstruct game state from persisted data
        $state = json_decode($row['state'], true);
        $game = GameState::fromPersistedArray($state);

        $this->games[$gameId] = $game;
        return $game;
    }

    private function saveGame(GameState $game): void {
        $this->games[$game->id] = $game;

        $pdo = $this->db->getConnection();
        $pdo->prepare('
            UPDATE games SET state = ?, phase = ?, round = ?, current_player_index = ?, updated_at = ?
            WHERE id = ?
        ')->execute([
            json_encode($game->toPersistedArray()),
            $game->phase,
            $game->round,
            $game->currentPlayerIndex,
            time(),
            $game->id
        ]);
    }

    private function logEvent(string $gameId, string $playerId, string $action, array $data): void {
        $pdo = $this->db->getConnection();
        $pdo->prepare('
            INSERT INTO game_events (game_id, player_id, action, data, created_at)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([
            $gameId,
            $playerId,
            $action,
            json_encode($data),
            time()
        ]);
    }
}

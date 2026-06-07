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
            json_encode($game->toArray()),
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

    public function getState(string $gameId, string $playerId): array {
        $game = $this->getGame($gameId);
        if (!$game) {
            return ['success' => false, 'error' => 'Spiel nicht gefunden'];
        }
        
        return [
            'success' => true,
            'state' => $game->toArray($playerId)
        ];
    }

    public function performAction(string $gameId, string $playerId, array $action): array {
        $game = $this->getGame($gameId);
        if (!$game) {
            return ['success' => false, 'error' => 'Spiel nicht gefunden'];
        }
        
        $player = $game->getPlayerById($playerId);
        if (!$player) {
            return ['success' => false, 'error' => 'Spieler nicht gefunden'];
        }
        
        $currentPlayer = $game->getCurrentPlayer();
        if (!$currentPlayer || $currentPlayer->id !== $playerId) {
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
                
            default:
                return ['success' => false, 'error' => 'Unbekannte Aktion'];
        }
        
        if (!$result) {
            return ['success' => false, 'error' => 'Aktion nicht möglich'];
        }
        
        $this->saveGame($game);
        $this->logEvent($game->id, $playerId, $actionType, $action);
        
        // Process bot turns
        $this->processBotTurns($game);
        
        return [
            'success' => true,
            'state' => $game->toArray($playerId)
        ];
    }

    public function performInitialPeek(string $gameId, string $playerId, int $cardIndex): array {
        $game = $this->getGame($gameId);
        if (!$game) {
            return ['success' => false, 'error' => 'Spiel nicht gefunden'];
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

    public function startNewRound(string $gameId, string $playerId): array {
        $game = $this->getGame($gameId);
        if (!$game) {
            return ['success' => false, 'error' => 'Spiel nicht gefunden'];
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
        $row = $pdo->prepare('SELECT * FROM games WHERE id = ?')
            ->execute([$gameId])
            ->fetch();
        
        if (!$row) {
            return null;
        }
        
        // Reconstruct game state
        $state = json_decode($row['state'], true);
        $config = json_decode($row['config'] ?? '{}', true);
        
        $game = new GameState($config);
        $game->id = $gameId;
        $game->phase = $state['phase'] ?? GameState::PHASE_SETUP;
        $game->round = $state['round'] ?? 1;
        $game->currentPlayerIndex = $state['currentPlayerIndex'] ?? 0;
        $game->caboCallerId = $state['caboCallerId'] ?? null;
        $game->finalTurnsComplete = $state['finalTurnsComplete'] ?? false;
        
        // Restore players
        foreach ($state['players'] ?? [] as $pData) {
            $player = new Player(
                $pData['name'] ?? 'Spieler',
                $pData['isBot'] ?? false,
                $pData['botDifficulty'] ?? null
            );
            $player->id = $pData['id'];
            $player->totalScore = $pData['totalScore'] ?? 0;
            $player->isHost = $pData['isHost'] ?? false;
            $player->calledCabo = $pData['calledCabo'] ?? false;
            $player->hasTakenFinalTurn = $pData['hasTakenFinalTurn'] ?? false;
            $game->addPlayer($player);
        }
        
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
            json_encode($game->toArray()),
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

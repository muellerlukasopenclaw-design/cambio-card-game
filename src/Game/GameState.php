<?php
declare(strict_types=1);

namespace Cambio\Game;

class GameState {
    public const PHASE_SETUP = 'setup';
    public const PHASE_INITIAL_PEEK = 'initial_peek';
    public const PHASE_PLAYING = 'playing';
    public const PHASE_CABO_CALLED = 'cabo_called';
    public const PHASE_ROUND_END = 'round_end';
    public const PHASE_GAME_OVER = 'game_over';

    public const ACTION_DRAW_DECK = 'draw_deck';
    public const ACTION_DRAW_DISCARD = 'draw_discard';
    public const ACTION_CALL_CABO = 'call_cabo';
    public const ACTION_PEEK = 'peek';
    public const ACTION_SPY = 'spy';
    public const ACTION_SWAP = 'swap';
    public const ACTION_DISCARD = 'discard';
    public const ACTION_SWAP_WITH_HAND = 'swap_with_hand';
    public const ACTION_DISCARD_MULTIPLE = 'discard_multiple';
    public const ACTION_SKIP = 'skip_action';

    public string $id;
    public string $phase = self::PHASE_SETUP;
    public int $round = 1;
    public int $currentPlayerIndex = 0;
    public ?string $caboCallerId = null;
    public bool $finalTurnsComplete = false;
    public Deck $deck;
    
    /** @var Player[] */
    public array $players = [];
    
    /** @var array<string, mixed> */
    public array $config = [];
    
    /** @var array<int, array> */
    public array $roundScores = [];
    
    /** @var array<string, array> */
    public array $playerKnowledge = [];
    
    public ?Card $drawnCard = null;
    public ?string $drawnCardOwnerId = null;
    public ?string $pendingAction = null;
    public ?string $lastAction = null;
    public ?array $lastActionData = null;
    public int $createdAt;
    public int $updatedAt;

    public function __construct(array $config = [], ?string $forcedId = null) {
        $this->id = $forcedId ?? uniqid('game_', true);
        $this->deck = new Deck();
        $this->config = array_merge([
            'targetScore' => 100,
            'caboPenalty' => 10,
            'scoringVariant' => 'classic', // classic: winner gets 0, simple: all count
            'allowMultipleDiscard' => false,
            'cardsPerPlayer' => 4,
            'initialPeekCount' => 2,
        ], $config);
        $this->createdAt = time();
        $this->updatedAt = time();
    }

    public function addPlayer(Player $player): void {
        $this->players[] = $player;
    }

    public function getCurrentPlayer(): ?Player {
        return $this->players[$this->currentPlayerIndex] ?? null;
    }

    public function getPlayerById(string $id): ?Player {
        foreach ($this->players as $player) {
            if ($player->id === $id) {
                return $player;
            }
        }
        return null;
    }

    public function getPlayerIndex(string $id): int {
        foreach ($this->players as $index => $player) {
            if ($player->id === $id) {
                return $index;
            }
        }
        return -1;
    }

    public function nextPlayer(): void {
        // Mark current player as having taken their final turn if in Cabo phase
        if ($this->phase === self::PHASE_CABO_CALLED) {
            $currentPlayer = $this->getCurrentPlayer();
            if ($currentPlayer) {
                $currentPlayer->hasTakenFinalTurn = true;
            }
        }

        $this->currentPlayerIndex = ($this->currentPlayerIndex + 1) % count($this->players);

        // Check if all final turns are complete after Cabo
        if ($this->phase === self::PHASE_CABO_CALLED) {
            $currentPlayer = $this->getCurrentPlayer();
            if ($currentPlayer && $currentPlayer->id === $this->caboCallerId) {
                $this->finalTurnsComplete = true;
                $this->endRound();
                return;
            }
        }
    }

    public function deal(): void {
        $this->deck->reset();
        $this->deck->shuffle();
        
        $cardsPerPlayer = $this->config['cardsPerPlayer'];
        
        foreach ($this->players as $player) {
            $player->resetForNewRound();
            for ($i = 0; $i < $cardsPerPlayer; $i++) {
                $card = $this->deck->draw();
                if ($card) {
                    $player->addCard($card, $i);
                }
            }
        }
        
        // Initial discard
        $firstDiscard = $this->deck->draw();
        if ($firstDiscard) {
            $this->deck->discard($firstDiscard);
        }
        
        $this->phase = self::PHASE_INITIAL_PEEK;
        $this->currentPlayerIndex = 0;
    }

    public function performInitialPeek(string $playerId, int $cardIndex): bool {
        $player = $this->getPlayerById($playerId);
        if (!$player || $this->phase !== self::PHASE_INITIAL_PEEK) {
            return false;
        }

        $expectedPlayer = $this->getCurrentPlayer();
        if (!$expectedPlayer || $expectedPlayer->id !== $playerId) {
            return false;
        }

        if (!isset($player->hand[$cardIndex])) {
            return false;
        }

        if (count($player->knownCards) >= $this->config['initialPeekCount']) {
            // Already peeked enough, move to next player
            $this->nextPlayer();
            if ($this->currentPlayerIndex === 0) {
                $this->phase = self::PHASE_PLAYING;
            }
            return true;
        }

        $player->learnCard($cardIndex, $player->hand[$cardIndex]);
        
        if (count($player->knownCards) >= $this->config['initialPeekCount']) {
            $this->nextPlayer();
            if ($this->currentPlayerIndex === 0) {
                $this->phase = self::PHASE_PLAYING;
            }
        }
        
        return true;
    }

    private function isTurnPhase(): bool {
        return in_array($this->phase, [self::PHASE_PLAYING, self::PHASE_CABO_CALLED], true);
    }

    private function canPlayerAct(string $playerId): bool {
        $player = $this->getCurrentPlayer();
        if (!$player || $player->id !== $playerId) {
            return false;
        }
        if ($this->drawnCard !== null || $this->pendingAction !== null) {
            return false;
        }
        if ($this->phase === self::PHASE_CABO_CALLED && $player->hasTakenFinalTurn) {
            return false;
        }
        return true;
    }

    public function drawFromDeck(string $playerId): bool {
        if (!$this->canPlayerAct($playerId) || !$this->isTurnPhase()) {
            return false;
        }

        $this->drawnCard = $this->deck->draw();
        if (!$this->drawnCard) {
            return false;
        }
        $this->drawnCardOwnerId = $playerId;
        $this->pendingAction = self::ACTION_DRAW_DECK;
        return true;
    }

    public function takeFromDiscard(string $playerId): bool {
        if (!$this->canPlayerAct($playerId) || !$this->isTurnPhase()) {
            return false;
        }

        $discardCard = $this->deck->takeDiscard();
        if (!$discardCard) {
            return false;
        }

        $this->drawnCard = $discardCard;
        $this->drawnCardOwnerId = $playerId;
        $this->pendingAction = self::ACTION_DRAW_DISCARD;
        return true;
    }

    public function swapWithHand(string $playerId, int $handIndex): bool {
        $player = $this->getCurrentPlayer();
        if (!$player || $player->id !== $playerId || !$this->drawnCard) {
            return false;
        }

        if (!isset($player->hand[$handIndex])) {
            return false;
        }

        $oldCard = $player->swapCard($handIndex, $this->drawnCard);
        $this->deck->discard($oldCard);
        
        // Update knowledge
        $player->forgetCard($handIndex);
        $player->learnCard($handIndex, $this->drawnCard);
        
        $this->drawnCard = null;
        $this->drawnCardOwnerId = null;
        $this->pendingAction = null;
        $this->lastAction = self::ACTION_SWAP_WITH_HAND;
        
        $this->nextPlayer();
        return true;
    }

    public function discardDrawn(string $playerId): bool {
        $player = $this->getCurrentPlayer();
        if (!$player || $player->id !== $playerId || !$this->drawnCard) {
            return false;
        }

        // Cannot discard when drawn from discard pile (must swap)
        if ($this->pendingAction === self::ACTION_DRAW_DISCARD) {
            return false;
        }

        $drawnValue = $this->drawnCard->getValue();
        $this->deck->discard($this->drawnCard);

        // Check for multiple discard (matching value in hand)
        $matchingIndices = [];
        foreach ($player->hand as $idx => $card) {
            if ($card->getValue() === $drawnValue) {
                $matchingIndices[] = $idx;
            }
        }

        if (!empty($matchingIndices) && ($this->config['allowMultipleDiscard'] ?? false)) {
            // Discard all matching cards
            foreach ($matchingIndices as $idx) {
                $this->deck->discard($player->hand[$idx]);
                $player->hand[$idx] = null;
                $player->forgetCard($idx);
            }
            $this->lastAction = self::ACTION_DISCARD_MULTIPLE;
            $this->lastActionData = ['count' => count($matchingIndices) + 1];
            $this->drawnCard = null;
            $this->drawnCardOwnerId = null;
            $this->pendingAction = null;
            $this->nextPlayer();
            return true;
        }

        // Check if action card and can be played
        $actionType = $this->drawnCard->getActionType();

        $this->drawnCard = null;
        $this->drawnCardOwnerId = null;

        if ($actionType && $this->pendingAction === self::ACTION_DRAW_DECK) {
            $this->pendingAction = $actionType;
            return true;
        }

        $this->pendingAction = null;
        $this->lastAction = self::ACTION_DISCARD;
        $this->nextPlayer();
        return true;
    }

    public function performPeek(string $playerId, int $cardIndex): bool {
        $player = $this->getCurrentPlayer();
        if (!$player || $player->id !== $playerId || $this->pendingAction !== self::ACTION_PEEK) {
            return false;
        }

        if (!isset($player->hand[$cardIndex])) {
            return false;
        }

        $player->learnCard($cardIndex, $player->hand[$cardIndex]);
        
        $this->pendingAction = null;
        $this->lastAction = self::ACTION_PEEK;
        $this->lastActionData = ['playerId' => $playerId, 'cardIndex' => $cardIndex];
        $this->nextPlayer();
        return true;
    }

    public function performSpy(string $playerId, string $targetId, int $cardIndex): bool {
        $player = $this->getCurrentPlayer();
        if (!$player || $player->id !== $playerId || $this->pendingAction !== self::ACTION_SPY) {
            return false;
        }

        $target = $this->getPlayerById($targetId);
        if (!$target || $target->id === $playerId) {
            return false;
        }

        if (!isset($target->hand[$cardIndex])) {
            return false;
        }

        $card = $target->hand[$cardIndex];
        $player->spiedCards[] = [
            'playerId' => $targetId,
            'cardIndex' => $cardIndex,
            'rank' => $card->rank
        ];
        
        $this->pendingAction = null;
        $this->lastAction = self::ACTION_SPY;
        $this->lastActionData = [
            'playerId' => $playerId,
            'targetId' => $targetId,
            'cardIndex' => $cardIndex
        ];
        $this->nextPlayer();
        return true;
    }

    public function performSwap(
        string $playerId,
        int $ownIndex,
        string $targetId,
        int $targetIndex
    ): bool {
        $player = $this->getCurrentPlayer();
        if (!$player || $player->id !== $playerId || $this->pendingAction !== self::ACTION_SWAP) {
            return false;
        }

        $target = $this->getPlayerById($targetId);
        if (!$target || $target->id === $playerId) {
            return false;
        }

        if (!isset($player->hand[$ownIndex]) || !isset($target->hand[$targetIndex])) {
            return false;
        }

        $ownCard = $player->hand[$ownIndex];
        $targetCard = $target->hand[$targetIndex];

        $player->hand[$ownIndex] = $targetCard;
        $target->hand[$targetIndex] = $ownCard;

        // Forget known positions
        $player->forgetCard($ownIndex);
        $target->forgetCard($targetIndex);

        $this->pendingAction = null;
        $this->lastAction = self::ACTION_SWAP;
        $this->lastActionData = [
            'playerId' => $playerId,
            'ownIndex' => $ownIndex,
            'targetId' => $targetId,
            'targetIndex' => $targetIndex
        ];
        $this->nextPlayer();
        return true;
    }

    public function skipAction(string $playerId): bool {
        $player = $this->getCurrentPlayer();
        if (!$player || $player->id !== $playerId) {
            return false;
        }

        if (!in_array($this->pendingAction, [self::ACTION_PEEK, self::ACTION_SPY, self::ACTION_SWAP], true)) {
            return false;
        }

        $this->pendingAction = null;
        $this->lastAction = self::ACTION_SKIP;
        $this->nextPlayer();
        return true;
    }

    public function callCabo(string $playerId): bool {
        $player = $this->getCurrentPlayer();
        if (!$player || $player->id !== $playerId || $this->phase !== self::PHASE_PLAYING) {
            return false;
        }

        $player->calledCabo = true;
        $this->caboCallerId = $playerId;
        $this->phase = self::PHASE_CABO_CALLED;
        $this->lastAction = self::ACTION_CALL_CABO;
        
        // Current player is done, others get one more turn
        $this->nextPlayer();
        return true;
    }

    public function endRound(): void {
        $this->phase = self::PHASE_ROUND_END;
        
        // Calculate scores
        $roundScores = [];
        $minValue = PHP_INT_MAX;
        
        foreach ($this->players as $player) {
            $value = $player->getHandValue();
            $roundScores[$player->id] = $value;
            if ($value < $minValue) {
                $minValue = $value;
            }
        }
        
        // Apply scoring variant
        if ($this->config['scoringVariant'] === 'classic') {
            foreach ($this->players as $player) {
                $value = $roundScores[$player->id];
                if ($value === $minValue) {
                    $roundScores[$player->id] = 0;
                }
            }
        }
        
        // Apply Cabo penalty
        if ($this->config['caboPenalty'] > 0 && $this->caboCallerId) {
            $caller = $this->getPlayerById($this->caboCallerId);
            if ($caller) {
                $callerValue = $roundScores[$caller->id];
                $isLowest = true;
                foreach ($roundScores as $pid => $value) {
                    if ($pid !== $caller->id && $value < $callerValue) {
                        $isLowest = false;
                        break;
                    }
                }
                if (!$isLowest) {
                    $roundScores[$caller->id] += $this->config['caboPenalty'];
                }
            }
        }
        
        // Update total scores
        foreach ($this->players as $player) {
            $player->totalScore += $roundScores[$player->id];
        }
        
        $this->roundScores[$this->round] = $roundScores;
        
        // Check for game over
        foreach ($this->players as $player) {
            if ($player->totalScore >= $this->config['targetScore']) {
                $this->phase = self::PHASE_GAME_OVER;
                return;
            }
        }
    }

    public function startNewRound(): void {
        if ($this->phase !== self::PHASE_ROUND_END) {
            return;
        }
        
        $this->round++;
        $this->caboCallerId = null;
        $this->finalTurnsComplete = false;
        $this->drawnCard = null;
        $this->pendingAction = null;
        $this->lastAction = null;
        $this->lastActionData = null;
        
        foreach ($this->players as $player) {
            $player->resetForNewRound();
        }
        
        $this->deal();
    }

    public function getWinner(): ?Player {
        if ($this->phase !== self::PHASE_GAME_OVER) {
            return null;
        }
        
        $winner = null;
        $minScore = PHP_INT_MAX;
        
        foreach ($this->players as $player) {
            if ($player->totalScore < $minScore) {
                $minScore = $player->totalScore;
                $winner = $player;
            }
        }
        
        return $winner;
    }

    public function toArray(string $forPlayerId = null): array {
        $currentPlayer = $this->getCurrentPlayer();
        $isRoundEnd = $this->phase === self::PHASE_ROUND_END || $this->phase === self::PHASE_GAME_OVER;

        $playersData = [];
        foreach ($this->players as $player) {
            $isSelf = $forPlayerId && $player->id === $forPlayerId;
            $playersData[] = $player->toArray($isSelf, $isRoundEnd);
        }

        $topDiscard = $this->deck->topDiscard();

        // Only show drawn card to the current player
        $showDrawnCard = $forPlayerId && $currentPlayer && $currentPlayer->id === $forPlayerId;

        return [
            'id' => $this->id,
            'phase' => $this->phase,
            'round' => $this->round,
            'currentPlayerIndex' => $this->currentPlayerIndex,
            'currentPlayerId' => $currentPlayer?->id,
            'caboCallerId' => $this->caboCallerId,
            'finalTurnsComplete' => $this->finalTurnsComplete,
            'players' => $playersData,
            'deckRemaining' => $this->deck->remaining(),
            'discardCount' => $this->deck->discardCount(),
            'topDiscard' => $topDiscard?->toArray(),
            'drawnCard' => $showDrawnCard ? $this->drawnCard?->toArray() : null,
            'drawnCardOwnerId' => $this->drawnCardOwnerId,
            'pendingAction' => $this->pendingAction,
            'lastAction' => $this->lastAction,
            'config' => $this->config,
            'roundScores' => $this->roundScores,
            'gameOver' => $this->phase === self::PHASE_GAME_OVER,
            'winner' => $this->getWinner()?->toArray(false)
        ];
    }

    public function toPersistedArray(): array {
        return [
            'id' => $this->id,
            'phase' => $this->phase,
            'round' => $this->round,
            'currentPlayerIndex' => $this->currentPlayerIndex,
            'caboCallerId' => $this->caboCallerId,
            'finalTurnsComplete' => $this->finalTurnsComplete,
            'players' => array_map(fn($p) => $p->toPersistedArray(), $this->players),
            'deck' => $this->deck->toArray(),
            'drawnCard' => $this->drawnCard?->toArray(),
            'drawnCardOwnerId' => $this->drawnCardOwnerId,
            'pendingAction' => $this->pendingAction,
            'lastAction' => $this->lastAction,
            'lastActionData' => $this->lastActionData,
            'config' => $this->config,
            'roundScores' => $this->roundScores,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function fromPersistedArray(array $data): self {
        $config = $data['config'] ?? [];
        $game = new self($config);
        $game->id = $data['id'];
        $game->phase = $data['phase'] ?? self::PHASE_SETUP;
        $game->round = $data['round'] ?? 1;
        $game->currentPlayerIndex = $data['currentPlayerIndex'] ?? 0;
        $game->caboCallerId = $data['caboCallerId'] ?? null;
        $game->finalTurnsComplete = $data['finalTurnsComplete'] ?? false;
        $game->lastAction = $data['lastAction'] ?? null;
        $game->lastActionData = $data['lastActionData'] ?? null;
        $game->pendingAction = $data['pendingAction'] ?? null;
        $game->roundScores = $data['roundScores'] ?? [];
        $game->createdAt = $data['createdAt'] ?? time();
        $game->updatedAt = time(); // Always update on load

        if (!empty($data['deck'])) {
            $game->deck = Deck::fromArray($data['deck']);
        }

        if (!empty($data['drawnCard'])) {
            $game->drawnCard = Card::fromArray($data['drawnCard']);
        }
        $game->drawnCardOwnerId = $data['drawnCardOwnerId'] ?? null;

        foreach ($data['players'] ?? [] as $pData) {
            $game->addPlayer(Player::fromPersistedArray($pData));
        }

        return $game;
    }
}

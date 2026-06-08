<?php
declare(strict_types=1);

namespace Cambio\Game;

class BotEngine {
    private GameState $game;
    private Player $bot;
    private string $difficulty;

    public function __construct(GameState $game, Player $bot) {
        $this->game = $game;
        $this->bot = $bot;
        $this->difficulty = $bot->botDifficulty ?? 'medium';
    }

    public function decideAction(): array {
        // ALWAYS handle pending actions first (peek/spy/swap from action cards)
        if ($this->game->pendingAction) {
            return $this->handlePendingAction();
        }

        // If we have a drawn card, handle it
        if ($this->game->drawnCard) {
            return $this->handleDrawnCard();
        }

        // Normal turn: decide to draw or call cabo
        return $this->decideNormalTurn();
    }

    /**
     * Handle pending action card states (peek, spy, swap)
     */
    private function handlePendingAction(): array {
        $pending = $this->game->pendingAction;

        switch ($pending) {
            case GameState::ACTION_PEEK:
                return $this->handlePeek();

            case GameState::ACTION_SPY:
                return $this->handleSpy();

            case GameState::ACTION_SWAP:
                return $this->handleSwap();

            default:
                return ['action' => GameState::ACTION_SKIP];
        }
    }

    private function handlePeek(): array {
        $unknown = $this->findUnknownIndex();
        if ($unknown !== null) {
            return ['action' => GameState::ACTION_PEEK, 'index' => $unknown];
        }
        $handIndices = array_keys($this->bot->hand);
        return ['action' => GameState::ACTION_PEEK, 'index' => $handIndices[array_rand($handIndices)] ?? 0];
    }

    private function handleSpy(): array {
        $target = $this->findBestSpyTarget();
        if ($target) {
            $handKeys = array_keys($target->hand);
            $index = !empty($handKeys) ? $handKeys[array_rand($handKeys)] : 0;
            return ['action' => GameState::ACTION_SPY, 'targetId' => $target->id, 'index' => $index];
        }
        return ['action' => GameState::ACTION_SKIP];
    }

    private function handleSwap(): array {
        $target = $this->findBestSwapTarget();
        if ($target) {
            $ownWorst = $this->findWorstCardIndex();
            $targetKeys = array_keys($target->hand);
            $targetIndex = !empty($targetKeys) ? $targetKeys[array_rand($targetKeys)] : 0;

            // Difficulty-based decision whether to swap or skip
            $shouldSwap = match($this->difficulty) {
                'easy' => true, // Always try swap in easy
                'medium' => $this->shouldSwapMedium(),
                'hard' => $this->shouldSwapHard(),
                default => true
            };

            if ($shouldSwap && $ownWorst !== null) {
                return [
                    'action' => GameState::ACTION_SWAP,
                    'ownIndex' => $ownWorst,
                    'targetId' => $target->id,
                    'targetIndex' => $targetIndex
                ];
            }
        }
        return ['action' => GameState::ACTION_SKIP];
    }

    private function shouldSwapMedium(): bool {
        $worstIndex = $this->findWorstCardIndex();
        if ($worstIndex === null) return true; // Unknown cards, try swap
        $worstValue = $this->bot->knownCards[$worstIndex]->getValue();
        return $worstValue >= 6; // Only swap if we have a decent card to give away
    }

    private function shouldSwapHard(): bool {
        $worstIndex = $this->findWorstCardIndex();
        if ($worstIndex === null) return true;
        $worstValue = $this->bot->knownCards[$worstIndex]->getValue();
        return $worstValue >= 8; // Only swap if we have a bad card
    }

    /**
     * Handle a drawn card (decide swap or discard)
     */
    private function handleDrawnCard(): array {
        $drawnValue = $this->game->drawnCard->getValue();
        $drawnAction = $this->game->drawnCard->getActionType();

        // If drawn from discard, must swap
        if ($this->game->pendingAction === GameState::ACTION_DRAW_DISCARD) {
            $worstIndex = $this->findWorstCardIndex();
            $handKeys = array_keys($this->bot->hand);
            return ['action' => GameState::ACTION_SWAP_WITH_HAND, 'index' => $worstIndex ?? ($handKeys[array_rand($handKeys)] ?? 0)];
        }

        // If action card, always discard to trigger the action
        if ($drawnAction) {
            return ['action' => GameState::ACTION_DISCARD];
        }

        // Strategic decision based on difficulty
        return match($this->difficulty) {
            'easy' => $this->handleDrawnCardEasy($drawnValue),
            'medium' => $this->handleDrawnCardMedium($drawnValue),
            'hard' => $this->handleDrawnCardHard($drawnValue),
            default => $this->handleDrawnCardMedium($drawnValue)
        };
    }

    private function handleDrawnCardEasy(int $drawnValue): array {
        $worstIndex = $this->findWorstCardIndex();

        // If we know a bad card and drawn is better, swap
        if ($worstIndex !== null) {
            $worstValue = $this->bot->knownCards[$worstIndex]->getValue();
            if ($drawnValue < $worstValue) {
                return ['action' => GameState::ACTION_SWAP_WITH_HAND, 'index' => $worstIndex];
            }
        }

        // Randomly swap unknown cards if drawn is low
        if ($drawnValue <= 4 && rand(1, 2) === 1) {
            $unknown = $this->findUnknownIndex();
            if ($unknown !== null) {
                return ['action' => GameState::ACTION_SWAP_WITH_HAND, 'index' => $unknown];
            }
        }

        return ['action' => GameState::ACTION_DISCARD];
    }

    private function handleDrawnCardMedium(int $drawnValue): array {
        $worstIndex = $this->findWorstCardIndex();

        if ($worstIndex !== null) {
            $worstValue = $this->bot->knownCards[$worstIndex]->getValue();
            if ($drawnValue < $worstValue) {
                return ['action' => GameState::ACTION_SWAP_WITH_HAND, 'index' => $worstIndex];
            }
        }

        $knownCount = count($this->bot->knownCards);
        if ($knownCount < 2 && $drawnValue <= 5) {
            $unknown = $this->findUnknownIndex();
            if ($unknown !== null) {
                return ['action' => GameState::ACTION_SWAP_WITH_HAND, 'index' => $unknown];
            }
        }

        return ['action' => GameState::ACTION_DISCARD];
    }

    private function handleDrawnCardHard(int $drawnValue): array {
        $worstIndex = $this->findWorstCardIndex();

        if ($worstIndex !== null) {
            $worstValue = $this->bot->knownCards[$worstIndex]->getValue();
            if ($drawnValue < $worstValue) {
                return ['action' => GameState::ACTION_SWAP_WITH_HAND, 'index' => $worstIndex];
            }
        }

        $unknownCount = count($this->bot->hand) - count($this->bot->knownCards);
        if ($unknownCount > 0 && $drawnValue <= 4) {
            $unknown = $this->findUnknownIndex();
            if ($unknown !== null) {
                return ['action' => GameState::ACTION_SWAP_WITH_HAND, 'index' => $unknown];
            }
        }

        return ['action' => GameState::ACTION_DISCARD];
    }

    /**
     * Decide normal turn action (draw or call cabo)
     */
    private function decideNormalTurn(): array {
        $estimatedValue = $this->estimateHandValue();

        // Can only act in playing or cabo_called phase
        if ($this->game->phase !== GameState::PHASE_PLAYING && $this->game->phase !== GameState::PHASE_CABO_CALLED) {
            return ['action' => GameState::ACTION_SKIP];
        }

        return match($this->difficulty) {
            'easy' => $this->decideNormalTurnEasy($estimatedValue),
            'medium' => $this->decideNormalTurnMedium($estimatedValue),
            'hard' => $this->decideNormalTurnHard($estimatedValue),
            default => $this->decideNormalTurnMedium($estimatedValue)
        };
    }

    private function decideNormalTurnEasy(int $estimatedValue): array {
        // Randomly call Cabo if estimated hand seems low
        if ($estimatedValue <= 8 && rand(1, 3) === 1) {
            return ['action' => GameState::ACTION_CALL_CABO];
        }
        return ['action' => GameState::ACTION_DRAW_DECK];
    }

    private function decideNormalTurnMedium(int $estimatedValue): array {
        // Call Cabo if hand is very good
        if ($estimatedValue <= 6) {
            return ['action' => GameState::ACTION_CALL_CABO];
        }

        // Check if discard pile has a good card
        $topDiscard = $this->game->deck->topDiscard();
        if ($topDiscard && $topDiscard->getValue() <= 3) {
            return ['action' => GameState::ACTION_DRAW_DISCARD];
        }

        return ['action' => GameState::ACTION_DRAW_DECK];
    }

    private function decideNormalTurnHard(int $estimatedValue): array {
        // More aggressive Cabo calling
        if ($estimatedValue <= 8) {
            return ['action' => GameState::ACTION_CALL_CABO];
        }

        // Consider other players' scores
        $minOpponentScore = PHP_INT_MAX;
        foreach ($this->game->players as $p) {
            if ($p->id !== $this->bot->id && $p->totalScore < $minOpponentScore) {
                $minOpponentScore = $p->totalScore;
            }
        }

        // If we're behind, be more aggressive
        if ($this->bot->totalScore > $minOpponentScore + 20 && $estimatedValue <= 12) {
            return ['action' => GameState::ACTION_CALL_CABO];
        }

        // Always check discard first
        $topDiscard = $this->game->deck->topDiscard();
        if ($topDiscard && $topDiscard->getValue() <= 2) {
            return ['action' => GameState::ACTION_DRAW_DISCARD];
        }

        return ['action' => GameState::ACTION_DRAW_DECK];
    }

    /**
     * Estimate hand value based on known cards + average for unknown
     */
    public function estimateHandValue(): int {
        $known = 0;
        $knownValue = 0;

        foreach ($this->bot->knownCards as $card) {
            $knownValue += $card->getValue();
            $known++;
        }

        $unknown = count($this->bot->hand) - $known;
        if ($unknown <= 0) {
            return $knownValue;
        }

        // Average card value is ~7 (1+2+...+13)/13 = 7
        return $knownValue + (int)($unknown * 7);
    }

    /**
     * @deprecated Use estimateHandValue() instead. Bots should not know exact hand values.
     */
    public function getHandValue(): int {
        return $this->estimateHandValue();
    }

    private function findWorstCardIndex(): ?int {
        $worstIndex = null;
        $worstValue = -1;

        foreach ($this->bot->knownCards as $idx => $card) {
            if ($card->getValue() > $worstValue) {
                $worstValue = $card->getValue();
                $worstIndex = $idx;
            }
        }

        return $worstIndex;
    }

    private function findUnknownIndex(): ?int {
        foreach (array_keys($this->bot->hand) as $i) {
            if (!isset($this->bot->knownCards[$i])) {
                return $i;
            }
        }
        return null;
    }

    private function findBestSpyTarget(): ?Player {
        $targets = [];
        foreach ($this->game->players as $p) {
            if ($p->id !== $this->bot->id) {
                $targets[] = $p;
            }
        }

        if (empty($targets)) {
            return null;
        }

        // Spy on player with lowest total score (likely winning)
        usort($targets, fn($a, $b) => $a->totalScore <=> $b->totalScore);
        return $targets[0];
    }

    private function findBestSwapTarget(): ?Player {
        $targets = [];
        foreach ($this->game->players as $p) {
            if ($p->id !== $this->bot->id) {
                $targets[] = $p;
            }
        }

        return empty($targets) ? null : $targets[array_rand($targets)];
    }
}

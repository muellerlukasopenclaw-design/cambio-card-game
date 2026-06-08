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
        return match($this->difficulty) {
            'easy' => $this->easyAction(),
            'medium' => $this->mediumAction(),
            'hard' => $this->hardAction(),
            default => $this->mediumAction()
        };
    }

    private function easyAction(): array {
        $estimatedValue = $this->estimateHandValue();
        $knownCount = count($this->bot->knownCards);
        $topDiscard = $this->game->deck->topDiscard();
        
        // Randomly decide to call Cabo if estimated hand seems low
        if ($this->game->phase === GameState::PHASE_PLAYING && $estimatedValue <= 8 && rand(1, 3) === 1) {
            return ['action' => GameState::ACTION_CALL_CABO];
        }
        
        // Always draw from deck (simple)
        if ($this->game->phase === GameState::PHASE_PLAYING) {
            return ['action' => GameState::ACTION_DRAW_DECK];
        }
        
        // If we have a drawn card, swap if it's better than known cards
        if ($this->game->drawnCard && $this->game->pendingAction === GameState::ACTION_DRAW_DECK) {
            $drawnValue = $this->game->drawnCard->getValue();
            
            // Find highest known card
            $worstIndex = null;
            $worstValue = -1;
            foreach ($this->bot->knownCards as $idx => $card) {
                if ($card->getValue() > $worstValue) {
                    $worstValue = $card->getValue();
                    $worstIndex = $idx;
                }
            }
            
            // If we know a bad card and drawn is better, swap
            if ($worstIndex !== null && $drawnValue < $worstValue) {
                return ['action' => GameState::ACTION_SWAP_WITH_HAND, 'index' => $worstIndex];
            }
            
            // Randomly swap unknown cards if drawn is low
            if ($drawnValue <= 4 && rand(1, 2) === 1) {
                $unknownIndices = [];
                foreach (array_keys($this->bot->hand) as $i) {
                    if (!isset($this->bot->knownCards[$i])) {
                        $unknownIndices[] = $i;
                    }
                }
                if (!empty($unknownIndices)) {
                    return ['action' => GameState::ACTION_SWAP_WITH_HAND, 'index' => $unknownIndices[array_rand($unknownIndices)]];
                }
            }
            
            // Discard
            return ['action' => GameState::ACTION_DISCARD];
        }
        
        // If action card pending, use randomly
        if ($this->game->pendingAction === GameState::ACTION_PEEK) {
            $unknownIndices = [];
            foreach (array_keys($this->bot->hand) as $i) {
                if (!isset($this->bot->knownCards[$i])) {
                    $unknownIndices[] = $i;
                }
            }
            if (!empty($unknownIndices)) {
                return ['action' => GameState::ACTION_PEEK, 'index' => $unknownIndices[array_rand($unknownIndices)]];
            }
            $handIndices = array_keys($this->bot->hand);
            return ['action' => GameState::ACTION_PEEK, 'index' => $handIndices[array_rand($handIndices)]];
        }
        
        if ($this->game->pendingAction === GameState::ACTION_SPY) {
            $targets = [];
            foreach ($this->game->players as $p) {
                if ($p->id !== $this->bot->id) {
                    $targets[] = $p;
                }
            }
            if (!empty($targets)) {
                $target = $targets[array_rand($targets)];
                $hand = $target->getVisibleHand();
                return ['action' => GameState::ACTION_SPY, 'targetId' => $target->id, 'index' => rand(0, count($hand) - 1)];
            }
        }
        
        if ($this->game->pendingAction === GameState::ACTION_SWAP) {
            // Random swap
            $targets = [];
            foreach ($this->game->players as $p) {
                if ($p->id !== $this->bot->id) {
                    $targets[] = $p;
                }
            }
            if (!empty($targets)) {
                $target = $targets[array_rand($targets)];
                $ownIndex = rand(0, 3);
                $targetHand = $target->getVisibleHand();
                return [
                    'action' => GameState::ACTION_SWAP,
                    'ownIndex' => $ownIndex,
                    'targetId' => $target->id,
                    'targetIndex' => rand(0, count($targetHand) - 1)
                ];
            }
        }
        
        return ['action' => GameState::ACTION_DISCARD];
    }

    private function mediumAction(): array {
        $estimatedValue = $this->estimateHandValue();
        $knownCount = count($this->bot->knownCards);
        $topDiscard = $this->game->deck->topDiscard();
        
        // Call Cabo strategically
        if ($this->game->phase === GameState::PHASE_PLAYING) {
            if ($estimatedValue <= 6) {
                return ['action' => GameState::ACTION_CALL_CABO];
            }
            
            // Check if discard pile has a good card
            if ($topDiscard && $topDiscard->getValue() <= 3) {
                return ['action' => GameState::ACTION_DRAW_DISCARD];
            }
            
            return ['action' => GameState::ACTION_DRAW_DECK];
        }
        
        // Handle drawn card
        if ($this->game->drawnCard) {
            $drawnValue = $this->game->drawnCard->getValue();
            $drawnAction = $this->game->drawnCard->getActionType();
            
            // If drawn from discard, must swap
            if ($this->game->pendingAction === GameState::ACTION_DRAW_DISCARD) {
                $worstIndex = $this->findWorstCardIndex();
                return ['action' => GameState::ACTION_SWAP_WITH_HAND, 'index' => $worstIndex ?? rand(0, 3)];
            }
            
            // If action card and easy mode, use it
            if ($drawnAction && $this->difficulty === 'easy') {
                return ['action' => GameState::ACTION_DISCARD];
            }
            
            // Strategic swap
            $worstIndex = $this->findWorstCardIndex();
            if ($worstIndex !== null) {
                $worstValue = $this->bot->knownCards[$worstIndex]->getValue();
                if ($drawnValue < $worstValue) {
                    return ['action' => GameState::ACTION_SWAP_WITH_HAND, 'index' => $worstIndex];
                }
            }
            
            // If we don't know many cards and drawn is low, swap with unknown
            if ($knownCount < 2 && $drawnValue <= 5) {
                $unknown = $this->findUnknownIndex();
                if ($unknown !== null) {
                    return ['action' => GameState::ACTION_SWAP_WITH_HAND, 'index' => $unknown];
                }
            }
            
            return ['action' => GameState::ACTION_DISCARD];
        }
        
        // Handle pending actions
        if ($this->game->pendingAction === GameState::ACTION_PEEK) {
            $unknown = $this->findUnknownIndex();
            return ['action' => GameState::ACTION_PEEK, 'index' => $unknown ?? 0];
        }
        
        if ($this->game->pendingAction === GameState::ACTION_SPY) {
            $target = $this->findBestSpyTarget();
            if ($target) {
                $hand = $target->getVisibleHand();
                return ['action' => GameState::ACTION_SPY, 'targetId' => $target->id, 'index' => rand(0, count($hand) - 1)];
            }
        }
        
        if ($this->game->pendingAction === GameState::ACTION_SWAP) {
            // Don't swap if we have good cards
            if ($handValue <= 8) {
                return ['action' => GameState::ACTION_DISCARD];
            }
            
            $target = $this->findBestSwapTarget();
            if ($target) {
                $ownWorst = $this->findWorstCardIndex() ?? rand(0, 3);
                $targetHand = $target->getVisibleHand();
                return [
                    'action' => GameState::ACTION_SWAP,
                    'ownIndex' => $ownWorst,
                    'targetId' => $target->id,
                    'targetIndex' => rand(0, count($targetHand) - 1)
                ];
            }
        }
        
        return ['action' => GameState::ACTION_DISCARD];
    }

    private function hardAction(): array {
        // Hard uses medium as base but with more aggressive Cabo calling
        // and better probability estimation
        
        $estimatedValue = $this->estimateHandValue();
        
        // More aggressive Cabo calling
        if ($this->game->phase === GameState::PHASE_PLAYING) {
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
        
        // Enhanced card handling
        if ($this->game->drawnCard) {
            $drawnValue = $this->game->drawnCard->getValue();
            
            if ($this->game->pendingAction === GameState::ACTION_DRAW_DISCARD) {
                $worstIndex = $this->findWorstCardIndex();
                return ['action' => GameState::ACTION_SWAP_WITH_HAND, 'index' => $worstIndex ?? rand(0, 3)];
            }
            
            // Probability-based decision
            $worstIndex = $this->findWorstCardIndex();
            if ($worstIndex !== null) {
                $worstValue = $this->bot->knownCards[$worstIndex]->getValue();
                if ($drawnValue < $worstValue) {
                    return ['action' => GameState::ACTION_SWAP_WITH_HAND, 'index' => $worstIndex];
                }
            }
            
            // If unknown cards exist and drawn is decent, take the chance
            $unknownCount = 4 - count($this->bot->knownCards);
            if ($unknownCount > 0 && $drawnValue <= 4) {
                $unknown = $this->findUnknownIndex();
                if ($unknown !== null) {
                    return ['action' => GameState::ACTION_SWAP_WITH_HAND, 'index' => $unknown];
                }
            }
            
            return ['action' => GameState::ACTION_DISCARD];
        }
        
        // Smart action usage
        if ($this->game->pendingAction === GameState::ACTION_PEEK) {
            $unknown = $this->findUnknownIndex();
            return ['action' => GameState::ACTION_PEEK, 'index' => $unknown ?? 0];
        }
        
        if ($this->game->pendingAction === GameState::ACTION_SPY) {
            // Spy on player with lowest visible score or most cards
            $target = $this->findBestSpyTarget();
            if ($target) {
                $hand = $target->getVisibleHand();
                // Prefer unknown positions
                return ['action' => GameState::ACTION_SPY, 'targetId' => $target->id, 'index' => rand(0, count($hand) - 1)];
            }
        }
        
        if ($this->game->pendingAction === GameState::ACTION_SWAP) {
            // Only swap if we have a known bad card
            $worstIndex = $this->findWorstCardIndex();
            if ($worstIndex !== null && $this->bot->knownCards[$worstIndex]->getValue() >= 8) {
                $target = $this->findBestSwapTarget();
                if ($target) {
                    $targetHand = $target->getVisibleHand();
                    return [
                        'action' => GameState::ACTION_SWAP,
                        'ownIndex' => $worstIndex,
                        'targetId' => $target->id,
                        'targetIndex' => rand(0, count($targetHand) - 1)
                    ];
                }
            }
            return ['action' => GameState::ACTION_DISCARD];
        }
        
        return ['action' => GameState::ACTION_DISCARD];
    }

    private function estimateHandValue(): int {
        $known = 0;
        $knownValue = 0;
        
        foreach ($this->bot->knownCards as $card) {
            $knownValue += $card->getValue();
            $known++;
        }
        
        $unknown = 4 - $known;
        if ($unknown === 0) {
            return $knownValue;
        }
        
        // Average card value is ~7 (1+2+...+13)/13 = 7
        $estimated = $knownValue + ($unknown * 7);
        return $estimated;
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

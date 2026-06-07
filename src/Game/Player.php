<?php
declare(strict_types=1);

namespace Cambio\Game;

class Player {
    public string $id;
    public string $name;
    public bool $isBot;
    public ?string $botDifficulty;
    
    /** @var Card[] */
    public array $hand = [];
    
    /** @var array<int, Card> */
    public array $knownCards = [];
    
    /** @var array<int, array{playerId: string, cardIndex: int, rank: int}> */
    public array $spiedCards = [];
    
    public int $totalScore = 0;
    public bool $ready = false;
    public bool $isHost = false;
    public bool $calledCabo = false;
    public bool $hasTakenFinalTurn = false;
    public ?string $sessionToken = null;

    public function __construct(
        string $name,
        bool $isBot = false,
        ?string $botDifficulty = null
    ) {
        $this->id = uniqid('p_', true);
        $this->name = $name;
        $this->isBot = $isBot;
        $this->botDifficulty = $botDifficulty;
    }

    public function addCard(Card $card, int $index): void {
        $this->hand[$index] = $card;
    }

    public function removeCard(int $index): ?Card {
        if (!isset($this->hand[$index])) {
            return null;
        }
        $card = $this->hand[$index];
        unset($this->hand[$index]);
        return $card;
    }

    public function swapCard(int $index, Card $newCard): Card {
        $oldCard = $this->hand[$index];
        $this->hand[$index] = $newCard;
        return $oldCard;
    }

    public function knowsCard(int $index): bool {
        return isset($this->knownCards[$index]);
    }

    public function learnCard(int $index, Card $card): void {
        $this->knownCards[$index] = $card;
    }

    public function forgetCard(int $index): void {
        unset($this->knownCards[$index]);
    }

    public function getHandValue(): int {
        $value = 0;
        foreach ($this->hand as $card) {
            $value += $card->getValue();
        }
        return $value;
    }

    public function getVisibleHand(): array {
        return array_values($this->hand);
    }

    public function toArray(bool $includeSecrets = false): array {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'isBot' => $this->isBot,
            'botDifficulty' => $this->botDifficulty,
            'totalScore' => $this->totalScore,
            'ready' => $this->ready,
            'isHost' => $this->isHost,
            'calledCabo' => $this->calledCabo,
            'hasTakenFinalTurn' => $this->hasTakenFinalTurn,
            'cardCount' => count($this->hand)
        ];

        if ($includeSecrets) {
            $data['hand'] = array_map(fn($c) => $c->toArray(), $this->getVisibleHand());
            $data['knownCards'] = array_map(
                fn($idx, $card) => ['index' => $idx, 'card' => $card->toArray()],
                array_keys($this->knownCards),
                $this->knownCards
            );
            $data['spiedCards'] = $this->spiedCards;
        }

        return $data;
    }

    public function resetForNewRound(): void {
        $this->hand = [];
        $this->knownCards = [];
        $this->spiedCards = [];
        $this->calledCabo = false;
        $this->hasTakenFinalTurn = false;
    }
}

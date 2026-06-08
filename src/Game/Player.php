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
            // For client: only show known cards, others as null
            $data['hand'] = array_map(function($c) { return $c ? $c->toArray() : null; }, array_values($this->hand));
            $data['knownCards'] = array_map(
                fn($idx, $card) => ['index' => $idx, 'card' => $card->toArray()],
                array_keys($this->knownCards),
                $this->knownCards
            );
            $data['spiedCards'] = $this->spiedCards;
        }

        return $data;
    }

    public function toPersistedArray(): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'isBot' => $this->isBot,
            'botDifficulty' => $this->botDifficulty,
            'totalScore' => $this->totalScore,
            'ready' => $this->ready,
            'isHost' => $this->isHost,
            'calledCabo' => $this->calledCabo,
            'hasTakenFinalTurn' => $this->hasTakenFinalTurn,
            'hand' => array_map(fn($c) => $c->toArray(), array_values($this->hand)),
            'knownCards' => array_map(
                fn($idx, $card) => ['index' => $idx, 'card' => $card->toArray()],
                array_keys($this->knownCards),
                $this->knownCards
            ),
            'spiedCards' => $this->spiedCards,
            'sessionToken' => $this->sessionToken,
        ];
    }

    public static function fromPersistedArray(array $data): self {
        $player = new self($data['name'] ?? 'Spieler', $data['isBot'] ?? false, $data['botDifficulty'] ?? null);
        $player->id = $data['id'];
        $player->totalScore = $data['totalScore'] ?? 0;
        $player->ready = $data['ready'] ?? false;
        $player->isHost = $data['isHost'] ?? false;
        $player->calledCabo = $data['calledCabo'] ?? false;
        $player->hasTakenFinalTurn = $data['hasTakenFinalTurn'] ?? false;
        $player->sessionToken = $data['sessionToken'] ?? null;

        foreach ($data['hand'] ?? [] as $index => $cardData) {
            $player->hand[$index] = Card::fromArray($cardData);
        }

        foreach ($data['knownCards'] ?? [] as $kc) {
            $player->knownCards[$kc['index']] = Card::fromArray($kc['card']);
        }

        $player->spiedCards = $data['spiedCards'] ?? [];

        return $player;
    }

    public function resetForNewRound(): void {
        $this->hand = [];
        $this->knownCards = [];
        $this->spiedCards = [];
        $this->calledCabo = false;
        $this->hasTakenFinalTurn = false;
    }
}

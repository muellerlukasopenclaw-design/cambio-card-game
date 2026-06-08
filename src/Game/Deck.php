<?php
declare(strict_types=1);

namespace Cambio\Game;

class Deck {
    /** @var Card[] */
    private array $cards = [];
    
    /** @var Card[] */
    private array $discard = [];

    public function __construct() {
        $this->reset();
    }

    public function reset(): void {
        $this->cards = [];
        $this->discard = [];
        
        foreach (Card::SUITS as $suit) {
            for ($rank = 1; $rank <= 13; $rank++) {
                $this->cards[] = new Card($suit, $rank);
            }
        }
    }

    public function shuffle(): void {
        shuffle($this->cards);
    }

    public function draw(): ?Card {
        if (empty($this->cards)) {
            $this->reshuffle();
        }
        return array_pop($this->cards);
    }

    public function drawMultiple(int $count): array {
        $cards = [];
        for ($i = 0; $i < $count; $i++) {
            $card = $this->draw();
            if ($card) {
                $cards[] = $card;
            }
        }
        return $cards;
    }

    public function discard(Card $card): void {
        $this->discard[] = $card;
    }

    public function topDiscard(): ?Card {
        return empty($this->discard) ? null : $this->discard[count($this->discard) - 1];
    }

    public function takeDiscard(): ?Card {
        return array_pop($this->discard);
    }

    public function remaining(): int {
        return count($this->cards);
    }

    public function discardCount(): int {
        return count($this->discard);
    }

    private function reshuffle(): void {
        if (empty($this->discard)) {
            return;
        }
        $topCard = array_pop($this->discard);
        $this->cards = $this->discard;
        $this->discard = [$topCard];
        shuffle($this->cards);
    }

    public function getDiscard(): array {
        return $this->discard;
    }

    public function toArray(): array {
        return [
            'cards' => array_map(fn($c) => $c->toArray(), $this->cards),
            'discard' => array_map(fn($c) => $c->toArray(), $this->discard),
        ];
    }

    public static function fromArray(array $data): self {
        $deck = new self();
        $deck->cards = array_map(fn($c) => new Card($c['suit'], $c['rank']), $data['cards'] ?? []);
        $deck->discard = array_map(fn($c) => new Card($c['suit'], $c['rank']), $data['discard'] ?? []);
        return $deck;
    }
}

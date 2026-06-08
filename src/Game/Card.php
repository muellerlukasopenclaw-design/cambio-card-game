<?php
declare(strict_types=1);

namespace Cambio\Game;

class Card {
    public const SUIT_HEARTS = '♥';
    public const SUIT_DIAMONDS = '♦';
    public const SUIT_CLUBS = '♣';
    public const SUIT_SPADES = '♠';

    public const SUITS = [self::SUIT_HEARTS, self::SUIT_DIAMONDS, self::SUIT_CLUBS, self::SUIT_SPADES];
    public const RED_SUITS = [self::SUIT_HEARTS, self::SUIT_DIAMONDS];

    public function __construct(
        public readonly string $suit,
        public readonly int $rank
    ) {}

    public function getValue(): int {
        return match($this->rank) {
            1 => 1,   // Ace
            2, 3, 4, 5, 6 => $this->rank,
            7, 8 => 7,  // Peek
            9, 10 => 9, // Spy
            11, 12 => 11, // Swap
            13 => 13, // King
            default => $this->rank
        };
    }

    public function getDisplayRank(): string {
        return match($this->rank) {
            1 => 'A',
            11 => 'J',
            12 => 'Q',
            13 => 'K',
            default => (string)$this->rank
        };
    }

    public function isRed(): bool {
        return in_array($this->suit, self::RED_SUITS, true);
    }

    public function isActionCard(): bool {
        return in_array($this->rank, [7, 8, 9, 10, 11, 12], true);
    }

    public function getActionType(): ?string {
        return match($this->rank) {
            7, 8 => 'peek',
            9, 10 => 'spy',
            11, 12 => 'swap',
            default => null
        };
    }

    public function toArray(): array {
        return [
            'suit' => $this->suit,
            'rank' => $this->rank,
            'value' => $this->getValue(),
            'display' => $this->getDisplayRank(),
            'isRed' => $this->isRed(),
            'action' => $this->getActionType()
        ];
    }

    public function equals(Card $other): bool {
        return $this->suit === $other->suit && $this->rank === $other->rank;
    }

    public function sameRank(Card $other): bool {
        return $this->rank === $other->rank;
    }

    public static function fromArray(array $data): self {
        return new self($data['suit'], $data['rank']);
    }
}

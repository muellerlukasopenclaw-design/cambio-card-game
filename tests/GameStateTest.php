<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Cambio\Game\GameState;
use Cambio\Game\Player;
use Cambio\Game\Card;
use Cambio\Game\Deck;

class GameStateTest extends TestCase {
    
    // ─── 5.1 Game Engine Tests ───────────────────────────────────────
    
    public function testDeckCreation(): void {
        $deck = new Deck();
        $this->assertCount(52, $deck->cards);
    }
    
    public function testDeckShuffle(): void {
        $deck1 = new Deck();
        $deck1->shuffle();
        $deck2 = new Deck();
        $deck2->shuffle();
        
        // Should be different after shuffle
        $this->assertNotEquals(
            array_map(fn($c) => $c->toString(), $deck1->cards),
            array_map(fn($c) => $c->toString(), $deck2->cards)
        );
    }
    
    public function testDealCreatesCorrectNumberOfCards(): void {
        $gs = new GameState();
        $gs->addPlayer(new Player('p1', 'Alice'));
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        
        $this->assertCount(4, $gs->players[0]->hand);
        $this->assertCount(4, $gs->players[1]->hand);
        $this->assertCount(44, $gs->deck->cards); // 52 - 8 dealt
    }
    
    public function testInitialPeekExactlyTwoCards(): void {
        $gs = new GameState();
        $p1 = new Player('p1', 'Alice');
        $gs->addPlayer($p1);
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        
        $gs->performInitialPeek('p1', 0);
        $gs->performInitialPeek('p1', 1);
        
        $this->assertCount(2, $p1->knownCards);
        $this->assertTrue($p1->hasSeenCard(0));
        $this->assertTrue($p1->hasSeenCard(1));
    }
    
    public function testDoubleInitialPeekForbidden(): void {
        $gs = new GameState();
        $p1 = new Player('p1', 'Alice');
        $gs->addPlayer($p1);
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        
        $gs->performInitialPeek('p1', 0);
        $gs->performInitialPeek('p1', 0); // Should be ignored
        
        $this->assertCount(1, $p1->knownCards);
    }
    
    public function testDrawDeck(): void {
        $gs = new GameState();
        $gs->addPlayer(new Player('p1', 'Alice'));
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        $gs->drawDeck('p1');
        $this->assertNotNull($gs->drawnCard);
        $this->assertEquals('p1', $gs->drawnCardOwnerId);
    }
    
    public function testDiscardDrawnCard(): void {
        $gs = new GameState();
        $gs->addPlayer(new Player('p1', 'Alice'));
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        $gs->drawDeck('p1');
        $discarded = $gs->drawnCard;
        $gs->discardDrawn('p1');
        
        $this->assertNull($gs->drawnCard);
        $this->assertEquals($discarded->toString(), $gs->discardPile[0]->toString());
    }
    
    public function testSwapWithHand(): void {
        $gs = new GameState();
        $p1 = new Player('p1', 'Alice');
        $gs->addPlayer($p1);
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        $originalHand = $p1->hand[0]->toString();
        $gs->drawDeck('p1');
        $drawnCard = $gs->drawnCard;
        
        $gs->swapWithHand('p1', 0);
        
        $this->assertEquals($drawnCard->toString(), $p1->hand[0]->toString());
        $this->assertEquals($originalHand, $gs->discardPile[0]->toString());
    }
    
    public function testDrawDiscardForcesSwap(): void {
        $gs = new GameState();
        $gs->addPlayer(new Player('p1', 'Alice'));
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        // First draw and discard
        $gs->drawDeck('p1');
        $gs->discardDrawn('p1');
        
        // Now draw from discard - must swap
        $gs->drawDiscard('p1');
        $this->assertEquals(GameState::ACTION_SWAP, $gs->pendingAction);
    }
    
    public function testPeekAction(): void {
        $gs = new GameState();
        $p1 = new Player('p1', 'Alice');
        $gs->addPlayer($p1);
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        // Force a peek card (7, 8, 9)
        $p1->hand[0] = Card::fromString('7♥');
        
        $gs->drawDeck('p1');
        $gs->drawnCard = Card::fromString('7♠'); // Peek card
        
        $gs->performAction('p1', ['action' => 'use_special', 'specialAction' => 'peek', 'index' => 0]);
        
        $this->assertTrue($p1->hasSeenCard(0));
    }
    
    public function testSpyAction(): void {
        $gs = new GameState();
        $p1 = new Player('p1', 'Alice');
        $p2 = new Player('p2', 'Bob');
        $gs->addPlayer($p1);
        $gs->addPlayer($p2);
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        // Force a spy card (10, J, Q)
        $gs->drawDeck('p1');
        $gs->drawnCard = Card::fromString('J♠'); // Spy card
        
        $gs->performAction('p1', ['action' => 'use_special', 'specialAction' => 'spy', 'targetId' => 'p2', 'index' => 0]);
        
        // p1 should now know p2's card
        $this->assertTrue($p1->hasSeenOpponentCard('p2', 0));
    }
    
    public function testSwapAction(): void {
        $gs = new GameState();
        $p1 = new Player('p1', 'Alice');
        $p2 = new Player('p2', 'Bob');
        $gs->addPlayer($p1);
        $gs->addPlayer($p2);
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        $p1Original = $p1->hand[0]->toString();
        $p2Original = $p2->hand[0]->toString();
        
        // Force a swap card (K)
        $gs->drawDeck('p1');
        $gs->drawnCard = Card::fromString('K♠'); // Swap card
        
        $gs->performAction('p1', [
            'action' => 'use_special',
            'specialAction' => 'swap',
            'targetId' => 'p2',
            'myIndex' => 0,
            'theirIndex' => 0
        ]);
        
        $this->assertEquals($p2Original, $p1->hand[0]->toString());
        $this->assertEquals($p1Original, $p2->hand[0]->toString());
    }
    
    public function testSkipAction(): void {
        $gs = new GameState();
        $gs->addPlayer(new Player('p1', 'Alice'));
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        $gs->drawDeck('p1');
        $gs->drawnCard = Card::fromString('7♠'); // Peek card
        
        $gs->performAction('p1', ['action' => 'skip_special']);
        
        $this->assertNull($gs->drawnCard);
        $this->assertNull($gs->pendingAction);
    }
    
    public function testCallCabo(): void {
        $gs = new GameState();
        $gs->addPlayer(new Player('p1', 'Alice'));
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        $gs->callCabo('p1');
        
        $this->assertEquals(GameState::PHASE_CABO_CALLED, $gs->phase);
        $this->assertEquals('p1', $gs->caboCallerId);
        $this->assertTrue($gs->players[0]->calledCabo);
    }
    
    public function testFinalTurnsAfterCabo(): void {
        $gs = new GameState();
        $gs->addPlayer(new Player('p1', 'Alice'));
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->addPlayer(new Player('p3', 'Charlie'));
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        $gs->callCabo('p1');
        
        // p2 and p3 should still be able to play
        $this->assertEquals('p2', $gs->currentPlayerId);
        
        $gs->drawDeck('p2');
        $gs->discardDrawn('p2');
        
        $this->assertEquals('p3', $gs->currentPlayerId);
        
        $gs->drawDeck('p3');
        $gs->discardDrawn('p3');
        
        // Should end round
        $this->assertEquals(GameState::PHASE_ROUND_END, $gs->phase);
    }
    
    public function testEndRound(): void {
        $gs = new GameState();
        $p1 = new Player('p1', 'Alice');
        $p2 = new Player('p2', 'Bob');
        $gs->addPlayer($p1);
        $gs->addPlayer($p2);
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        $gs->callCabo('p1');
        $gs->endRound();
        
        $this->assertEquals(GameState::PHASE_ROUND_END, $gs->phase);
        $this->assertGreaterThan(0, $p1->totalScore);
        $this->assertGreaterThan(0, $p2->totalScore);
    }
    
    public function testScoring(): void {
        $gs = new GameState();
        $p1 = new Player('p1', 'Alice');
        $p2 = new Player('p2', 'Bob');
        $gs->addPlayer($p1);
        $gs->addPlayer($p2);
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        // Force specific cards
        $p1->hand = [Card::fromString('5♥'), Card::fromString('3♦'), Card::fromString('7♠'), Card::fromString('2♣')];
        $p2->hand = [Card::fromString('K♥'), Card::fromString('Q♦'), Card::fromString('J♠'), Card::fromString('A♣')];
        
        $gs->callCabo('p1');
        $gs->endRound();
        
        $p1Score = $p1->totalScore;
        $p2Score = $p2->totalScore;
        
        // p1 has lower hand (5+3+7+2=17) vs p2 (10+10+10+1=31)
        $this->assertLessThan($p2Score, $p1Score);
    }
    
    public function testCaboPenalty(): void {
        $gs = new GameState(['caboPenalty' => 10, 'scoringVariant' => 'classic']);
        $p1 = new Player('p1', 'Alice');
        $p2 = new Player('p2', 'Bob');
        $gs->addPlayer($p1);
        $gs->addPlayer($p2);
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        // p1 has high hand, p2 has low hand
        $p1->hand = [Card::fromString('K♥'), Card::fromString('Q♦'), Card::fromString('J♠'), Card::fromString('10♣')];
        $p2->hand = [Card::fromString('2♥'), Card::fromString('3♦'), Card::fromString('4♠'), Card::fromString('5♣')];
        
        $gs->callCabo('p1'); // p1 calls cabo but has higher score
        $gs->endRound();
        
        // p1 should get penalty (40 + 10 = 50)
        $this->assertEquals(50, $p1->totalScore);
    }
    
    public function testNewRound(): void {
        $gs = new GameState();
        $gs->addPlayer(new Player('p1', 'Alice'));
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        $gs->callCabo('p1');
        $gs->endRound();
        $gs->startNewRound();
        
        $this->assertEquals(GameState::PHASE_INITIAL_PEEK, $gs->phase);
        $this->assertEquals(2, $gs->round);
        $this->assertCount(4, $gs->players[0]->hand);
    }
    
    public function testGameOver(): void {
        $gs = new GameState(['targetScore' => 50]);
        $p1 = new Player('p1', 'Alice');
        $p2 = new Player('p2', 'Bob');
        $gs->addPlayer($p1);
        $gs->addPlayer($p2);
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        // Force p1 to have high score
        $p1->totalScore = 51;
        
        $gs->callCabo('p1');
        $gs->endRound();
        
        $this->assertTrue($gs->gameOver);
        $this->assertNotNull($gs->winner);
    }
    
    // ─── 5.2 Security/Visibility Tests ───────────────────────────────
    
    public function testPlayerSeesOnlyOwnKnownCards(): void {
        $gs = new GameState();
        $p1 = new Player('p1', 'Alice');
        $p2 = new Player('p2', 'Bob');
        $gs->addPlayer($p1);
        $gs->addPlayer($p2);
        $gs->deal();
        
        $state = $gs->toArray('p1');
        
        // p1 should see their own cards (but initially null because not peeked)
        $this->assertNotNull($state['players'][0]['hand']);
        
        // p2's cards should be hidden (null)
        $this->assertNull($state['players'][1]['hand'][0]);
    }
    
    public function testPlayerCannotSeeOpponentCards(): void {
        $gs = new GameState();
        $p1 = new Player('p1', 'Alice');
        $p2 = new Player('p2', 'Bob');
        $gs->addPlayer($p1);
        $gs->addPlayer($p2);
        $gs->deal();
        
        $state = $gs->toArray('p1');
        
        // p2's hand should be null or empty
        foreach ($state['players'] as $player) {
            if ($player['id'] === 'p2') {
                $this->assertNull($player['hand'][0]);
            }
        }
    }
    
    public function testDrawnCardOnlyVisibleToOwner(): void {
        $gs = new GameState();
        $gs->addPlayer(new Player('p1', 'Alice'));
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        $gs->drawDeck('p1');
        
        // From p1's perspective
        $state1 = $gs->toArray('p1');
        $this->assertNotNull($state1['drawnCard']);
        
        // From p2's perspective
        $state2 = $gs->toArray('p2');
        $this->assertNull($state2['drawnCard']);
    }
    
    // ─── 5.3 State Persistency Tests ──────────────────────────────────
    
    public function testPersistAndRestore(): void {
        $gs = new GameState();
        $gs->addPlayer(new Player('p1', 'Alice'));
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        $persisted = $gs->toPersistedArray();
        $restored = GameState::fromPersistedArray($persisted);
        
        $this->assertEquals($gs->id, $restored->id);
        $this->assertEquals($gs->phase, $restored->phase);
        $this->assertEquals($gs->round, $restored->round);
        $this->assertCount(2, $restored->players);
    }
    
    public function testPendingActionPersisted(): void {
        $gs = new GameState();
        $gs->addPlayer(new Player('p1', 'Alice'));
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        $gs->drawDeck('p1');
        
        $persisted = $gs->toPersistedArray();
        $restored = GameState::fromPersistedArray($persisted);
        
        $this->assertNotNull($restored->drawnCard);
        $this->assertEquals($gs->drawnCard->toString(), $restored->drawnCard->toString());
    }
    
    // ─── 5.4 Multiplayer/Lobby Tests ─────────────────────────────────
    
    public function testLobbyStateRequiresToken(): void {
        $db = new \Cambio\Storage\Database();
        $lobbyController = new \Cambio\Api\LobbyController($db);
        
        // Create lobby
        $result = $lobbyController->create('Test Lobby', 'Host', 5);
        $this->assertTrue($result['success']);
        
        $lobbyId = $result['lobby']['id'];
        
        // Without token should fail for non-playing lobby
        $state = $lobbyController->getState($lobbyId, '');
        $this->assertFalse($state['success']);
    }
    
    public function testLobbyGetsPlayingStatusAfterStart(): void {
        $db = new \Cambio\Storage\Database();
        $lobbyController = new \Cambio\Api\LobbyController($db);
        $gameController = new \Cambio\Api\GameController($db);
        
        // Create lobby
        $result = $lobbyController->create('Test Lobby', 'Host', 5);
        $lobbyId = $result['lobby']['id'];
        $hostId = $result['player']['id'];
        $token = $result['player']['token'];
        
        // Set ready
        $lobbyController->setReady($lobbyId, $hostId, true, hash('sha256', $token));
        
        // Start game
        $startResult = $lobbyController->startGame($lobbyId, hash('sha256', $token));
        $this->assertTrue($startResult['success']);
        
        // Create game
        $gameResult = $gameController->createGame($lobbyId, $startResult['players'], [], $startResult['gameId']);
        $this->assertTrue($gameResult['success']);
        
        // Update lobby status
        $db->getPdo()->prepare('UPDATE lobbies SET status = ?, game_id = ? WHERE id = ?')
            ->execute(['playing', $gameResult['gameId'], $lobbyId]);
        
        // Check lobby state
        $state = $lobbyController->getState($lobbyId, hash('sha256', $token));
        $this->assertTrue($state['success']);
        $this->assertEquals('playing', $state['lobby']['status']);
        $this->assertEquals($gameResult['gameId'], $state['lobby']['gameId']);
    }
}

<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Cambio\Game\GameState;
use Cambio\Game\Player;

class GameStateTest extends TestCase {
    public function testDealCreatesCorrectNumberOfCards(): void {
        $gs = new GameState();
        $gs->addPlayer(new Player('p1', 'Alice'));
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        
        $this->assertCount(4, $gs->players[0]->hand);
        $this->assertCount(4, $gs->players[1]->hand);
    }
    
    public function testDrawDeckGivesCardToCurrentPlayer(): void {
        $gs = new GameState();
        $gs->addPlayer(new Player('p1', 'Alice'));
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        $gs->drawDeck('p1');
        $this->assertNotNull($gs->drawnCard);
        $this->assertEquals('p1', $gs->drawnCardOwnerId);
    }
    
    public function testDrawDiscardRequiresSwap(): void {
        $gs = new GameState();
        $gs->addPlayer(new Player('p1', 'Alice'));
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        // First draw a card to discard
        $gs->drawDeck('p1');
        $discardCard = $gs->drawnCard;
        $gs->discardDrawn('p1');
        
        // Now draw from discard - must swap
        $gs->drawDiscard('p1');
        $this->assertEquals(GameState::ACTION_SWAP, $gs->pendingAction);
    }
    
    public function testCallCaboSetsPhase(): void {
        $gs = new GameState();
        $gs->addPlayer(new Player('p1', 'Alice'));
        $gs->addPlayer(new Player('p2', 'Bob'));
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        $gs->callCabo('p1');
        $this->assertEquals(GameState::PHASE_CABO_CALLED, $gs->phase);
        $this->assertEquals('p1', $gs->caboCallerId);
    }
    
    public function testEndRoundCalculatesScores(): void {
        $gs = new GameState();
        $p1 = new Player('p1', 'Alice');
        $p2 = new Player('p2', 'Bob');
        $gs->addPlayer($p1);
        $gs->addPlayer($p2);
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        // Force specific cards for testing
        $p1->hand = [\Cambio\Game\Card::fromString('5♥'), \Cambio\Game\Card::fromString('3♦'), \Cambio\Game\Card::fromString('7♠'), \Cambio\Game\Card::fromString('2♣')];
        $p2->hand = [\Cambio\Game\Card::fromString('K♥'), \Cambio\Game\Card::fromString('Q♦'), \Cambio\Game\Card::fromString('J♠'), \Cambio\Game\Card::fromString('A♣')];
        
        $gs->callCabo('p1');
        $gs->endRound();
        
        $this->assertEquals(GameState::PHASE_ROUND_END, $gs->phase);
        $this->assertGreaterThan(0, $p1->totalScore);
        $this->assertGreaterThan(0, $p2->totalScore);
    }
    
    public function testCaboPenaltyAppliedWhenNotLowest(): void {
        $gs = new GameState(['caboPenalty' => 10, 'scoringVariant' => 'classic']);
        $p1 = new Player('p1', 'Alice');
        $p2 = new Player('p2', 'Bob');
        $gs->addPlayer($p1);
        $gs->addPlayer($p2);
        $gs->deal();
        $gs->phase = GameState::PHASE_PLAYING;
        
        // p1 has high hand, p2 has low hand
        $p1->hand = [\Cambio\Game\Card::fromString('K♥'), \Cambio\Game\Card::fromString('Q♦'), \Cambio\Game\Card::fromString('J♠'), \Cambio\Game\Card::fromString('10♣')];
        $p2->hand = [\Cambio\Game\Card::fromString('2♥'), \Cambio\Game\Card::fromString('3♦'), \Cambio\Game\Card::fromString('4♠'), \Cambio\Game\Card::fromString('5♣')];
        
        $gs->callCabo('p1'); // p1 calls cabo but has higher score
        $gs->endRound();
        
        // p1 should get penalty
        $p1RawScore = 10 + 10 + 10 + 10; // K+Q+J+10 = 40
        $expectedP1Score = $p1RawScore + 10; // + penalty
        $this->assertEquals($expectedP1Score, $p1->totalScore);
    }
    
    public function testVisibilityHidesSecretCards(): void {
        $gs = new GameState();
        $p1 = new Player('p1', 'Alice');
        $p2 = new Player('p2', 'Bob');
        $gs->addPlayer($p1);
        $gs->addPlayer($p2);
        $gs->deal();
        
        $state = $gs->toArray('p1');
        
        // p1 should see their own cards
        $this->assertNotNull($state['players'][0]['hand'][0]);
        
        // p2 should NOT see their cards from p1's perspective
        // (they should be null or hidden)
        $this->assertNull($state['players'][1]['hand'][0]);
    }
    
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
}

# Testing Guide

## Automated Tests

### PHPUnit

```bash
# Install dependencies
composer install

# Run all tests
vendor/bin/phpunit

# Run with coverage
vendor/bin/phpunit --coverage-html coverage
```

### Test Categories

#### Game Engine (tests/GameStateTest.php)
- Deck creation, shuffle, deal
- Initial peek (exactly 2 cards)
- Draw deck, discard, swap
- Special actions (peek, spy, swap)
- Cabo call, final turns, scoring
- Game over detection

#### Security/Visibility
- Player sees only own known cards
- Opponent cards hidden
- Drawn card only visible to owner
- Token validation

#### Lobby/Multiplayer
- Lobby creation
- Player join/leave
- Ready status
- Game start
- Status updates

## Manual Tests

### Singleplayer with 1 Bot
1. Start singleplayer
2. Select 1 bot
3. Initial peek
4. Play multiple turns
5. Draw from deck
6. Draw from discard
7. Call cabo
8. Round ends

### Hotseat with 2 Players
1. Start hotseat
2. Player 1 peek
3. Shield screen
4. Player 2 peek
5. Play turns
6. Call cabo
7. Final turns

### Online Multiplayer (2 Browsers)
1. Browser A: Create lobby
2. Browser B: Join with code
3. Both set ready
4. Host starts
5. Play turns
6. Reload both
7. Reconnect

### PWA Test
1. Clear site data
2. Reload page
3. Install PWA
4. Test offline
5. Check version

## CI/CD Tests

GitHub Actions runs:
- `composer validate`
- `composer install`
- `php -l` (syntax check)
- `node --check public/app.js`
- `vendor/bin/phpunit`
- Asset verification
- Docker build
- Docker push

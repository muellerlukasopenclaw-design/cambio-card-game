# Architecture

## Overview

Cambio is a PHP-based card game with a vanilla JavaScript frontend.

## Components

### Frontend (public/)
- `app.js` - Main game logic, API client, UI rendering
- `i18n.js` - Internationalization (de/en)
- `features.js` - Tutorial, stats, achievements, leaderboard
- `extras.js` - Chat, emoji, history, undo, save/load
- `styles.css` - All styling with CSS variables
- `service-worker.js` - PWA caching
- `index.html` - Main entry point

### Backend (src/)
- `Api/GameController.php` - Game actions, state management
- `Api/LobbyController.php` - Lobby CRUD, player management
- `Game/GameState.php` - Core game logic
- `Game/Player.php` - Player state, hand, known cards
- `Game/Card.php` - Card representation
- `Game/Deck.php` - Deck creation, shuffle, deal
- `Game/BotEngine.php` - Bot AI
- `Storage/Database.php` - SQLite persistence

### API Endpoints

#### Game
- `POST /api/game/create` - Create new game
- `GET /api/game/state` - Get game state
- `POST /api/game/action` - Perform action
- `POST /api/game/peek` - Initial peek
- `POST /api/game/new-round` - Start new round

#### Lobby
- `POST /api/lobby/create` - Create lobby
- `POST /api/lobby/join` - Join lobby
- `GET /api/lobby/state` - Get lobby state
- `POST /api/lobby/ready` - Set ready status
- `POST /api/lobby/start` - Start game
- `POST /api/lobby/leave` - Leave lobby
- `POST /api/lobby/bot` - Add/remove bot

## Data Flow

1. Frontend polls `/api/game/state` every 2 seconds
2. Actions sent via `POST /api/game/action`
3. State persisted in SQLite
4. Token-based authentication for all actions

## Security

- Token hash (SHA-256) stored in database
- No plaintext tokens in network
- XSS escaping in frontend
- CORS whitelist
- CSP headers

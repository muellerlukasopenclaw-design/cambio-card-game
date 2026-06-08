const http = require('http');
const fs = require('fs');
const path = require('path');
const { exec } = require('child_process');

const PORT = 9090;
const PUBLIC_DIR = path.join(__dirname, 'public');

const mimeTypes = {
  '.html': 'text/html',
  '.js': 'application/javascript',
  '.css': 'text/css',
  '.json': 'application/json',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.ico': 'image/x-icon',
  '.webmanifest': 'application/manifest+json'
};

const server = http.createServer((req, res) => {
  // CORS headers
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  
  if (req.method === 'OPTIONS') {
    res.writeHead(200);
    res.end();
    return;
  }

  console.log(`${req.method} ${req.url}`);

  // API requests
  if (req.url.startsWith('/api/')) {
    handleApi(req, res);
    return;
  }

  // Static files
  let filePath = path.join(PUBLIC_DIR, req.url === '/' ? 'index.html' : req.url);
  const ext = path.extname(filePath).toLowerCase();
  const contentType = mimeTypes[ext] || 'application/octet-stream';

  fs.readFile(filePath, (err, content) => {
    if (err) {
      if (err.code === 'ENOENT') {
        res.writeHead(404, { 'Content-Type': 'text/html' });
        res.end('<h1>404 Not Found</h1>', 'utf-8');
      } else {
        res.writeHead(500);
        res.end(`Server Error: ${err.code}`);
      }
    } else {
      res.writeHead(200, { 'Content-Type': contentType });
      res.end(content, 'utf-8');
    }
  });
});

function handleApi(req, res) {
  const url = new URL(req.url, `http://localhost:${PORT}`);
  const endpoint = url.pathname.replace('/api/', '');
  
  console.log(`API: ${endpoint}`);
  
  // For now, just return mock responses for testing
  if (endpoint === 'game/create' && req.method === 'POST') {
    let body = '';
    req.on('data', chunk => body += chunk);
    req.on('end', () => {
      try {
        const input = JSON.parse(body);
        console.log('Create game input:', input);
        
        // Mock response
        const response = {
          success: true,
          gameId: 'game_test_' + Date.now(),
          sessionToken: 'token_' + Math.random().toString(36).substring(2),
          sessionTokens: ['token_' + Math.random().toString(36).substring(2)],
          state: {
            phase: 'initial_peek',
            currentPlayer: 0,
            players: [
              { id: 'p1', name: input.playerName || 'Player', hand: [null, null, null, null], knownCards: [], isBot: false },
              { id: 'bot1', name: 'Bot 1', hand: [null, null, null, null], knownCards: [], isBot: true }
            ],
            discardPile: [{ suit: 'hearts', value: '5', numericValue: 5 }],
            deckSize: 48,
            yourPlayerId: 'p1',
            isYourTurn: false,
            round: 1,
            scores: {},
            caboCalled: false,
            caboCallerId: null,
            finalTurnsLeft: 0,
            gameOver: false,
            winner: null,
            lastAction: null,
            drawnCard: null,
            pendingAction: null,
            drawnCardOwnerId: null,
            canAct: false,
            turnPhase: 'initial_peek'
          }
        };
        
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify(response));
      } catch (e) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: false, error: e.message }));
      }
    });
    return;
  }
  
  if (endpoint === 'game/state' && req.method === 'GET') {
    const gameId = url.searchParams.get('gameId');
    
    const response = {
      success: true,
      state: {
        phase: 'initial_peek',
        currentPlayer: 0,
        players: [
          { id: 'p1', name: 'Player', hand: [null, null, null, null], knownCards: [], isBot: false },
          { id: 'bot1', name: 'Bot 1', hand: [null, null, null, null], knownCards: [], isBot: true }
        ],
        discardPile: [{ suit: 'hearts', value: '5', numericValue: 5 }],
        deckSize: 48,
        yourPlayerId: 'p1',
        isYourTurn: false,
        round: 1,
        scores: {},
        caboCalled: false,
        caboCallerId: null,
        finalTurnsLeft: 0,
        gameOver: false,
        winner: null,
        lastAction: null,
        drawnCard: null,
        pendingAction: null,
        drawnCardOwnerId: null,
        canAct: false,
        turnPhase: 'initial_peek'
      }
    };
    
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(response));
    return;
  }
  
  // Default: not found
  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ success: false, error: 'Unknown endpoint' }));
}

server.listen(PORT, () => {
  console.log(`Test server running at http://localhost:${PORT}/`);
  console.log(`Serving files from: ${PUBLIC_DIR}`);
});

/**
 * Cambio Card Game — Frontend Application
 * Handles: Singleplayer, Hotseat, Online Multiplayer, Lobby, Game Loop
 */

const API_BASE = '/api';

// ─── State ──────────────────────────────────────────────────────────
const state = {
    screen: 'start',
    playerId: null,
    sessionToken: null,
    lobbyId: null,
    gameId: null,
    gameState: null,
    isHost: false,
    gameToken: null,
    settings: {
        animations: true,
        sounds: false,
        darkMode: true,
        rules: 'classic',
        caboPenalty: 10,
        targetScore: 100
    },
    hotseat: {
        players: [],
        currentIndex: 0
    },
    pollInterval: null,
    eventSource: null,
    pendingSwapOwnIndex: null,
    buttonLocks: new Set()
};

// ─── Utilities ────────────────────────────────────────────────────────
const $ = (sel) => document.querySelector(sel);
const $$ = (sel) => document.querySelectorAll(sel);

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, c => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[c]));
}

function lockButton(action) {
    if (state.buttonLocks.has(action)) return false;
    state.buttonLocks.add(action);
    setTimeout(() => state.buttonLocks.delete(action), 2000);
    return true;
}

function showScreen(id) {
    $$('.screen').forEach(s => s.classList.remove('active'));
    const screen = $(`#screen-${id}`);
    if (screen) screen.classList.add('active');
    state.screen = id;
}

function toast(msg, type = 'info') {
    const container = $('#toast-container');
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.textContent = msg;
    container.appendChild(el);
    setTimeout(() => el.remove(), 3000);
}

async function api(path, method = 'GET', body = null) {
    let url = `${API_BASE}${path}`;
    const opts = { method, headers: { 'Content-Type': 'application/json' } };
    
    if (method === 'GET' && body) {
        url += '?' + new URLSearchParams(body).toString();
    } else if (body) {
        opts.body = JSON.stringify(body);
    }
    
    try {
        const res = await fetch(url, opts);
        const text = await res.text();
        const data = text ? JSON.parse(text) : {};
        if (!res.ok) {
            return { success: false, error: data.error || `HTTP ${res.status}` };
        }
        return data;
    } catch (e) {
        return { success: false, error: 'Netzwerkfehler' };
    }
}

function cardHtml(card, index = null, selectable = false) {
    const idx = index !== null ? ` data-index="${index}"` : '';
    if (!card) {
        return `<div class="card back${selectable ? ' selectable' : ''}"${idx}></div>`;
    }
    const cls = `card face ${card.isRed ? 'red' : 'black'}${selectable ? ' selectable' : ''}`;
    return `<div class="${cls}"${idx}>${card.display}${card.suit}</div>`;
}

function backCard(index = null, selectable = false) {
    const idx = index !== null ? ` data-index="${index}"` : '';
    return `<div class="card back${selectable ? ' selectable' : ''}"${idx}></div>`;
}

// ─── Settings ───────────────────────────────────────────────────────
function loadSettings() {
    try {
        const saved = JSON.parse(localStorage.getItem('cambio-settings') || '{}');
        Object.assign(state.settings, saved);
    } catch (e) {}
    applySettings();
}

function saveSettings() {
    localStorage.setItem('cambio-settings', JSON.stringify(state.settings));
}

function applySettings() {
    $('#setting-animations').checked = state.settings.animations;
    $('#setting-sounds').checked = state.settings.sounds;
    $('#setting-dark-mode').checked = state.settings.darkMode;
    $('#setting-rules').value = state.settings.rules;
    $('#setting-cabo-penalty').value = state.settings.caboPenalty;
    $('#setting-target').value = state.settings.targetScore;
    document.body.classList.toggle('dark', state.settings.darkMode);
}

// ─── Navigation ─────────────────────────────────────────────────────
function handleAction(action) {
    switch (action) {
        case 'singleplayer': showScreen('singleplayer'); break;
        case 'hotseat': showScreen('hotseat'); break;
        case 'multiplayer': showScreen('multiplayer'); break;
        case 'rules': showRules(); break;
        case 'settings': showScreen('settings'); break;
        case 'back': showScreen('start'); break;
        case 'start-singleplayer': startSingleplayer(); break;
        case 'start-hotseat': startHotseat(); break;
        case 'create-lobby': showScreen('create-lobby'); break;
        case 'join-lobby': showScreen('join-lobby'); break;
        case 'create-lobby-submit': createLobby(); break;
        case 'join-lobby-submit': joinLobby(); break;
        case 'copy-code': copyLobbyCode(); break;
        case 'start-game': startGame(); break;
        case 'leave-lobby': leaveLobby(); break;
        case 'add-player': addHotseatPlayer(); break;
        case 'ready': setReady(); break;
        case 'add-bot': addBot(); break;
        case 'draw-deck': actionDrawDeck(); break;
        case 'draw-discard': actionDrawDiscard(); break;
        case 'call-cabo': actionCallCabo(); break;
        case 'cancel': closeModal(); break;
    }
}

// ─── Singleplayer ────────────────────────────────────────────────────
async function startSingleplayer() {
    const name = $('#sp-name').value.trim() || 'Spieler';
    const botCount = parseInt($('#sp-bot-count').value);
    const difficulty = $('#sp-difficulty').value;

    const players = [{
        id: 'p_' + Math.random().toString(36).slice(2),
        name: name,
        is_bot: false,
        is_host: true
    }];

    const botNames = ['Alpha', 'Beta', 'Gamma', 'Delta'];
    for (let i = 0; i < botCount; i++) {
        players.push({
            id: 'bot_' + Math.random().toString(36).slice(2),
            name: botNames[i] + ' (Bot)',
            is_bot: true,
            bot_difficulty: difficulty,
            is_host: false
        });
    }

    state.playerId = players[0].id;
    state.isHost = true;

    const config = {
        targetScore: state.settings.targetScore,
        caboPenalty: state.settings.caboPenalty,
        scoringVariant: state.settings.rules
    };

    const res = await api('/game/create', 'POST', {
        lobbyId: 'local_' + Date.now(),
        playerId: players[0].id,
        config,
        players
    });

    if (res.success) {
        state.gameId = res.gameId;
        state.gameState = res.state;
        state.gameToken = res.sessionToken || null;
        // Save session to localStorage
        localStorage.setItem('cambio_session', JSON.stringify({
            gameId: state.gameId,
            playerId: state.playerId,
            sessionToken: state.gameToken,
            mode: 'singleplayer'
        }));
        showScreen('game');
        renderGame();
        startPolling();
    } else {
        toast(res.error || 'Fehler beim Starten', 'error');
    }
}

// ─── Hotseat ────────────────────────────────────────────────────────
function addHotseatPlayer() {
    const container = $('#hotseat-players');
    const count = container.querySelectorAll('.player-name').length + 1;
    if (count > 5) {
        toast('Maximal 5 Spieler', 'error');
        return;
    }
    const label = document.createElement('label');
    label.innerHTML = `Spieler ${count} <input type="text" class="player-name" value="Spieler ${count}" maxlength="20">`;
    container.appendChild(label);
}

async function startHotseat() {
    const inputs = $$('#hotseat-players .player-name');
    const players = Array.from(inputs).map((input, i) => ({
        id: 'p_' + Math.random().toString(36).slice(2),
        name: input.value.trim() || `Spieler ${i + 1}`,
        is_bot: false,
        is_host: i === 0
    }));

    state.hotseat.players = players;
    state.hotseat.currentIndex = 0;
    state.playerId = players[0].id;
    state.isHost = true;

    const config = {
        targetScore: state.settings.targetScore,
        caboPenalty: state.settings.caboPenalty,
        scoringVariant: state.settings.rules
    };

    const res = await api('/game/create', 'POST', {
        lobbyId: 'hotseat_' + Date.now(),
        playerId: players[0].id,
        config,
        players
    });

    if (res.success) {
        state.gameId = res.gameId;
        state.gameState = res.state;
        // Store all session tokens for hotseat players
        state.hotseat.tokens = res.sessionTokens || {};
        state.gameToken = state.hotseat.tokens[state.playerId] || res.sessionToken || null;
        // Save hotseat session for restore
        localStorage.setItem('cambio_session', JSON.stringify({
            gameId: state.gameId,
            playerId: state.playerId,
            sessionToken: state.gameToken,
            hotseatPlayers: state.hotseat.players,
            hotseatTokens: state.hotseat.tokens,
            mode: 'hotseat'
        }));
        showScreen('game');
        renderGame();
        if (state.gameState.phase === 'initial_peek') {
            showHotseatShield();
        }
    } else {
        toast(res.error || 'Fehler beim Starten', 'error');
    }
}

function showHotseatShield() {
    const current = state.hotseat.players[state.hotseat.currentIndex];
    $('#shield-player').textContent = current.name;
    $('#screen-shield').classList.remove('hidden');
    $('#screen-shield').classList.add('active');
}

function hideHotseatShield() {
    $('#screen-shield').classList.add('hidden');
    $('#screen-shield').classList.remove('active');
}

function nextHotseatPlayer() {
    state.hotseat.currentIndex = (state.hotseat.currentIndex + 1) % state.hotseat.players.length;
    state.playerId = state.hotseat.players[state.hotseat.currentIndex].id;
    // Update token for current player
    state.gameToken = state.hotseat.tokens[state.playerId] || null;
    showHotseatShield();
}

// ─── Lobby / Multiplayer ────────────────────────────────────────────
async function createLobby() {
    const name = $('#lobby-host-name').value.trim() || 'Host';
    const lobbyName = $('#lobby-name').value.trim() || 'Cambio-Runde';
    const maxPlayers = parseInt($('#lobby-max-players').value);

    const res = await api('/lobby/create', 'POST', {
        hostName: name,
        name: lobbyName,
        maxPlayers
    });

    if (res.success) {
        state.lobbyId = res.lobbyId;
        state.playerId = res.playerId;
        state.sessionToken = res.sessionToken;
        state.isHost = true;
        // Save lobby session for restore
        localStorage.setItem('cambio_session', JSON.stringify({
            lobbyId: state.lobbyId,
            playerId: state.playerId,
            sessionToken: state.sessionToken,
            isHost: true,
            mode: 'lobby'
        }));
        showLobby();
    } else {
        toast(res.error || 'Fehler', 'error');
    }
}

async function joinLobby() {
    const name = $('#join-name').value.trim() || 'Spieler';
    const code = $('#join-code').value.trim().toUpperCase();

    if (code.length !== 6) {
        toast('Code muss 6 Zeichen haben', 'error');
        return;
    }

    const res = await api('/lobby/join', 'POST', { name, code });

    if (res.success) {
        state.lobbyId = res.lobbyId;
        state.playerId = res.playerId;
        state.sessionToken = res.sessionToken;
        state.isHost = false;
        // Save lobby session for restore
        localStorage.setItem('cambio_session', JSON.stringify({
            lobbyId: state.lobbyId,
            playerId: state.playerId,
            sessionToken: state.sessionToken,
            isHost: false,
            mode: 'lobby'
        }));
        showLobby();
    } else {
        toast(res.error || 'Fehler', 'error');
    }
}

async function showLobby() {
    showScreen('lobby');
    $('#lobby-code').textContent = '----';
    $('#btn-start-game').disabled = true;
    await pollLobby();
    state.pollInterval = setInterval(pollLobby, 2000);
}

async function renderLobbyState() {
    // Alias for session restore - same as showLobby but without resetting UI
    await pollLobby();
    if (!state.pollInterval) {
        state.pollInterval = setInterval(pollLobby, 2000);
    }
}

async function pollLobby() {
    if (!state.lobbyId || !state.sessionToken) return;

    const res = await api('/lobby/state', 'GET', {
        lobbyId: state.lobbyId,
        token: state.sessionToken
    });

    if (!res.success) {
        toast(res.error || 'Lobby-Fehler', 'error');
        return;
    }

    const lobby = res.lobby;
    $('#lobby-title').textContent = lobby.name;
    $('#lobby-code').textContent = lobby.code;

    const list = $('#lobby-players');
    // Show human players
    let html = lobby.players.map(p => `
        <div class="player-item">
            <span>${escapeHtml(p.name)}${p.ready ? ' ✅' : ''}</span>
            <span class="${p.is_host ? 'host' : ''}">${p.is_host ? 'Host' : ''}</span>
            ${!p.is_bot && p.id === state.playerId ? '<button class="btn small" data-action="ready">Ready</button>' : ''}
        </div>
    `).join('');
    
    // Show bots
    html += lobby.bots.map(b => `
        <div class="player-item bot">
            <span>${escapeHtml(b.name)} (Bot — ${b.bot_difficulty || 'medium'})</span>
            ${lobby.isHost ? `<button class="btn small danger" data-action="remove-bot" data-bot-id="${b.id}">×</button>` : ''}
        </div>
    `).join('');
    
    list.innerHTML = html;

    state.isHost = lobby.isHost;
    const allReady = lobby.players.every(p => p.ready);
    const canStart = lobby.isHost && lobby.playerCount >= 2 && allReady && lobby.canJoin !== false;
    $('#btn-start-game').disabled = !canStart;
    
    // Show lobby info
    $('#lobby-info').textContent = `${lobby.playerCount}/${lobby.maxPlayers} Spieler${lobby.isFull ? ' (voll)' : ''}`;
    
    // Add bot button for host (only if not full)
    if (lobby.isHost && !lobby.isFull) {
        const botContainer = document.createElement('div');
        botContainer.className = 'bot-add-container';
        botContainer.innerHTML = `
            <select id="bot-difficulty" class="small">
                <option value="easy">Einfach</option>
                <option value="medium" selected>Mittel</option>
                <option value="hard">Schwer</option>
            </select>
            <button class="btn small" data-action="add-bot">+ Bot</button>
        `;
        list.appendChild(botContainer);
    }
    
    // Remove bot handler
    list.querySelectorAll('[data-action="remove-bot"]').forEach(btn => {
        btn.addEventListener('click', () => removeBot(btn.dataset.botId));
    });
    
    // Auto-transition to game when lobby status changes to playing
    if (lobby.status === 'playing' && !state.gameId) {
        clearInterval(state.pollInterval);
        if (lobby.gameId) {
            state.gameId = lobby.gameId;
            state.gameToken = state.sessionToken;
            // Fetch initial game state before showing game screen
            const gameRes = await api('/game/state', 'GET', {
                gameId: state.gameId,
                playerId: state.playerId,
                token: state.gameToken
            });
            if (gameRes.success) {
                state.gameState = gameRes.state;
            }
            showScreen('game');
            renderGame();
            startPolling();
        }
    }
}

function copyLobbyCode() {
    const code = $('#lobby-code').textContent;
    navigator.clipboard.writeText(code).then(() => toast('Code kopiert!'));
}

async function setReady() {
    const res = await api('/lobby/ready', 'POST', {
        lobbyId: state.lobbyId,
        playerId: state.playerId,
        ready: true,
        token: state.sessionToken
    });
    if (res.success) {
        toast('Bereit!');
    }
}

async function addBot() {
    if (!state.isHost) return;
    const difficulty = document.getElementById('bot-difficulty')?.value || 'medium';
    const res = await api('/lobby/add-bot', 'POST', {
        lobbyId: state.lobbyId,
        playerId: state.playerId,
        token: state.sessionToken,
        difficulty: difficulty
    });
    if (res.success) {
        toast('Bot hinzugefügt');
    } else {
        toast(res.error || 'Fehler', 'error');
    }
}

async function removeBot(botId) {
    if (!state.isHost || !botId) return;
    const res = await api('/lobby/remove-bot', 'POST', {
        lobbyId: state.lobbyId,
        playerId: state.playerId,
        token: state.sessionToken,
        botId: botId
    });
    if (res.success) {
        toast('Bot entfernt');
    } else {
        toast(res.error || 'Fehler', 'error');
    }
}

async function startGame() {
    if (!state.isHost) return;

    const res = await api('/lobby/start', 'POST', {
        lobbyId: state.lobbyId,
        playerId: state.playerId,
        token: state.sessionToken
    });

    if (res.success) {
        clearInterval(state.pollInterval);
        state.gameId = res.gameId;
        state.gameState = res.state;
        state.gameToken = state.sessionToken;
        showScreen('game');
        renderGame();
        startPolling();
    } else {
        toast(res.error || 'Fehler', 'error');
    }
}

async function leaveLobby() {
    if (state.lobbyId && state.playerId) {
        await api('/lobby/leave', 'POST', {
            lobbyId: state.lobbyId,
            playerId: state.playerId,
            token: state.sessionToken
        });
    }
    clearInterval(state.pollInterval);
    state.lobbyId = null;
    state.playerId = null;
    state.sessionToken = null;
    showScreen('start');
}

// ─── Game Rendering ─────────────────────────────────────────────────
function renderGame() {
    const gs = state.gameState;
    if (!gs) return;

    $('#round-info').textContent = `Runde ${gs.round}`;

    const ownPlayer = gs.players.find(p => p.id === state.playerId);
    const isMyTurn = gs.currentPlayerId === state.playerId;
    const isPlaying = gs.phase === 'playing' || gs.phase === 'cabo_called';
    const isInitialPeek = gs.phase === 'initial_peek';
    const isRoundEnd = gs.phase === 'round_end';
    const isGameOver = gs.phase === 'game_over';

    // Turn indicator
    const current = gs.players.find(p => p.id === gs.currentPlayerId);
    $('#turn-indicator').textContent = isGameOver
        ? '🏆 Spiel beendet!'
        : isRoundEnd
        ? 'Runde beendet'
        : isMyTurn
        ? 'Du bist dran!'
        : `${current?.name || '...'} ist dran`;

    // Score info
    const scores = gs.players.map(p => `${p.name}: ${p.totalScore}`).join(' | ');
    $('#score-info').textContent = scores;

    // Other players
    const others = gs.players.filter(p => p.id !== state.playerId);
    $('#other-players').innerHTML = others.map(p => `
        <div class="player-area ${p.id === gs.currentPlayerId ? 'active' : ''}" data-player-id="${p.id}">
            <span class="name">${escapeHtml(p.name)}</span>
            <div class="cards">${Array.from({length: p.cardCount}, (_, i) => backCard(i, isMyTurn && ['spy','swap'].includes(gs.pendingAction))).join('')}</div>
        </div>
    `).join('');

    // Deck & discard
    $('#deck-count').textContent = gs.deckRemaining;
    $('#discard').innerHTML = gs.topDiscard
        ? cardHtml(gs.topDiscard)
        : '<div class="card empty"></div>';

    // Own hand
    const ownHand = $('#own-hand');
    if (ownPlayer && ownPlayer.hand) {
        ownHand.innerHTML = ownPlayer.hand.map((c, i) => {
            const known = ownPlayer.knownCards?.find(k => k.index === i);
            const card = known ? known.card : null;
            const selectable = (isMyTurn && isPlaying && gs.pendingAction) || isInitialPeek;
            return cardHtml(card, i, selectable);
        }).join('');
    } else {
        ownHand.innerHTML = Array.from({length: 4}, (_, i) => backCard(i, isInitialPeek)).join('');
    }

    // Game actions
    const actions = $('#game-actions');
    if (isMyTurn && isPlaying && !gs.pendingAction) {
        actions.classList.remove('hidden');
    } else {
        actions.classList.add('hidden');
    }

    // Modal for pending actions
    if (isMyTurn && gs.pendingAction) {
        showPendingAction(gs.pendingAction, gs.drawnCard);
    } else {
        closeModal();
    }

    // Round end / Game over
    if (isRoundEnd || isGameOver) {
        showRoundEnd(gs);
    }
}

function showPendingAction(action, drawnCard) {
    const modal = $('#action-modal');
    const body = $('#modal-body');
    const title = $('#modal-title');

    modal.classList.remove('hidden');

    if (action === 'draw_deck' || action === 'draw_discard') {
        title.textContent = 'Was machst du mit der Karte?';
        const isDiscardDraw = action === 'draw_discard';
        body.innerHTML = `
            <div class="drawn-card">${cardHtml(drawnCard)}</div>
            <div class="modal-actions">
                <button class="btn primary" data-action="swap-modal">Mit Hand tauschen</button>
                ${!isDiscardDraw ? '<button class="btn secondary" data-action="discard-modal">Ablegen</button>' : ''}
            </div>
        `;
    } else if (action === 'peek') {
        title.textContent = '👁 Wähle eine Karte zum Anschauen';
        body.innerHTML = '<p>Tippe auf eine deiner Karten</p><button class="btn secondary" data-action="skip-action">Überspringen</button>';
    } else if (action === 'spy') {
        title.textContent = '🔍 Wähle eine gegnerische Karte';
        body.innerHTML = '<p>Tippe auf eine Karte eines Gegners</p><button class="btn secondary" data-action="skip-action">Überspringen</button>';
    } else if (action === 'swap') {
        title.textContent = '🔄 Wähle deine Karte und eine gegnerische Karte';
        body.innerHTML = '<p>Tippe zuerst auf deine Karte, dann auf eine gegnerische</p><button class="btn secondary" data-action="skip-action">Überspringen</button>';
    }
}

function closeModal() {
    $('#action-modal').classList.add('hidden');
}

function showRoundEnd(gs) {
    const modal = $('#action-modal');
    const body = $('#modal-body');
    const title = $('#modal-title');

    modal.classList.remove('hidden');

    if (gs.gameOver) {
        const winner = gs.winner;
        title.textContent = '🏆 Spiel beendet!';
        body.innerHTML = `
            <p>${escapeHtml(winner?.name || 'Unbekannt')} gewinnt!</p>
            <div class="final-scores">${gs.players.map(p => `
                <div>${escapeHtml(p.name)}: ${p.totalScore} Punkte</div>
            `).join('')}</div>
            <button class="btn primary" data-action="back">Zum Menü</button>
        `;
    } else {
        title.textContent = 'Runde beendet';
        const roundScores = gs.roundScores[gs.round] || {};
        body.innerHTML = `
            <div class="round-scores">${gs.players.map(p => `
                <div>${escapeHtml(p.name)}: +${roundScores[p.id] || 0} (Gesamt: ${p.totalScore})</div>
            `).join('')}</div>
            <button class="btn primary" data-action="next-round">Nächste Runde</button>
        `;
    }
}

// ─── Game Actions ───────────────────────────────────────────────────
async function actionDrawDeck() {
    await sendAction({ action: 'draw_deck' });
}

async function actionDrawDiscard() {
    await sendAction({ action: 'draw_discard' });
}

async function actionCallCabo() {
    await sendAction({ action: 'call_cabo' });
}

async function sendAction(action) {
    if (!lockButton(action.action || 'action')) return;
    const body = {
        gameId: state.gameId,
        playerId: state.playerId,
        action
    };
    if (state.gameToken) {
        body.token = state.gameToken;
    }
    const res = await api('/game/action', 'POST', body);

    if (res.success) {
        state.gameState = res.state;
        renderGame();
    } else {
        toast(res.error || 'Aktion nicht möglich', 'error');
    }
}

async function performInitialPeek(cardIndex) {
    const body = {
        gameId: state.gameId,
        playerId: state.playerId,
        cardIndex
    };
    if (state.gameToken) {
        body.token = state.gameToken;
    }
    const res = await api('/game/peek', 'POST', body);

    if (res.success) {
        state.gameState = res.state;
        renderGame();
        if (state.gameState.phase === 'initial_peek') {
            const current = state.gameState.players.find(p => p.id === state.gameState.currentPlayerId);
            if (current && current.isBot) {
                setTimeout(() => pollGame(), 500);
            }
        }
    } else {
        toast(res.error || 'Peek nicht möglich', 'error');
    }
}

async function startNewRound() {
    const body = {
        gameId: state.gameId,
        playerId: state.playerId
    };
    if (state.gameToken) {
        body.token = state.gameToken;
    }
    const res = await api('/game/new-round', 'POST', body);

    if (res.success) {
        state.gameState = res.state;
        renderGame();
    } else {
        toast(res.error || 'Fehler', 'error');
    }
}

// ─── Polling ────────────────────────────────────────────────────────
function startPolling() {
    if (state.pollInterval) clearInterval(state.pollInterval);
    state.pollInterval = setInterval(pollGame, 1500);
}

function startLobbyPolling() {
    if (state.pollInterval) clearInterval(state.pollInterval);
    state.pollInterval = setInterval(pollLobby, 2000);
}

function stopPolling() {
    if (state.pollInterval) {
        clearInterval(state.pollInterval);
        state.pollInterval = null;
    }
    if (state.eventSource) {
        state.eventSource.close();
        state.eventSource = null;
    }
}

async function pollGame() {
    if (!state.gameId) return;

    const params = {
        gameId: state.gameId,
        playerId: state.playerId
    };
    if (state.gameToken) {
        params.token = state.gameToken;
    }
    const res = await api('/game/state', 'GET', params);

    if (res.success) {
        const oldPhase = state.gameState?.phase;
        state.gameState = res.state;
        renderGame();

        // Hotseat shield
        if (state.gameState.phase === 'initial_peek' || state.gameState.phase === 'playing') {
            const current = state.gameState.players.find(p => p.id === state.gameState.currentPlayerId);
            if (current && !current.isBot) {
                const hotseatIndex = state.hotseat.players.findIndex(p => p.id === current.id);
                if (hotseatIndex !== -1 && hotseatIndex !== state.hotseat.currentIndex) {
                    state.hotseat.currentIndex = hotseatIndex;
                    state.playerId = current.id;
                    showHotseatShield();
                }
            }
        }

        // Auto-poll for bot turns
        const current = state.gameState.players.find(p => p.id === state.gameState.currentPlayerId);
        if (current && current.isBot && state.gameState.phase !== 'round_end' && state.gameState.phase !== 'game_over') {
            setTimeout(() => pollGame(), 800);
        }
    }
}

// ─── Event Handlers ───────────────────────────────────────────────────
function initEventListeners() {
    // Menu buttons
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-action]');
        if (!btn) return;

        const action = btn.dataset.action;

        if (action === 'swap-modal') {
            closeModal();
            // Enable card selection for swap
            $('#own-hand').classList.add('selecting');
            toast('Tippe auf eine Karte zum Tauschen');
            return;
        }

        if (action === 'discard-modal') {
            sendAction({ action: 'discard' });
            closeModal();
            return;
        }

        if (action === 'skip-action') {
            sendAction({ action: 'skip_action' });
            closeModal();
            return;
        }

        if (action === 'next-round') {
            startNewRound();
            closeModal();
            return;
        }

        handleAction(action);
    });

    // Card clicks
    $('#own-hand').addEventListener('click', (e) => {
        const card = e.target.closest('.card');
        if (!card || !card.dataset.index) return;

        const index = parseInt(card.dataset.index);
        const gs = state.gameState;
        if (!gs) return;

        const isMyTurn = gs.currentPlayerId === state.playerId;

        // Initial peek
        if (gs.phase === 'initial_peek' && isMyTurn) {
            performInitialPeek(index);
            return;
        }

        // Pending swap
        if (gs.pendingAction === 'draw_deck' || gs.pendingAction === 'draw_discard') {
            sendAction({ action: 'swap_with_hand', index });
            closeModal();
            return;
        }

        // Peek action card
        if (gs.pendingAction === 'peek' && isMyTurn) {
            sendAction({ action: 'peek', index });
            return;
        }

        // Swap action - select own card first
        if (gs.pendingAction === 'swap' && isMyTurn) {
            state.pendingSwapOwnIndex = index;
            $$('#own-hand .card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            toast('Wähle jetzt eine gegnerische Karte zum Tauschen');
            return;
        }
    });

    // Other player card clicks (for spy/swap)
    $('#other-players').addEventListener('click', (e) => {
        const card = e.target.closest('.card');
        if (!card || card.dataset.index === undefined) return;

        const gs = state.gameState;
        if (!gs || gs.currentPlayerId !== state.playerId) return;

        const playerArea = card.closest('.player-area');
        const targetId = playerArea.dataset.playerId;
        const targetPlayer = gs.players.find(p => p.id === targetId);
        if (!targetPlayer) return;

        const index = parseInt(card.dataset.index);

        if (gs.pendingAction === 'spy') {
            sendAction({ action: 'spy', targetId: targetPlayer.id, index });
            return;
        }

        if (gs.pendingAction === 'swap') {
            if (state.pendingSwapOwnIndex === null) {
                toast('Swap: Wähle zuerst eine deiner Karten');
                return;
            }
            sendAction({
                action: 'swap',
                ownIndex: state.pendingSwapOwnIndex,
                targetId: targetPlayer.id,
                targetIndex: index
            });
            state.pendingSwapOwnIndex = null;
            $$('#own-hand .card').forEach(c => c.classList.remove('selected'));
            return;
        }
    });

    // Hotseat shield tap
    $('#screen-shield').addEventListener('click', () => {
        hideHotseatShield();
    });

    // Settings changes
    $('#setting-animations').addEventListener('change', (e) => {
        state.settings.animations = e.target.checked;
        saveSettings();
    });
    $('#setting-sounds').addEventListener('change', (e) => {
        state.settings.sounds = e.target.checked;
        saveSettings();
    });
    $('#setting-dark-mode').addEventListener('change', (e) => {
        state.settings.darkMode = e.target.checked;
        saveSettings();
        applySettings();
    });
    $('#setting-rules').addEventListener('change', (e) => {
        state.settings.rules = e.target.value;
        saveSettings();
    });
    $('#setting-cabo-penalty').addEventListener('change', (e) => {
        state.settings.caboPenalty = parseInt(e.target.value);
        saveSettings();
    });
    $('#setting-target').addEventListener('change', (e) => {
        state.settings.targetScore = e.target.value === 'custom' ? 100 : parseInt(e.target.value);
        saveSettings();
    });
}

// ─── Rules ──────────────────────────────────────────────────────────
function showRules() {
    showScreen('rules');
    $('#rules-content').innerHTML = `
        <h3>Ziel</h3>
        <p>Minimiere den Wert deiner Karten. Der Spieler mit den wenigsten Punkten gewinnt.</p>
        
        <h3>Kartenwerte</h3>
        <ul>
            <li>A = 1 Punkt</li>
            <li>2-6 = Augenzahl</li>
            <li>7,8 = 7 Punkte (👁 Peek: eigene Karte anschauen)</li>
            <li>9,10 = 9 Punkte (🔍 Spy: fremde Karte anschauen)</li>
            <li>J,Q = 11 Punkte (🔄 Swap: Karten tauschen)</li>
            <li>K = 13 Punkte</li>
        </ul>
        
        <h3>Spielablauf</h3>
        <p>1. Jeder bekommt 4 Karten (2 davon kurz anschauen)</p>
        <p>2. Zieh vom Stapel oder nimm die oberste Ablagekarte</p>
        <p>3. Tausche mit deiner Hand oder lege ab (Aktion möglich)</p>
        <p>4. Sage "Cabo!" wenn du denkst, du hast die wenigsten Punkte</p>
        <p>5. Alle anderen bekommen noch einen Zug, dann Rundenende</p>
        
        <h3>Cabo-Strafe</h3>
        <p>Wer "Cabo" ruft aber NICHT die wenigsten Punkte hat, bekommt +10 Strafpunkte.</p>
        
        <h3>Rundenende</h3>
        <p>Der Spieler mit den wenigsten Punkten bekommt 0 Punkte (Klassisch-Modus). Alle anderen zählen ihre Karten.</p>
        
        <h3>Spielende</h3>
        <p>Wer 100+ Punkte hat, verliert. Der mit den wenigsten Punkten gewinnt.</p>
    `;
}

// ─── PWA Install ────────────────────────────────────────────────────
let deferredPrompt = null;

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    $('#pwa-install').classList.remove('hidden');
});

$('#btn-install')?.addEventListener('click', async () => {
    if (!deferredPrompt) return;
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    if (outcome === 'accepted') {
        $('#pwa-install').classList.add('hidden');
    }
    deferredPrompt = null;
});

// ─── Init ───────────────────────────────────────────────────────────
async function loadVersion() {
    try {
        const res = await fetch('/api/health');
        const data = await res.json();
        if (data.version) {
            $('#app-version').textContent = 'v' + data.version;
        }
    } catch (e) {}
}

function init() {
    loadSettings();
    initEventListeners();
    loadVersion();

    // Try to restore session from localStorage
    const saved = localStorage.getItem('cambio_session');
    if (saved) {
        try {
            const session = JSON.parse(saved);
            if (session.gameId && session.playerId && session.sessionToken) {
                state.gameId = session.gameId;
                state.playerId = session.playerId;
                state.sessionToken = session.sessionToken;
                state.gameToken = session.sessionToken;
                // Restore hotseat data if available
                if (session.mode === 'hotseat' && session.hotseatPlayers) {
                    state.hotseat.players = session.hotseatPlayers;
                    state.hotseat.tokens = session.hotseatTokens || {};
                    state.hotseat.currentIndex = 0;
                }
                showScreen('game');
                renderGame();
                startPolling();
                return;
            } else if (session.lobbyId && session.playerId && session.sessionToken) {
                state.lobbyId = session.lobbyId;
                state.playerId = session.playerId;
                state.sessionToken = session.sessionToken;
                state.isHost = session.isHost || false;
                showScreen('lobby');
                renderLobbyState();
                startLobbyPolling();
                return;
            }
        } catch (e) {
            console.error('Failed to restore session:', e);
        }
    }
    showScreen('start');

    // Register service worker
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/service-worker.js').catch(() => {});
    }
}

init();

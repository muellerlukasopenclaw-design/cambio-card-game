/**
 * Cambio Extras - Chat, Emoji, History, Undo, Save/Load, Export, Print, Share, Feedback
 */

// ─── In-Game Chat ────────────────────────────────────────────────────
const chatMessages = [];

function initChat() {
    const chatContainer = document.createElement('div');
    chatContainer.id = 'chat-container';
    chatContainer.className = 'chat-container hidden';
    chatContainer.innerHTML = `
        <div class="chat-header">
            <span>💬 Chat</span>
            <button class="btn small" onclick="toggleChat()">✕</button>
        </div>
        <div id="chat-messages" class="chat-messages"></div>
        <div class="chat-input">
            <input type="text" id="chat-input" placeholder="Nachricht..." maxlength="200">
            <button class="btn small" onclick="sendChatMessage()">➤</button>
        </div>
    `;
    document.body.appendChild(chatContainer);
    
    document.getElementById('chat-input').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') sendChatMessage();
    });
}

function toggleChat() {
    const chat = document.getElementById('chat-container');
    chat.classList.toggle('hidden');
}

function sendChatMessage() {
    const input = document.getElementById('chat-input');
    const text = input.value.trim();
    if (!text) return;
    
    const msg = {
        playerId: state.playerId,
        playerName: state.gameState?.players.find(p => p.id === state.playerId)?.name || 'Unbekannt',
        text,
        timestamp: Date.now()
    };
    
    chatMessages.push(msg);
    addChatMessage(msg);
    input.value = '';
    
    // In multiplayer, send to server
    if (state.lobbyId) {
        api('/lobby/chat', 'POST', {
            lobbyId: state.lobbyId,
            playerId: state.playerId,
            token: state.sessionToken,
            message: text
        }).catch(() => {});
    }
}

function addChatMessage(msg) {
    const container = document.getElementById('chat-messages');
    const el = document.createElement('div');
    el.className = 'chat-message';
    const isMe = msg.playerId === state.playerId;
    el.innerHTML = `
        <span class="chat-name ${isMe ? 'me' : ''}">${escapeHtml(msg.playerName)}:</span>
        <span class="chat-text">${escapeHtml(msg.text)}</span>
    `;
    container.appendChild(el);
    container.scrollTop = container.scrollHeight;
}

function receiveChatMessage(msg) {
    chatMessages.push(msg);
    addChatMessage(msg);
    // Show notification if chat is closed
    const chat = document.getElementById('chat-container');
    if (chat.classList.contains('hidden')) {
        toast(`💬 ${msg.playerName}: ${msg.text}`, 'info');
    }
}

// ─── Emoji Reactions ─────────────────────────────────────────────────
const emojiReactions = ['👍', '👎', '😂', '😮', '🎉', '🔥', '💀', '👏'];

function showEmojiPicker(targetId) {
    const picker = document.createElement('div');
    picker.className = 'emoji-picker';
    picker.innerHTML = emojiReactions.map(emoji => 
        `<button class="emoji-btn" onclick="sendReaction('${targetId}', '${emoji}'); this.closest('.emoji-picker').remove()">${emoji}</button>`
    ).join('');
    
    document.body.appendChild(picker);
    
    // Position near target
    const target = document.querySelector(`[data-player-id="${targetId}"]`);
    if (target) {
        const rect = target.getBoundingClientRect();
        picker.style.left = `${rect.left}px`;
        picker.style.top = `${rect.bottom + 5}px`;
    }
    
    // Close on click outside
    setTimeout(() => {
        document.addEventListener('click', function close(e) {
            if (!picker.contains(e.target)) {
                picker.remove();
                document.removeEventListener('click', close);
            }
        });
    }, 10);
}

function sendReaction(targetId, emoji) {
    const reaction = {
        from: state.playerId,
        to: targetId,
        emoji,
        timestamp: Date.now()
    };
    
    // Show locally
    showFloatingEmoji(targetId, emoji);
    
    // In multiplayer, send to server
    if (state.lobbyId) {
        api('/lobby/reaction', 'POST', {
            lobbyId: state.lobbyId,
            playerId: state.playerId,
            token: state.sessionToken,
            targetId,
            emoji
        }).catch(() => {});
    }
}

function showFloatingEmoji(targetId, emoji) {
    const target = document.querySelector(`[data-player-id="${targetId}"]`);
    if (!target) return;
    
    const el = document.createElement('div');
    el.className = 'floating-emoji';
    el.textContent = emoji;
    const rect = target.getBoundingClientRect();
    el.style.left = `${rect.left + rect.width / 2}px`;
    el.style.top = `${rect.top}px`;
    document.body.appendChild(el);
    
    setTimeout(() => el.remove(), 2000);
}

// ─── Game History ───────────────────────────────────────────────────
function saveGameHistory(gameState) {
    const history = JSON.parse(localStorage.getItem('cambio-history') || '[]');
    const entry = {
        id: gameState.id,
        date: new Date().toISOString(),
        players: gameState.players.map(p => ({ name: p.name, score: p.totalScore })),
        winner: gameState.players.reduce((a, b) => a.totalScore < b.totalScore ? a : b).name,
        rounds: gameState.round,
        mode: state.lobbyId ? 'multiplayer' : state.hotseat.players.length > 0 ? 'hotseat' : 'singleplayer'
    };
    history.unshift(entry);
    history.splice(20); // Keep last 20 games
    localStorage.setItem('cambio-history', JSON.stringify(history));
}

function showGameHistory() {
    const history = JSON.parse(localStorage.getItem('cambio-history') || '[]');
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content history-content">
            <h3>📜 Spielverlauf</h3>
            <div class="history-list">
                ${history.length === 0 ? '<p>Noch keine Spiele</p>' : history.map(h => `
                    <div class="history-entry">
                        <div class="history-date">${new Date(h.date).toLocaleDateString()}</div>
                        <div class="history-players">${h.players.map(p => `${p.name}: ${p.score}`).join(', ')}</div>
                        <div class="history-winner">🏆 ${h.winner}</div>
                        <div class="history-mode">${h.mode} • ${h.rounds} Runden</div>
                    </div>
                `).join('')}
            </div>
            <button class="btn secondary" onclick="this.closest('.modal').remove()">Schließen</button>
        </div>
    `;
    document.body.appendChild(modal);
}

// ─── Undo/Redo ─────────────────────────────────────────────────────
let gameHistoryStack = [];
let historyIndex = -1;
const MAX_HISTORY = 10;

function saveGameState() {
    if (!state.gameState) return;
    
    // Remove future states if we're not at the end
    if (historyIndex < gameHistoryStack.length - 1) {
        gameHistoryStack = gameHistoryStack.slice(0, historyIndex + 1);
    }
    
    gameHistoryStack.push(JSON.stringify(state.gameState));
    if (gameHistoryStack.length > MAX_HISTORY) {
        gameHistoryStack.shift();
    } else {
        historyIndex++;
    }
}

function canUndo() {
    return historyIndex > 0;
}

function canRedo() {
    return historyIndex < gameHistoryStack.length - 1;
}

function undo() {
    if (!canUndo()) return;
    historyIndex--;
    state.gameState = JSON.parse(gameHistoryStack[historyIndex]);
    renderGame();
    toast('↩️ Rückgängig', 'info');
}

function redo() {
    if (!canRedo()) return;
    historyIndex++;
    state.gameState = JSON.parse(gameHistoryStack[historyIndex]);
    renderGame();
    toast('↪️ Wiederherstellen', 'info');
}

// ─── Save/Load Game ──────────────────────────────────────────────────
function saveGameToSlot(slot) {
    if (!state.gameState) return;
    const saves = JSON.parse(localStorage.getItem('cambio-saves') || '{}');
    saves[slot] = {
        gameState: state.gameState,
        playerId: state.playerId,
        gameToken: state.gameToken,
        timestamp: Date.now()
    };
    localStorage.setItem('cambio-saves', JSON.stringify(saves));
    toast(`💾 Spiel gespeichert (Slot ${slot})`, 'success');
}

function loadGameFromSlot(slot) {
    const saves = JSON.parse(localStorage.getItem('cambio-saves') || '{}');
    const save = saves[slot];
    if (!save) {
        toast('Kein Spiel in diesem Slot', 'error');
        return;
    }
    state.gameState = save.gameState;
    state.playerId = save.playerId;
    state.gameToken = save.gameToken;
    renderGame();
    toast(`📂 Spiel geladen (Slot ${slot})`, 'success');
}

function showSaveLoadModal() {
    const saves = JSON.parse(localStorage.getItem('cambio-saves') || '{}');
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content">
            <h3>💾 Speichern / Laden</h3>
            <div class="save-slots">
                ${[1, 2, 3].map(slot => {
                    const save = saves[slot];
                    return `
                        <div class="save-slot">
                            <span>Slot ${slot}</span>
                            ${save ? `<span class="save-date">${new Date(save.timestamp).toLocaleString()}</span>` : '<span class="save-empty">Leer</span>'}
                            <div class="save-actions">
                                <button class="btn small" onclick="saveGameToSlot(${slot}); this.closest('.modal').remove()">Speichern</button>
                                ${save ? `<button class="btn small" onclick="loadGameFromSlot(${slot}); this.closest('.modal').remove()">Laden</button>` : ''}
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
            <button class="btn secondary" onclick="this.closest('.modal').remove()">Schließen</button>
        </div>
    `;
    document.body.appendChild(modal);
}

// ─── Export/Import ───────────────────────────────────────────────────
function exportGame() {
    if (!state.gameState) return;
    const data = {
        gameState: state.gameState,
        playerId: state.playerId,
        gameToken: state.gameToken,
        exportedAt: new Date().toISOString()
    };
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `cambio-save-${state.gameState.id}.json`;
    a.click();
    URL.revokeObjectURL(url);
    toast('📤 Spiel exportiert', 'success');
}

function importGame(file) {
    const reader = new FileReader();
    reader.onload = (e) => {
        try {
            const data = JSON.parse(e.target.result);
            state.gameState = data.gameState;
            state.playerId = data.playerId;
            state.gameToken = data.gameToken;
            renderGame();
            toast('📥 Spiel importiert', 'success');
        } catch (err) {
            toast('Fehler beim Importieren', 'error');
        }
    };
    reader.readAsText(file);
}

// ─── Print Scorecard ─────────────────────────────────────────────────
function printScorecard() {
    if (!state.gameState) return;
    const gs = state.gameState;
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head><title>Cambio - Spielergebnis</title>
        <style>
            body { font-family: sans-serif; padding: 20px; }
            h1 { text-align: center; }
            table { width: 100%; border-collapse: collapse; margin: 20px 0; }
            th, td { border: 1px solid #ddd; padding: 10px; text-align: center; }
            th { background: #f0f0f0; }
            .winner { background: #ffd700; font-weight: bold; }
        </style>
        </head>
        <body>
            <h1>🃏 Cambio - Spielergebnis</h1>
            <p>Spiel-ID: ${gs.id}</p>
            <p>Runden: ${gs.round}</p>
            <table>
                <tr><th>Spieler</th><th>Punkte</th></tr>
                ${gs.players.map(p => `
                    <tr class="${p.totalScore === Math.min(...gs.players.map(x => x.totalScore)) ? 'winner' : ''}">
                        <td>${p.name}</td>
                        <td>${p.totalScore}</td>
                    </tr>
                `).join('')}
            </table>
            <p style="text-align: center; color: #666;">Gedruckt am ${new Date().toLocaleString()}</p>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}

// ─── Share Results ───────────────────────────────────────────────────
async function shareResults() {
    if (!state.gameState) return;
    const gs = state.gameState;
    const winner = gs.players.reduce((a, b) => a.totalScore < b.totalScore ? a : b);
    const text = `🃏 Cambio Spielergebnis:\n${gs.players.map(p => `${p.name}: ${p.totalScore} Punkte`).join('\n')}\n\n🏆 Gewinner: ${winner.name}!`;
    
    if (navigator.share) {
        try {
            await navigator.share({ title: 'Cambio Ergebnis', text });
        } catch (e) {}
    } else {
        navigator.clipboard.writeText(text).then(() => toast('Ergebnis kopiert!', 'success'));
    }
}

// ─── Feedback Form ───────────────────────────────────────────────────
function showFeedbackForm() {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content">
            <h3>📝 Feedback</h3>
            <form id="feedback-form">
                <label>
                    Name (optional)
                    <input type="text" id="feedback-name" placeholder="Dein Name">
                </label>
                <label>
                    Bewertung
                    <select id="feedback-rating">
                        <option value="5">⭐⭐⭐⭐⭐ Hervorragend</option>
                        <option value="4">⭐⭐⭐⭐ Gut</option>
                        <option value="3">⭐⭐⭐ Okay</option>
                        <option value="2">⭐⭐ Schlecht</option>
                        <option value="1">⭐ Sehr schlecht</option>
                    </select>
                </label>
                <label>
                    Kommentar
                    <textarea id="feedback-text" rows="4" placeholder="Dein Feedback..."></textarea>
                </label>
                <div class="btn-group">
                    <button type="button" class="btn secondary" onclick="this.closest('.modal').remove()">Abbrechen</button>
                    <button type="submit" class="btn primary">Absenden</button>
                </div>
            </form>
        </div>
    `;
    document.body.appendChild(modal);
    
    document.getElementById('feedback-form').addEventListener('submit', (e) => {
        e.preventDefault();
        const feedback = {
            name: document.getElementById('feedback-name').value,
            rating: document.getElementById('feedback-rating').value,
            text: document.getElementById('feedback-text').value,
            timestamp: Date.now(),
            version: document.getElementById('app-version')?.textContent || 'unknown',
            userAgent: navigator.userAgent
        };
        
        // Store locally (could be sent to server)
        const feedbacks = JSON.parse(localStorage.getItem('cambio-feedback') || '[]');
        feedbacks.push(feedback);
        localStorage.setItem('cambio-feedback', JSON.stringify(feedbacks));
        
        toast('Feedback gespeichert! Danke!', 'success');
        modal.remove();
    });
}

// Export
window.extras = {
    initChat,
    toggleChat,
    sendChatMessage,
    receiveChatMessage,
    showEmojiPicker,
    sendReaction,
    showFloatingEmoji,
    saveGameHistory,
    showGameHistory,
    saveGameState,
    canUndo,
    canRedo,
    undo,
    redo,
    saveGameToSlot,
    loadGameFromSlot,
    showSaveLoadModal,
    exportGame,
    importGame,
    printScorecard,
    shareResults,
    showFeedbackForm
};

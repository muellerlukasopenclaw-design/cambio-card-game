/**
 * Cambio Features - Tutorial, Stats, Leaderboard, Achievements
 */

// ─── Tutorial ────────────────────────────────────────────────────────
const tutorialSteps = [
    {
        title: 'Willkommen bei Cambio!',
        text: 'Cambio ist ein taktisches Kartenspiel. Ziel ist es, die wenigsten Punkte zu haben.',
        highlight: null
    },
    {
        title: 'Deine Karten',
        text: 'Du hast 4 Karten. Am Anfang darfst du 2 davon kurz anschauen.',
        highlight: '#own-hand'
    },
    {
        title: 'Ziehen',
        text: 'Ziehe vom Stapel oder nimm die oberste Karte der Ablage.',
        highlight: '#table-center'
    },
    {
        title: 'Aktionen',
        text: 'Spezielle Karten erlauben Peek (eigene Karte anschauen), Spy (gegnerische Karte anschauen) oder Swap (Karten tauschen).',
        highlight: '#game-actions'
    },
    {
        title: 'Cabo!',
        text: 'Wenn du denkst, dass du die wenigsten Punkte hast, rufe "Cabo!". Aber Achtung: Wenn du falsch liegst, gibt es Strafpunkte!',
        highlight: null
    }
];

let tutorialIndex = 0;

function showTutorial() {
    tutorialIndex = 0;
    showTutorialStep();
}

function showTutorialStep() {
    const step = tutorialSteps[tutorialIndex];
    const modal = document.createElement('div');
    modal.className = 'tutorial-modal';
    modal.innerHTML = `
        <div class="tutorial-content">
            <h3>${step.title}</h3>
            <p>${step.text}</p>
            <div class="tutorial-progress">${tutorialIndex + 1} / ${tutorialSteps.length}</div>
            <div class="tutorial-buttons">
                ${tutorialIndex > 0 ? '<button class="btn secondary" onclick="prevTutorialStep()">Zurück</button>' : ''}
                <button class="btn primary" onclick="${tutorialIndex < tutorialSteps.length - 1 ? 'nextTutorialStep()' : 'closeTutorial()'}">${tutorialIndex < tutorialSteps.length - 1 ? 'Weiter' : 'Fertig'}</button>
            </div>
        </div>
    `;
    
    // Highlight element
    if (step.highlight) {
        const el = document.querySelector(step.highlight);
        if (el) {
            el.classList.add('tutorial-highlight');
            modal.dataset.highlight = step.highlight;
        }
    }
    
    document.body.appendChild(modal);
}

function nextTutorialStep() {
    closeTutorial();
    tutorialIndex++;
    showTutorialStep();
}

function prevTutorialStep() {
    closeTutorial();
    tutorialIndex--;
    showTutorialStep();
}

function closeTutorial() {
    const modal = document.querySelector('.tutorial-modal');
    if (modal) {
        const highlight = modal.dataset.highlight;
        if (highlight) {
            document.querySelector(highlight)?.classList.remove('tutorial-highlight');
        }
        modal.remove();
    }
    localStorage.setItem('cambio-tutorial-done', 'true');
}

function shouldShowTutorial() {
    return !localStorage.getItem('cambio-tutorial-done');
}

// ─── Statistics ─────────────────────────────────────────────────────
function loadStats() {
    try {
        return JSON.parse(localStorage.getItem('cambio-stats') || '{}');
    } catch (e) {
        return {};
    }
}

function saveStats(stats) {
    localStorage.setItem('cambio-stats', JSON.stringify(stats));
}

function recordGameResult(result) {
    const stats = loadStats();
    stats.gamesPlayed = (stats.gamesPlayed || 0) + 1;
    stats.gamesWon = (stats.gamesWon || 0) + (result.won ? 1 : 0);
    stats.totalScore = (stats.totalScore || 0) + result.score;
    stats.bestScore = Math.min(stats.bestScore || Infinity, result.score);
    stats.caboCalled = (stats.caboCalled || 0) + (result.caboCalled ? 1 : 0);
    stats.caboWon = (stats.caboWon || 0) + (result.caboWon ? 1 : 0);
    
    // Track by game mode
    const mode = result.mode || 'singleplayer';
    if (!stats.byMode) stats.byMode = {};
    if (!stats.byMode[mode]) stats.byMode[mode] = { played: 0, won: 0 };
    stats.byMode[mode].played++;
    if (result.won) stats.byMode[mode].won++;
    
    saveStats(stats);
    checkAchievements(stats);
}

function showStats() {
    const stats = loadStats();
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content stats-content">
            <h3>📊 Statistiken</h3>
            <div class="stats-grid">
                <div class="stat-item">
                    <span class="stat-value">${stats.gamesPlayed || 0}</span>
                    <span class="stat-label">Spiele gespielt</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value">${stats.gamesWon || 0}</span>
n                    <span class="stat-label">Spiele gewonnen</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value">${stats.bestScore || '-'}</span>
                    <span class="stat-label">Beste Punktzahl</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value">${stats.caboCalled || 0}</span>
                    <span class="stat-label">Cabo gerufen</span>
                </div>
            </div>
            <button class="btn secondary" onclick="this.closest('.modal').remove()">Schließen</button>
        </div>
    `;
    document.body.appendChild(modal);
}

// ─── Achievements ───────────────────────────────────────────────────
const achievements = [
    { id: 'first_win', name: 'Erster Sieg', desc: 'Gewinne dein erstes Spiel', icon: '🏆' },
    { id: 'cabo_master', name: 'Cabo-Meister', desc: 'Gewinne 10 Spiele mit Cabo', icon: '🎯' },
    { id: 'low_scorer', name: 'Punktejäger', desc: 'Erreiche unter 10 Punkte in einer Runde', icon: '⭐' },
    { id: 'veteran', name: 'Veteran', desc: 'Spiele 50 Spiele', icon: '🎖️' },
    { id: 'perfectionist', name: 'Perfektionist', desc: 'Gewinne mit 0 Punkten', icon: '💎' },
    { id: 'swap_master', name: 'Tausch-Meister', desc: 'Tausche 20 Karten erfolgreich', icon: '🔄' },
];

function checkAchievements(stats) {
    const unlocked = JSON.parse(localStorage.getItem('cambio-achievements') || '[]');
    const newAchievements = [];
    
    if (stats.gamesWon >= 1 && !unlocked.includes('first_win')) {
        newAchievements.push('first_win');
    }
    if (stats.caboWon >= 10 && !unlocked.includes('cabo_master')) {
        newAchievements.push('cabo_master');
    }
    if (stats.bestScore <= 10 && !unlocked.includes('low_scorer')) {
        newAchievements.push('low_scorer');
    }
    if (stats.gamesPlayed >= 50 && !unlocked.includes('veteran')) {
        newAchievements.push('veteran');
    }
    if (stats.bestScore === 0 && !unlocked.includes('perfectionist')) {
        newAchievements.push('perfectionist');
    }
    
    if (newAchievements.length > 0) {
        unlocked.push(...newAchievements);
        localStorage.setItem('cambio-achievements', JSON.stringify(unlocked));
        newAchievements.forEach(id => {
            const ach = achievements.find(a => a.id === id);
            if (ach) toast(`🏆 Erfolg freigeschaltet: ${ach.name}!`, 'success');
        });
    }
}

function showAchievements() {
    const unlocked = JSON.parse(localStorage.getItem('cambio-achievements') || '[]');
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content achievements-content">
            <h3>🏆 Erfolge</h3>
            <div class="achievements-list">
                ${achievements.map(ach => `
                    <div class="achievement ${unlocked.includes(ach.id) ? 'unlocked' : 'locked'}">
                        <span class="achievement-icon">${ach.icon}</span>
                        <div class="achievement-info">
                            <span class="achievement-name">${ach.name}</span>
                            <span class="achievement-desc">${ach.desc}</span>
                        </div>
                        ${unlocked.includes(ach.id) ? '✅' : '🔒'}
                    </div>
                `).join('')}
            </div>
            <button class="btn secondary" onclick="this.closest('.modal').remove()">Schließen</button>
        </div>
    `;
    document.body.appendChild(modal);
}

// ─── Leaderboard (Local) ────────────────────────────────────────────
function addLeaderboardEntry(name, score, mode) {
    const entries = JSON.parse(localStorage.getItem('cambio-leaderboard') || '[]');
    entries.push({
        name,
        score,
        mode,
        date: new Date().toISOString()
    });
    // Keep only top 50
    entries.sort((a, b) => a.score - b.score);
    entries.splice(50);
    localStorage.setItem('cambio-leaderboard', JSON.stringify(entries));
}

function showLeaderboard() {
    const entries = JSON.parse(localStorage.getItem('cambio-leaderboard') || '[]');
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content leaderboard-content">
            <h3>🏅 Bestenliste</h3>
            <div class="leaderboard-list">
                ${entries.length === 0 ? '<p>Noch keine Einträge</p>' : entries.slice(0, 20).map((entry, i) => `
                    <div class="leaderboard-entry">
                        <span class="rank">${i + 1}.</span>
                        <span class="name">${entry.name}</span>
                        <span class="score">${entry.score} Punkte</span>
                        <span class="mode">${entry.mode}</span>
                    </div>
                `).join('')}
            </div>
            <button class="btn secondary" onclick="this.closest('.modal').remove()">Schließen</button>
        </div>
    `;
    document.body.appendChild(modal);
}

// Export
window.features = {
    showTutorial,
    shouldShowTutorial,
    showStats,
    recordGameResult,
    showAchievements,
    showLeaderboard,
    addLeaderboardEntry
};

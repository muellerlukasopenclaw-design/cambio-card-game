/**
 * Cambio i18n - Internationalization
 * Supported: de (default), en
 */

const i18n = {
    de: {
        appName: 'Cambio',
        tagline: 'Das taktische Kartenspiel',
        singleplayer: 'Gegen Bots',
        hotseat: 'Lokal (Hotseat)',
        multiplayer: 'Online Multiplayer',
        rules: 'Spielregeln',
        settings: 'Einstellungen',
        start: 'Starten',
        back: 'Zurück',
        yourTurn: 'Du bist dran!',
        waiting: 'Warte auf Zug...',
        roundEnd: 'Runde beendet',
        gameOver: 'Spiel beendet!',
        drawDeck: 'Stapel ziehen',
        drawDiscard: 'Ablage nehmen',
        callCabo: 'Cabo!',
        swap: 'Tauschen',
        discard: 'Ablegen',
        skip: 'Überspringen',
        peek: 'Anschauen',
        spy: 'Spionieren',
        ready: 'Bereit',
        notReady: 'Nicht bereit',
        waitingForPlayers: 'Warte auf Spieler...',
        lobbyCode: 'Code',
        copy: 'Kopieren',
        leave: 'Verlassen',
        addBot: 'Bot hinzufügen',
        removeBot: 'Bot entfernen',
        startGame: 'Spiel starten',
        player: 'Spieler',
        bot: 'Bot',
        score: 'Punkte',
        total: 'Gesamt',
        round: 'Runde',
        winner: 'gewinnt!',
        newRound: 'Nächste Runde',
        toMenu: 'Zum Menü',
        settingsAnimations: 'Animationen',
        settingsSounds: 'Sounds',
        settingsDarkMode: 'Dark Mode',
        settingsRules: 'Regelvariante',
        settingsCaboPenalty: 'Cabo-Strafe',
        settingsTarget: 'Zielpunktzahl',
        error: 'Fehler',
        networkError: 'Netzwerkfehler — bitte Verbindung prüfen',
        invalidToken: 'Ungültiges Token',
        lobbyNotFound: 'Lobby nicht gefunden',
        gameNotFound: 'Spiel nicht gefunden',
    },
    en: {
        appName: 'Cambio',
        tagline: 'The tactical card game',
        singleplayer: 'vs Bots',
        hotseat: 'Local (Hotseat)',
        multiplayer: 'Online Multiplayer',
        rules: 'Rules',
        settings: 'Settings',
        start: 'Start',
        back: 'Back',
        yourTurn: 'Your turn!',
        waiting: 'Waiting for turn...',
        roundEnd: 'Round ended',
        gameOver: 'Game over!',
        drawDeck: 'Draw from deck',
        drawDiscard: 'Take from discard',
        callCabo: 'Cabo!',
        swap: 'Swap',
        discard: 'Discard',
        skip: 'Skip',
        peek: 'Peek',
        spy: 'Spy',
        ready: 'Ready',
        notReady: 'Not ready',
        waitingForPlayers: 'Waiting for players...',
        lobbyCode: 'Code',
        copy: 'Copy',
        leave: 'Leave',
        addBot: 'Add Bot',
        removeBot: 'Remove Bot',
        startGame: 'Start Game',
        player: 'Player',
        bot: 'Bot',
        score: 'Score',
        total: 'Total',
        round: 'Round',
        winner: 'wins!',
        newRound: 'Next Round',
        toMenu: 'To Menu',
        settingsAnimations: 'Animations',
        settingsSounds: 'Sounds',
        settingsDarkMode: 'Dark Mode',
        settingsRules: 'Rules Variant',
        settingsCaboPenalty: 'Cabo Penalty',
        settingsTarget: 'Target Score',
        error: 'Error',
        networkError: 'Network error — please check connection',
        invalidToken: 'Invalid token',
        lobbyNotFound: 'Lobby not found',
        gameNotFound: 'Game not found',
    }
};

let currentLang = 'de';

function setLanguage(lang) {
    if (i18n[lang]) {
        currentLang = lang;
        localStorage.setItem('cambio-lang', lang);
        updateUI();
    }
}

function t(key) {
    return i18n[currentLang]?.[key] || i18n.de[key] || key;
}

function updateUI() {
    // Update all elements with data-i18n attribute
    document.querySelectorAll('[data-i18n]').forEach(el => {
        const key = el.dataset.i18n;
        if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
            el.placeholder = t(key);
        } else {
            el.textContent = t(key);
        }
    });
}

function initLanguage() {
    const saved = localStorage.getItem('cambio-lang');
    if (saved && i18n[saved]) {
        currentLang = saved;
    }
    updateUI();
}

// Export for app.js
window.i18n = { t, setLanguage, initLanguage };

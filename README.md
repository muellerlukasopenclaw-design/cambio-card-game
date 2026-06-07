# Cambio Card Game

Ein browserbasiertes Kartenspiel im Stil von "Cabo" als Progressive Web App (PWA).

## Features

- **Einzelspieler gegen Bots** (3 Schwierigkeitsgrade)
- **Lokaler Hotseat-Modus** (mehrere Spieler am gleichen Gerät)
- **Online-Multiplayer** über Lobby-Codes
- **Gemischte Modi** (Menschen + Bots)
- **PWA-fähig** (installierbar, Offline-Einzelspieler)
- **Responsive Design** (Smartphone-optimiert, Desktop-fähig)
- **Dark Mode**

## Spielregeln

Siehe [docs/rules.md](docs/rules.md)

## Technischer Stack

- **Frontend:** Vanilla JavaScript, HTML5, CSS3
- **Backend:** PHP 8.x
- **Datenbank:** SQLite
- **Echtzeit:** Server-Sent Events (SSE)
- **Container:** Nginx + PHP-FPM
- **CI/CD:** GitHub Actions → GHCR

## Lokale Entwicklung

```bash
# Mit Docker Compose
docker-compose up -d

# Oder direkt mit PHP-Server (nur Frontend/Tests)
cd public && php -S localhost:8080
```

## Deployment

Siehe [docs/deployment.md](docs/deployment.md)

## Lizenz

MIT

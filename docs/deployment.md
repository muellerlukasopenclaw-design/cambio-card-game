# Deployment Guide

## Portainer Stack ( empfohlen)

```yaml
version: '3.8'

services:
  cambio:
    image: ghcr.io/muellerlukasopenclaw-design/cambio-card-game:v0.1.23
    container_name: cambio-card-game
    restart: unless-stopped
    ports:
      - "6255:80"
    volumes:
      - cambio-data:/var/www/html/data
    environment:
      - PHP_MEMORY_LIMIT=256M
      - PHP_MAX_EXECUTION_TIME=30

volumes:
  cambio-data:
```

## Nginx Proxy Manager

- **Domain:** `cabo.müller-lukas.de`
- **Forward Hostname:** `192.168.178.84`
- **Forward Port:** `6255`
- **Scheme:** `http`

## Health Check

```bash
curl https://cabo.müller-lukas.de/api/health
```

Erwartete Antwort:
```json
{"status":"ok","version":"0.1.23"}
```

## Updates

1. Neues Image bauen (GitHub Actions)
2. Portainer Stack neu deployen
3. Container neu erstellen (nicht nur neustarten)
4. `/api/health` prüfen

## Cache-Invalidierung

- Service Worker: `CACHE_NAME` in `service-worker.js` anpassen
- Assets: `?v=VERSION` Query-Parameter nutzen
- Docker: `no-cache` bei Build-Problemen

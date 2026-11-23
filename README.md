# Nachrichten aus Berlin 🌻 Mitte (Sumpfberichte)

Statischer RSS-Nachrichtenaggregator für Berlin und Berlin-Mitte mit automatischem stündlichem Feed-Abruf über **GitHub Actions** und Hosting über **GitHub Pages**.

## Wie es funktioniert

- **`fetchFeedsWithProxy.php`** läuft stündlich per GitHub Action (`.github/workflows/update-feeds.yml`), ruft alle Quellen aus `feeds.config.json` parallel ab, akkumuliert Artikel additiv in `feeds_berlin.json` & `feeds_mitte.json` und protokolliert den Zustand in `logs/feed_status.json`.
- **GitHub Pages** veröffentlicht ausschließlich die öffentlichen Frontend-Dateien (`index.html`, `styles.css`, `rss-feeds.js`, Icons, `robots.txt`, `feeds_*.json`). Interne Dateien (`fetchFeedsWithProxy.php`, `feeds.config.json`, `logs/`) werden nicht ausgeliefert.
- **Feed-Monitoring:** Im GitHub-Reiter **Actions** zeigt jeder Lauf eine übersichtliche Markdown-Tabelle aller fehlerhaften, leeren oder veralteten Quellen. Fällt eine Quelle 3× in Folge aus, erscheint automatisch eine GitHub-Actions-Warnung.

## Einrichtung auf GitHub Pages

1. Im GitHub-Repository **Settings → Pages** öffnen.
2. Unter **Build and deployment → Source** auf **GitHub Actions** stellen.
3. Unter **Settings → Actions → General → Workflow permissions** prüfen, dass **Read and write permissions** aktiviert sind (damit die Action die aktualisierten JSON-Dateien committen kann).
4. Optional unter **Settings → Pages → Custom domain** eine eigene Domain eintragen und **Enforce HTTPS** aktivieren.
5. Im Reiter **Actions → Update Feeds & Deploy Pages** einmal auf **Run workflow** klicken, um den ersten Lauf sofort zu starten.

## Quellen pflegen & lokal testen

- **Quellen & Schlagwörter bearbeiten:** in `feeds.config.json` (nach `git push` aktualisiert GitHub Actions automatisch).
- **Lokal testen:**
  ```bash
  php fetchFeedsWithProxy.php
  php fetchFeedsWithProxy.php --status
  ```

# Nachrichten aus Berlin 🌻 Mitte (Sumpfberichte)

Statischer RSS-Nachrichtenaggregator für Berlin und Berlin-Mitte mit automatischem stündlichem Feed-Abruf über **GitHub Actions** und Hosting über **GitHub Pages** (inkl. eigener Domain bei Strato).

## Wie es funktioniert

- **`fetchFeedsWithProxy.php`** läuft stündlich per GitHub Action (`.github/workflows/update-feeds.yml`), ruft alle Quellen aus `feeds.config.json` parallel ab, akkumuliert Artikel additiv in `feeds_berlin.json` & `feeds_mitte.json` und protokolliert den Zustand in `logs/feed_status.json`.
- **GitHub Pages** veröffentlicht ausschließlich die öffentlichen Frontend-Dateien (`index.html`, `styles.css`, `rss-feeds.js`, Icons, `robots.txt`, `feeds_*.json`). Interne Dateien (`fetchFeedsWithProxy.php`, `feeds.config.json`, `logs/`) werden nicht ausgeliefert.
- **Feed-Monitoring:** Im GitHub-Reiter **Actions** zeigt jeder Lauf eine übersichtliche Markdown-Tabelle aller fehlerhaften, leeren oder veralteten Quellen. Fällt eine Quelle 3× in Folge aus, erscheint automatisch eine GitHub-Actions-Warnung.

---

## Einrichtung auf GitHub Pages + Strato-Domain

### 1. Repository auf GitHub anlegen & hochladen
```bash
git init -b main
git add .
git commit -m "Initial commit"
git remote add origin https://github.com/<DEIN-USER>/<DEIN-REPO>.git
git push -u origin main
```
*(GitHub Pages ist für öffentliche Repositories kostenlos; für private Repositories wird GitHub Pro benötigt.)*

### 2. GitHub Pages aktivieren
1. Im GitHub-Repository: **Settings → Pages** öffnen.
2. Unter **Build and deployment → Source** auf **GitHub Actions** stellen.
3. Unter **Settings → Actions → General → Workflow permissions** prüfen, dass **Read and write permissions** aktiviert sind (damit die Action die aktualisierten JSON-Dateien committen kann).
4. Im Reiter **Actions → Update Feeds & Deploy Pages** einmal auf **Run workflow** klicken, um den ersten Lauf sofort zu starten.

### 3. Eigene Domain bei Strato verbinden

#### Variante A: Subdomain (z. B. `news.deinedomain.de`) – am einfachsten
1. Im **Strato Kunden-Login → Domains → DNS-Einstellungen** für die Subdomain einen **CNAME-Record** setzen:
   - Host: `news` (deine Subdomain)
   - Wert / Ziel: `<DEIN-USER>.github.io.`
2. In GitHub unter **Settings → Pages → Custom domain** `news.deinedomain.de` eintragen, speichern und nach DNS-Prüfung **Enforce HTTPS** aktivieren.
3. Optional eine Datei `CNAME` im Repo-Root mit exakt einer Zeile (`news.deinedomain.de`) anlegen – der Workflow kopiert sie automatisch mit.

#### Variante B: Hauptdomain (z. B. `deinedomain.de`)
1. Im **Strato Kunden-Login → Domains → DNS-Einstellungen** für die Hauptdomain diese vier **A-Records** von GitHub Pages setzen (und ggf. bestehende Strato-Webspace-A-Records entfernen):
   - `185.199.108.153`
   - `185.199.109.153`
   - `185.199.110.153`
   - `185.199.111.153`
2. Optional für IPv6 zusätzlich diese **AAAA-Records**:
   - `2606:50c0:8000::153`
   - `2606:50c0:8001::153`
   - `2606:50c0:8002::153`
   - `2606:50c0:8003::153`
3. In GitHub unter **Settings → Pages → Custom domain** `deinedomain.de` eintragen und **Enforce HTTPS** aktivieren.

---

## Lokale Nutzung / Quellen pflegen

- **Quellen & Schlagwörter bearbeiten:** in `feeds.config.json` (nach `git push` aktualisiert GitHub Actions automatisch).
- **Lokal testen:**
  ```bash
  php fetchFeedsWithProxy.php
  php fetchFeedsWithProxy.php --status
  ```

# Nachrichten aus Berlin 🌻 Mitte (Sumpfberichte)

Ein leichtgewichtiger, statischer RSS-Nachrichtenaggregator für aktuelle politische und lokale Meldungen aus **Berlin** und **Berlin-Mitte**.

## Funktionen

- **Zwei Regionen:** Wechsel zwischen **Berlin** (letzte 5 Tage) und **Berlin-Mitte** (letzte 10 Tage).
- **Zwei Ansichten:** Sortierung nach **Datum** (chronologisch pro Tag) oder nach **Quelle** (Übersicht pro Medium) mit auf- und zuklappbaren Quellenlisten.
- **Schlagwort-Radar:** Hebt Artikel zu relevanten lokalen und politischen Themen automatisch hervor und sammelt sie pro Tag in einem eigenen Radar-Block – per **🎯 Radar**-Button lassen sich auch ausschließlich Treffer anzeigen.
- **Automatische Aktualisierung:** Ein stündlicher GitHub-Actions-Workflow ruft alle RSS-/Atom-Feeds parallel ab, führt Artikel additiv zusammen, verhindert Duplikate bei nachträglich geänderten Überschriften und veröffentlicht die statische Seite automatisch auf GitHub Pages.
- **Quellen-Monitoring:** Fehlerhafte, leere oder veraltete Feeds werden automatisch protokolliert und in der GitHub-Actions-Zusammenfassung gemeldet.
- **Teilbare Ansichten & Komfort:** Die gewählte Region und Ansicht bleiben über `localStorage` und URL-Hash (z. B. `#mitte/quelle`) erhalten; gelesene Artikel werden dezent markiert.

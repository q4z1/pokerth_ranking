# PokerTH Ranking

Laravel-App hinter **pokerth.net**: Ranglisten & Statistiken, Download-Seite,
Server-Log-Auswertung, Cloudflare-Schutzautomatik und Sitemap-Generator.
Erreichbar unter `/pthranking`, teilt sich Datenbank und Web-Root mit dem
phpBB-Forum. Deploy = `git pull` (+ ggf. `npm run build`); es gibt keinen
separaten Build-/Release-Schritt, Änderungen sind sofort live.

## Setup & Build

- PHP 8.2+, Composer, Node
- `composer install`
- Frontend (Vue 3 + Element Plus, Vite):
  - `npm install`
  - `npm run build` – Ausgabe nach `public/js` und `public/css`
    (beide gitignored, werden direkt ausgeliefert)
  - `npm run dev` für lokale Entwicklung
- `.env` wie bei Laravel üblich. Die Downloads/Assets des Forums liegen unter
  `base_path()/../download` bzw. `.../images`.

## Artisan-Befehle (projekteigen)

`php artisan list` zeigt alles, `php artisan schedule:list` den Ist-Zustand des
Schedulers. Die folgenden Befehle sind projektspezifisch – der Rest ist
Framework-Standard.

| Befehl | Zweck | Optionen | Takt |
| --- | --- | --- | --- |
| `attack:check` | Trefferrate beobachten und den Cloudflare-„Under Attack"-Modus automatisch ein-/ausschalten | `--enable`, `--disable` (von Hand schalten statt messen) | alle 5 Min |
| `downloads:sync` | Neuestes PokerTH-Release von GitHub holen und den Download-Snapshot schreiben | `--dry-run` (nur anzeigen) | täglich 04:40 |
| `sitemap:generate` | `sitemap.xml` für das Forum bauen | `--output=<pfad>`, `--dry-run` | 04:20 & 16:20 |
| `avatars:purge-blacklisted` | Dateien bereits gesperrter Avatare aus dem öffentlichen Verzeichnis in die Quarantäne schieben | `--dry-run` | manuell |
| `logs:rotate-nginx` | Zu große nginx-Logs komprimieren und leeren (copytruncate-Stil) | `--dir=`, `--max-size=<MB>`, `--keep=<n>`, `--dry-run` | manuell (siehe Hinweis) |
| `bbc:gamedates` | Spieltermine für BBC automatisch anlegen | – | manuell |
| `ranking:fix` | Platzhalter für Ad-hoc-Neuberechnung des Rankings; Logik im Body ist auskommentiert und wird pro Einsatz angepasst | – | manuell / Entwicklung |
| `season:switch` | Platzhalter (`handle()` gibt 0 zurück) – **nicht benutzen** | – | – |

### Details

**`attack:check`** – misst die Request-Rate (nginx-Log) und aktiviert bei einem
Angriff automatisch die Cloudflare-Under-Attack-Regel; fällt die Rate wieder,
wird sie zurückgenommen. Zustand liegt in `storage/app` (`filter_window_until.txt`
u. a.). `--enable`/`--disable` überschreiben die Messung manuell.

**`downloads:sync`** – ruft die GitHub-API (`pokerth/pokerth`, Release „latest")
ab und legt `storage/app/downloads-latest.json` an. Die Download-Seite
(Route `/downloads/all`, `DownloadsController::allversions`) rendert
ausschließlich diesen Snapshot – es gibt **keine selbstgehosteten
Client-Installer** mehr. Bei API-Ausfall bleibt der vorhandene Snapshot
unangetastet. Nach einem Upstream-Release einfach einmal aufrufen; sonst
erledigt das der tägliche Lauf. Logik in `app/Services/GithubReleases.php`.

**`sitemap:generate`** – nimmt nur auf, was ein Gast sehen darf (Gäste-ACL aus
`phpbb_acl_groups`). Die Schwesterseiten (`monthlycup`, `bbc`, `wec`) bringen
ihren eigenen Generator mit und werden vom selben Scheduler zeitversetzt
angestoßen.

**`logs:rotate-nginx`** – rotiert nur Dateien über der Größenschwelle; ein
Leerlauf kostet nichts. ⚠️ In `app/Console/Kernel.php` ist der Befehl als
stündlich eingetragen, aber `Kernel.php` wird unter Laravel 12 nicht mehr
geladen (Scheduler kommt komplett aus `bootstrap/app.php`). Er läuft also
aktuell **nicht** automatisch – bei Bedarf in `bootstrap/app.php` nachtragen.

## Scheduler

Kein `schedule:run`-Cron, sondern ein Dauerprozess **`php artisan
schedule:work`**, gestartet bzw. neugestartet über `restart_scheduler.sh`
(läuft als `root`, daher gehören manche Dateien unter `storage/` root). Alle
geplanten Aufgaben stehen in **`bootstrap/app.php`** unter `->withSchedule(...)`:

| Cron | Aufgabe |
| --- | --- |
| `*/5 * * * *` | `attack:check` |
| `40 4 * * *` | `downloads:sync` |
| `20 4,16 * * *` | `sitemap:generate` (+ `monthlycup`/`bbc`/`wec` um :25/:30/:35) |
| `0 0 01 */3 *` | `App\Console\Invokes\SeasonSwitch` (Quartals-Rollover: Tabellen kopieren, aufräumen) |

`app/Console/Kernel.php` und der darin importierte `AttackCeck` (sic) sind
toter Code aus der Zeit vor dem Laravel-12-Umbau.

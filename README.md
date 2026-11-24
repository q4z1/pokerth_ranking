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
| `backup:run` | DB-Dump (alle 4 h, ohne Table-Lock) + gebündelte pigz-Kompression + rsync der Web-Trees nach `/var/www/backup` | `--compress`, `--files`, `--skip-dump`, `--only=<db,…>`, `--dry-run` | alle 4 h (00/08/12/16/20 CEST), schwerer Lauf 04:00 |
| `sitemap:generate` | `sitemap.xml` für das Forum bauen | `--output=<pfad>`, `--dry-run` | 04:20 & 16:20 |
| `avatars:purge-blacklisted` | Dateien bereits gesperrter Avatare aus dem öffentlichen Verzeichnis in die Quarantäne schieben | `--dry-run` | manuell |
| `logs:rotate-nginx` | Zu große nginx-Logs komprimieren und leeren (copytruncate-Stil) | `--dir=`, `--max-size=<MB>`, `--keep=<n>`, `--dry-run` | manuell (siehe Hinweis) |
| `player:set-password <name> [pw]` | Passwort im Forum (argon2id) und in der Ranking-DB (AES) setzen; ohne `pw` wird verdeckt abgefragt | `--force` (ohne Rückfrage) | manuell |
| `player:delete <name>` | Account im Forum (phpBB) und in der Ranking-DB löschen – gleiche Logik wie „Delete“ auf der Players-Seite; Forenbeiträge bleiben als „Deleted“ | `--dry-run`, `--force` (ohne Rückfrage) | manuell |
| `ranking:detect-collusion` | Account-Ringe **einer** Person finden, die gemeinsam an gewerteten Tischen sitzen (Multi-Seat / Chip-Dumping); Spalten Rang/Score, sortiert nach bestem Rang | `--days=`, `--since=day\|week\|month\|year`, `--confidence=review\|medium\|high`, `--min-tables=`, `--ip-max-accounts=`, `--player=`, `--limit=`, `--json` | manuell |
| `ranking:track-ring [preset]` | Bekannten Ban-Evasion-Ring aus Seed-Accounts aufspannen und **neue** Accounts (≤ N Tage) farblich hervorheben | `--seed=*`, `--new-days=`, `--ip-max=`, `--subnet-max=`, `--avatar-max=`, `--show-weak`, `--json` | manuell |
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

**`backup:run`** – löst die früheren externen Skripte `~/.local/bin/backup.sh`
+ `~/.local/bin/automysqlbackup` ab, die bei jedem Lauf XHR-Timeouts im Forum
verursacht haben. Zwei Stufen:

- **alle 4 h** (`backup:run`, ohne Flags): nur `mysqldump` je DB, **unkomprimiert**
  und mit `--single-transaction --skip-lock-tables` – kein Table-Lock mehr, keine
  gzip-CPU. `mysqldump`/`rsync` laufen mit `nice`/`ionice`.
- **04:00 CEST** (`backup:run --compress --files`): packt alle offenen Dumps
  gebündelt mit `pigz` (mehrkernig, `nice 19` / `ionice` idle), spiegelt die
  Web-Trees per `rsync` und legt die weekly- (Fr) / monthly-Kopien (1.) an.

**Notventil:** sinkt der freie Platz auf `/var/www/backup` unter `BACKUP_MIN_FREE_GB`
(Default 6) oder liegen mehr als 8 unkomprimierte Dump-Sätze herum, komprimiert
auch ein regulärer 4h-Lauf sofort – damit die unkomprimierten Dumps `/var/www`
nicht volllaufen lassen, falls der 04:00-Lauf mal ausfällt.

Ergebnis-Layout und Aufbewahrung sind identisch zu automysqlbackup:
`/var/www/backup/db/{daily,weekly,monthly,latest,fullschema,status}`,
Namensschema `daily_<db>_<Y-m-d>_<HHhMMm>_<Weekday>.sql[.gz]`, Retention
6 / 35 / 150 Tage (daily / weekly / monthly). Web-Trees weiter als in-place
rsync-Spiegel unter `/var/www/backup/<quellpfad>/`.

Bewusste Abweichungen: alle `*_test`-DBs sind ausgeschlossen (die alte Config
sicherte `pokerth_ranking_test` durch einen Tippfehler mit); die kalten
MyISAM-Tabellen in `ph`/`monthlycup` (`ryae_*`, alte `player_*beta*stats`,
`award20xx`) sind mit `--single-transaction` nicht mehr snapshot-konsistent.
Fehlt `pigz` im Image, fällt der Command auf single-threaded `gzip` zurück und
schreibt einen `notice` ins Log – für die eigentliche CPU-Entlastung muss `pigz`
ins Container-Image (`apt-get install -y pigz`). Der frühere externe 4h-Cron
(`docker exec … backup.sh`) wird damit abgelöst und ist zu deaktivieren.

Konfiguration: `config/backup.php` (Pfade, Retention, DB-Ausschlüsse, `nice`/
`ionice`-Stufen); Overrides optional über `BACKUP_*` in `.env`.

**`player:delete <name>`** – Name exakt und case-sensitiv. Zeigt erst
Forum-User und Ranking-Player (IDs, E-Mail, Beitrags- und Session-Zahl) und
fragt nach. Löscht nach phpBB `user_delete()` im Modus „retain“: Forenbeiträge,
Topics und Anhänge gehen an den Anonymous-User („Deleted“), User-Zeilen in den
phpBB-Nebentabellen, Sessions, eigene Reports, private Nachrichten (wie phpBB:
zugestellte PMs bleiben für Empfänger lesbar) und die hochgeladene Avatar-Datei
werden entfernt, `num_users` sinkt; auf Ranking-Seite verschwinden `player` und
`player_ranking`. Alles in **einer Transaktion** – schlägt ein Schritt fehl, bleibt
der Account unverändert. Gibt es den Namen nur auf einer Seite, wird nur diese
gelöscht; Founder-Accounts werden verweigert. Logik in
`app/Services/PlayerDeletionService.php` (auch von der Players-Seite benutzt).
Anders als das Selbstlöschen im UCP landet der Name **nicht** in
`suspended_nicknames`. Für eine dauerhafte Namenssperre zusätzlich einen
Eintrag in phpBB „Disallow usernames“ anlegen (Beispiel: `Guest*`).

**`logs:rotate-nginx`** – rotiert nur Dateien über der Größenschwelle; ein
Leerlauf kostet nichts. ⚠️ In `app/Console/Kernel.php` ist der Befehl als
stündlich eingetragen, aber `Kernel.php` wird unter Laravel 12 nicht mehr
geladen (Scheduler kommt komplett aus `bootstrap/app.php`). Er läuft also
aktuell **nicht** automatisch – bei Bedarf in `bootstrap/app.php` nachtragen.

**`ranking:detect-collusion`** – sucht Account-Ringe, die derselben Person
gehören und gemeinsam an **gewerteten** Tischen (`game_has_player`) sitzen.
Verknüpft wird über den **IP-Pool** aus allen Sessions + `last_ip` (nicht über
„gleiche IP im selben Spiel" – der Game-Server sieht die Gegensitz-IP gar
nicht), kombiniert mit wiederholtem gemeinsamen Tisch; gleicher Passwort-Blob
bzw. ähnlicher Nick erhärten. Öffentliche VPN-/CGNAT-IPs werden über
`--ip-max-accounts` ausgefiltert (eine IP „verknüpft" nur, wenn sie wenige
Accounts nutzen). Ausgabe sind Ringe mit Score; `--player=` zeigt gezielt den
Ring eines Accounts. Logik in `app/Services/CollusionDetector.php`. Geteilte IP
bleibt ein Indiz, kein Beweis (Haushalt/CGNAT) – die Liste ist eine
Entscheidungsgrundlage, kein Automatik-Ban. Dieselbe Erkennung gibt es im
Admin-Backend unter **Reports → Collusion** (`GET /reports/collusion`,
`ReportsController::collusion`, Vue-Komponente `CollusionTable.vue`) mit Ban-Buttons.

**`ranking:track-ring`** – für wiederkehrende Ban-Evader: aus bekannten
Seed-Accounts (Presets in `config/rings.php`, reale Namen nur in der
gitignorierten `config/rings.local.php`, oder ad hoc per `--seed`) wird der
aktuelle Ring aufgespannt und **neue** Accounts (`--new-days`, Default 7) gelb
hervorgehoben – inkl. frischer Wegwerf-Accounts, die über IP, /24, gleichen
Passwort-Blob oder seltenen Avatar andocken. Selbst gelöschte/umbenannte Seeds
werden über `suspended_nicknames` (live + Season-Snapshots) und
`server_session.nick` aufgelöst, da die `player_id` stabil bleibt. Logik in
`app/Services/RingTracker.php`.

## Scheduler

Kein `schedule:run`-Cron, sondern ein Dauerprozess **`php artisan
schedule:work`**, gestartet bzw. neugestartet über `restart_scheduler.sh`
(läuft als `root`, daher gehören manche Dateien unter `storage/` root). Alle
geplanten Aufgaben stehen in **`bootstrap/app.php`** unter `->withSchedule(...)`:

Auf dem **Host** (nicht im Container) hält ein Cron den Dauerprozess frisch –
in unserem Setup:

```
59 23 * * * /usr/bin/docker exec -d pthdev-app /bin/bash /var/www/pokerth/pthranking/restart_scheduler.sh
```

`schedule:work` liest den Plan jede Minute neu, ein Deploy wirkt also ohne
Neustart; der nächtliche Neustart sorgt zusätzlich für frischen Code und einen
sauberen Prozess. Der `backup:run`-Lauf um 00:00 liegt eine Minute nach diesem
Neustart – unkritisch; rückt der Neustart-Cron je näher an `:00`, die reinen
Dump-Läufe um ein paar Minuten versetzen (`5 0,8,12,16,20 * * *`).

| Cron | Aufgabe |
| --- | --- |
| `*/5 * * * *` | `attack:check` |
| `40 4 * * *` | `downloads:sync` |
| `20 4,16 * * *` | `sitemap:generate` (+ `monthlycup`/`bbc`/`wec` um :25/:30/:35) |
| `0 0 01 */3 *` | `App\Console\Invokes\SeasonSwitch` (Quartals-Rollover: Tabellen kopieren, aufräumen) |
| `0 0,8,12,16,20 * * *` (Europe/Berlin) | `backup:run` – nur DB-Dump |
| `0 4 * * *` (Europe/Berlin) | `backup:run --compress --files` – Dump + Kompression + rsync + weekly/monthly |

`app/Console/Kernel.php` und der darin importierte `AttackCeck` (sic) sind
toter Code aus der Zeit vor dem Laravel-12-Umbau.

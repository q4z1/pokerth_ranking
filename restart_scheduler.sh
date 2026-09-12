#!/bin/sh
#
# Startet den Laravel-Scheduler neu. Aufgerufen wird das Script vom Host-Cron:
#   59 23 * * * /usr/bin/docker exec -d pthdev-app /bin/bash .../restart_scheduler.sh
# Das `docker exec` laeuft ohne -u, also als root.
#
# Der Scheduler darf aber nicht als root laufen. Alles, was er anlegt, gehoerte
# sonst root mit umask 022 - und devuser kaeme an die eigenen Projektdateien
# nicht mehr heran: die withoutOverlapping-Mutexe unter
# storage/framework/cache, die Backup-Baeume unter /var/www/backup, die
# Sitemaps und die nginx-Logs. Genau daran ist `php artisan schedule:list`
# mit "Permission denied" gescheitert.
#
# Deshalb wird hier auf devuser abgesenkt, sobald das Script als root laeuft.
# Der pkill muss davor passieren: ein als devuser laufender Prozess koennte
# einen alten root-Scheduler nicht beenden.
#
set -eu

APP_DIR=/var/www/pokerth/pthranking
RUN_USER=devuser
RUN_HOME=/home/devuser
PHP=/usr/local/bin/php

cd "$APP_DIR"

# Solange wir noch root sind: Eigentuemer geradeziehen. Zwei Gruende.
# 1) Der nginx-Container legt seine Logs als root an - devuser koennte sie
#    sonst nicht rotieren, copytruncate braucht Schreibrecht auf die Datei
#    selbst, nicht nur aufs Verzeichnis.
# 2) Altlasten aus der Zeit, als dieser Scheduler noch als root lief
#    (Backup-Baeume, Sitemap). Der find ist billig und selbstheilend, falls
#    doch wieder etwas als root schreibt.
find /var/log/nginx /var/www/backup /var/www/pokerth/sitemap.xml -user root \
    -exec chown devuser:devuser {} + 2>/dev/null || true

# Kein Treffer ist kein Fehler (pkill gibt dann 1 zurueck, set -e wuerde beissen).
/usr/bin/pkill -f 'artisan schedule:work' || true

if [ "$(id -u)" = "0" ]; then
    # HOME mitgeben: setpriv wechselt nur die IDs, die Umgebung bleibt sonst
    # die von root - HOME=/root ist fuer devuser nicht schreibbar.
    exec env HOME="$RUN_HOME" \
        setpriv --reuid="$RUN_USER" --regid="$RUN_USER" --init-groups \
        "$PHP" artisan schedule:work
fi

exec "$PHP" artisan schedule:work

<?php

/*
|--------------------------------------------------------------------------
| Backup (php artisan backup:run)
|--------------------------------------------------------------------------
|
| Löst das frühere ~/.local/bin/backup.sh + automysqlbackup ab. Schreibt in
| dasselbe Verzeichnis mit demselben Namensschema/derselben Retention, damit
| die bestehende Historie konsistent bleibt. Details siehe README und der
| Doc-Block in app/Console/Commands/Backup.php.
|
*/

return [

    // Wurzel des Backup-Baums. Darunter: db/{daily,weekly,monthly,latest,
    // fullschema,status} und var/www/<app> (rsync-Spiegel der Web-Trees).
    'dir' => env('BACKUP_DIR', '/var/www/backup'),

    'db' => [
        // Default: dieselbe DB wie die App. mysqldump läuft gegen diesen Host.
        'host' => env('BACKUP_DB_HOST', env('DB_HOST', 'mariadb')),
        'port' => env('BACKUP_DB_PORT', env('DB_PORT', '3306')),
        'user' => env('BACKUP_DB_USER', env('DB_USERNAME', 'root')),
        'pass' => env('BACKUP_DB_PASS', env('DB_PASSWORD')),

        // Diese Schemata nie sichern. Alles auf "_test" fliegt zusätzlich
        // automatisch raus (siehe Command).
        'exclude' => ['information_schema', 'performance_schema'],
    ],

    // Aufbewahrung in TAGEN – 1:1 wie automysqlbackup (CONFIG_rotation_*).
    'keep' => [
        'daily'   => (int) env('BACKUP_KEEP_DAILY', 6),
        'weekly'  => (int) env('BACKUP_KEEP_WEEKLY', 35),
        'monthly' => (int) env('BACKUP_KEEP_MONTHLY', 150),
    ],

    // Wann weekly/monthly-Kopien entstehen (im 04:00-Lauf).
    'weekly_on'  => 5, // ISO-Wochentag, 5 = Freitag (wie CONFIG_do_weekly)
    'monthly_on' => 1, // Tag des Monats

    // Notventil: sinkt der freie Platz unter diesen Wert ODER liegen mehr als
    // max_pending_sets unkomprimierte Dump-Sätze herum, komprimiert auch ein
    // regulärer 4h-Lauf sofort, statt bis 04:00 zu warten.
    'min_free_gb'      => (int) env('BACKUP_MIN_FREE_GB', 6),
    'max_pending_sets' => (int) env('BACKUP_MAX_PENDING_SETS', 8),

    'compress' => [
        'bin'     => 'pigz',  // fehlt pigz -> Fallback gzip (+ Log-notice)
        'level'   => 6,
        'threads' => (int) env('BACKUP_PIGZ_THREADS', 2), // pigz -p
        'nice'    => 19,
        'ionice'  => ['class' => 3, 'level' => null], // idle
    ],

    // Priorität für mysqldump und rsync (die 4h-Läufe laufen im Live-Betrieb).
    'io' => [
        'nice'   => 10,
        'ionice' => ['class' => 2, 'level' => 7], // best-effort, niedrigste Stufe
    ],

    'files' => [
        // Web-Trees – Ziel ist backup.dir . '/var/www/<basename>' bzw. für
        // absolute Fremdpfade backup.dir . '<pfad>' (wie backup.sh).
        'sources' => [
            '/var/www/bbc',
            '/var/www/monthlycup',
            '/var/www/pokerth',
            '/var/www/wec',
            '/home/devuser/.local/bin',
        ],
        'excludes' => ['*.log', '**/.git/*', '**/node_modules/*', '**/vendor/*'],
    ],

];

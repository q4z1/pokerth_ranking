<?php

use App\Console\Invokes\SeasonSwitch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            '/account/reset',
            '/account/change',
            '/account/create',
            '/account/validate',
            '/ranking/leaderboard/*',
            '/gametable/show',
            '/game/pdb',
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->call(new SeasonSwitch)->cron('0 0 01 */3 *');
        $schedule->command('attack:check')->cron('*/5 * * * *');

        // Snapshot des neuesten PokerTH-Releases von GitHub frisch halten. Nach
        // einem Release kann man `php artisan downloads:sync` auch direkt
        // aufrufen, statt bis zum nächsten Lauf zu warten.
        $schedule->command('downloads:sync')->cron('40 4 * * *')->withoutOverlapping();
        // 2x täglich, versetzt zur vollen Stunde – öfter bringt nichts, die
        // Suchmaschinen holen die Sitemap ohnehin nur etwa einmal am Tag ab.
        $schedule->command('sitemap:generate')->cron('20 4,16 * * *');

        // Die Schwesterseiten bringen ihren eigenen Generator mit, jede kennt
        // nur ihre eigenen Routen und Modelle. Angestossen werden sie hier,
        // weil der Cron ausschliesslich diesen Scheduler aufruft. Zeitlich
        // versetzt, damit nicht vier Läufe gleichzeitig auf der DB liegen.
        foreach ([
            'monthlycup' => '25 4,16 * * *',
            'bbc'        => '30 4,16 * * *',
            'wec'        => '35 4,16 * * *',
        ] as $app => $cron) {
            $schedule->exec("cd /var/www/{$app} && php artisan sitemap:generate")
                ->cron($cron)
                ->withoutOverlapping()
                ->onFailure(fn () => logger()->error("sitemap:generate failed for {$app}"));
        }
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

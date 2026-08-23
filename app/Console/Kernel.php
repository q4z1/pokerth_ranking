<?php

namespace App\Console;

use App\Console\Invokes\SeasonSwitch;
use App\Console\Commands\AttackCeck;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->call(new SeasonSwitch)->cron('0 0 01 */3 *'); // M H d m Y
        $schedule->command('attack:check')->cron('*/5 * * * *');

        // Stuendlich statt taeglich: die Platte stand am 23.08.2026 bei 95%,
        // und pokerth_access.log waechst unter Angriff um mehrere hundert MB
        // am Tag. Rotiert wird ohnehin nur, was ueber der Groessenschwelle
        // liegt - laeuft der Aufruf leer, kostet er nichts.
        $schedule->command('logs:rotate-nginx')->hourly()->withoutOverlapping();

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

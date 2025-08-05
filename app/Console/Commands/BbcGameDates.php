<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BbcGameDates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bbc:gamedates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically create game-dates for BBC.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $database = "bbc_test";
        $database = "bbc";
        $s2_tickets = count(DB::select("select * from `$database`.players as p where p.s2_tickets > 0"));
        $s3_tickets = count(DB::select("select * from `$database`.players as p where p.s3_tickets > 0"));
        $s4_tickets = count(DB::select("select * from `$database`.players as p where p.s4_tickets > 0"));
        $matrix = [
            'week_even' => [
                "monday" => [
                    '1:00' => 1,
                    '19:30' => 1,
                    '21:30' => 2,
                    '23:15' => 1,
                ],
                "tuesday" => [
                    '1:00' => 1,
                    '19:30' => 2,
                    '21:30' => 1,
                    '23:15' => 1,
                ],
                "wednesday" => [
                    '1:00' => 1,
                    '19:30' => 1,
                    '21:30' => 1,
                    '23:15' => 2,
                ],
                "thursday" => [
                    '1:00' => 2,
                    '19:30' => 1,
                    '21:30' => 2,
                    '23:15' => 1,
                ],
                "friday" => [
                    '1:00' => 1,
                    '19:30' => 2,
                    '21:30' => 1,
                    '23:15' => 1,
                ],
                "saturday" => [
                    '1:00' => 1,
                    '19:30' => 1,
                    '21:30' => 2,
                    '23:15' => 1,
                ],
                "sunday" => [
                    '1:00' => 2,
                    '19:30' => 2,
                    '21:30' => 1,
                    '23:15' => 1,
                ],
            ],
            'week_odd' => [
                "monday" => [
                    '1:00' => 1,
                    '19:30' => 1,
                    '21:30' => 1,
                    '23:15' => 2,
                ],
                "tuesday" => [
                    '1:00' => 1,
                    '19:30' => 1,
                    '21:30' => 2,
                    '23:15' => 1,
                ],
                "wednesday" => [
                    '1:00' => 1,
                    '19:30' => 2,
                    '21:30' => 1,
                    '23:15' => 1,
                ],
                "thursday" => [
                    '1:00' => 1,
                    '19:30' => 1,
                    '21:30' => 2,
                    '23:15' => 1,
                ],
                "friday" => [
                    '1:00' => 2,
                    '19:30' => 1,
                    '21:30' => 2,
                    '23:15' => 1,
                ],
                "saturday" => [
                    '1:00' => 1,
                    '19:30' => 2,
                    '21:30' => 1,
                    '23:15' => 1,
                ],
                "sunday" => [
                    '1:00' => 2,
                    '19:30' => 1,
                    '21:30' => 1,
                    '23:15' => 2,
                ],
            ]
        ];
        dd($matrix);
        dd($s2_tickets, $s3_tickets, $s4_tickets);
        return Command::SUCCESS;
    }
}

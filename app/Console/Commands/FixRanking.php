<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixRanking extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ranking:fix';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        echo "\nExecuting FixRanking.\n";

        // $games = DB::select("select * from game as g where g.start_time >= '2024-02-29 17:19:00' and g.start_time < '2024-03-02 10:49:00'");

        // foreach($games as $game){
        //     echo "\nCALL updatePointsForGame(" . $game->idgame . ");\n";
        //     DB::statement("CALL updatePointsForGame(" . $game->idgame . ");");
        //     echo "\ndone\n";
        // }


        return Command::SUCCESS;
    }
}

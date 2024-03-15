<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Console\Invokes\SeasonSwitch as SeasonSwitch2;

class SeasonSwitch extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'season:switch';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        //$sw = new SeasonSwitch2;
        //$sw->run();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        return 0;
    }
}

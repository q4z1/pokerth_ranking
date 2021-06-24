<?php

namespace App\Console\Invokes;
use Illuminate\Support\Facades\DB;

class SeasonSwitch {

    private $season;
    private $tables;

    public function __invoke()
    {
        $this->season = $this->get_season(intval(date("Y")), intval(date("m")));
        $this->tables = ['game', 'game_has_player', 'player_ranking'];
        $this->create_tables();
        $this->copy_tables();
        $this->cleanup();
        echo date("Y-m-d H:i:s") . " : Ending Season {$this->season}.\n";
        return;
    }

    private function create_tables(){
        foreach($this->tables as $table){
            DB::statement("CREATE TABLE `pokerth_seasons`.`{$this->season}_{$table}` LIKE `pokerth_ranking`.`{$table}`;");
        }
    }

    private function copy_tables(){
        foreach($this->tables as $table){
            DB::statement("INSERT INTO `pokerth_seasons`.`{$this->season}_{$table}` SELECT * FROM `pokerth_ranking`.`{$table}`;");
        }
    }

    private function cleanup(){
        DB::statement("TRUNCATE `pokerth_ranking`.`suspended_nicknames`;");
        DB::statement("UPDATE `pokerth_ranking`.`player_ranking` SET `final_score` = 0, `points_sum` = 0, `average_score` = 0, `season_games` = 0;");
        DB::statement("DELETE FROM `pokerth_ranking`.`game` WHERE `end_time` IS NOT NULL");
        DB::statement("DELETE FROM `pokerth_ranking`.`game_has_player` WHERE `end_time` IS NOT NULL");
    }

    private function get_season($year, $month){
        $year = ($month === 1) ? $year - 1 : $year;
        $season =  ($month === 1) ? 4 : round($month / 3);
        if($year === 2021) $season--; // @INFO: Exception for first season of 2021 - can be removed Jan 01, 2022 earliest
        return $year . "_" . $season;
    }

}
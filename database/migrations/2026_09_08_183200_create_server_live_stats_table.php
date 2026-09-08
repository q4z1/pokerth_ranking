<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ein-Zeilen-Heartbeat, den der PokerTH-Gameserver einmal pro Minute
 * aktualisiert (siehe docs/live-stats-heartbeat.md). Die Tabelle wurde auf der
 * Live-DB bereits von Hand angelegt; diese Migration ist nur fuer frische
 * Umgebungen / die Test-DB da und fasst eine vorhandene Tabelle nicht an.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('server_live_stats')) {
            return;
        }

        Schema::create('server_live_stats', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('run_id')->nullable()
                ->comment('server_run.run_id of the writing process');
            $table->unsignedSmallInteger('players_online')->default(0)
                ->comment('established sessions: lobby + in games');
            $table->unsignedSmallInteger('tables_running')->default(0)
                ->comment('open games in the lobby game list');
            $table->unsignedSmallInteger('players_waiting')->default(0)
                ->comment('established sessions in the lobby, not seated at a game');
            $table->dateTime('updated_at')
                ->comment('server-local wall clock, same convention as server_session.connected_at');
        });

        DB::table('server_live_stats')->insert([
            'id' => 1,
            'updated_at' => '1970-01-01 00:00:00',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('server_live_stats');
    }
};

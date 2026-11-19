<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archivierte Reports (Soft-Delete). Bewusst eine eigene Tabelle statt einer
 * Spalte in reported_avatar / reported_gamename: die gehören dem Game-Server,
 * dessen INSERTs wir hier nicht prüfen können.
 *
 * Archivierte Reports verschwinden aus den Report-Listen und dem Unseen-Badge,
 * zählen aber weiter für Offenders / Wiederholungstäter (außer ihr Status ist
 * "ignored" bzw. "reporter spam").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_archive', function (Blueprint $table) {
            $table->string('type', 16);                   // avatar | gamename
            $table->unsignedInteger('report_id');
            $table->string('archived_by', 64)->nullable();
            $table->dateTime('archived_at');
            $table->primary(['type', 'report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_archive');
    }
};

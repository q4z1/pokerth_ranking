<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit-Trail des Admin-Backends: wer hat wann was mit wem gemacht
 * (Login, Ban, Mute, Kickban, Report-Aktionen, Adverts, ...).
 * Geschrieben ausschließlich über App\Services\AdminAudit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_action', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->dateTime('created_at')->index();      // App-Zeit (Europe/Berlin)
            $table->string('admin_name', 64)->nullable()->index();
            $table->string('action', 32)->index();
            $table->unsignedInteger('target_player_id')->nullable()->index();
            $table->string('target_name', 64)->nullable();
            $table->boolean('success')->default(true);
            $table->text('details')->nullable();          // JSON
            $table->string('ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_action');
    }
};

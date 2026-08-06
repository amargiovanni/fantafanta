<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esegue la migrazione.
     *
     * Tabella singleton (una sola riga, id=1) con la configurazione della
     * lega: regolamento Classic, crediti, numero squadre, modificatori.
     * Vedi App\Models\LeagueConfig::current().
     */
    public function up(): void
    {
        Schema::create('league_config', function (Blueprint $table) {
            $table->id();
            // Slot rosa per ruolo, es. {"P":3,"D":8,"C":8,"A":6}.
            $table->json('slots');
            $table->unsignedInteger('total_credits')->default(500);
            $table->unsignedInteger('teams_count')->default(8);
            $table->boolean('modifier_defense')->default(true);
            $table->boolean('modifier_fairplay')->default(true);
            $table->string('auction_type')->default('random');
            $table->timestamps();
        });
    }

    /**
     * Annulla la migrazione.
     */
    public function down(): void
    {
        Schema::dropIfExists('league_config');
    }
};

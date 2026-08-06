<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esegue la migrazione.
     *
     * Due colonne che la sala d'asta (Fase 3) aggiunge a tabelle già in uso.
     *
     * `plan_slots.original_player_id` — quando il titolare designato viene
     * battuto da un avversario, la promozione dell'alternativa sovrascrive
     * `player_id`, e il nome perso sparirebbe. La sala deve invece mostrarlo
     * barrato con sotto il ripiego promosso (briefing §8.2): senza questa
     * colonna quella riga non è disegnabile.
     *
     * `acquisitions.plan_effects` — il giornale di ciò che la promozione
     * deterministica ha cambiato nel piano al momento dell'acquisto: per ogni
     * slot toccato, i valori di prima. È quello che rende l'undo un `revert`
     * esatto e non una ricostruzione a occhio — comprese le alternative
     * potate dagli altri slot, che nessun campo singolo saprebbe rimettere a
     * posto.
     */
    public function up(): void
    {
        Schema::table('plan_slots', function (Blueprint $table) {
            $table->foreignId('original_player_id')->nullable()->after('player_id')->constrained('players')->nullOnDelete();
        });

        Schema::table('acquisitions', function (Blueprint $table) {
            // [{slot_id, before: {player_id, original_player_id, target_price,
            //  max_price, alternatives, slot_status}}, ...]
            $table->json('plan_effects')->nullable()->after('valuation_at_purchase');
        });
    }

    /**
     * Annulla la migrazione.
     */
    public function down(): void
    {
        Schema::table('plan_slots', function (Blueprint $table) {
            $table->dropForeign(['original_player_id']);
            $table->dropColumn('original_player_id');
        });

        Schema::table('acquisitions', function (Blueprint $table) {
            $table->dropColumn('plan_effects');
        });
    }
};

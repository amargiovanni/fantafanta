<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esegue la migrazione.
     *
     * Le 25 righe che compongono un piano (briefing §4): una per slot rosa,
     * con il titolare designato e le sue alternative in ordine di preferenza.
     *
     * Le alternative stanno in JSON e non in una tabella figlia perché non
     * hanno vita propria: nascono e muoiono con lo slot, si leggono sempre
     * tutte insieme e non sono mai interrogate per giocatore. La promozione
     * deterministica (App\Services\PlanSlotPromoter) le consuma in ordine.
     *
     * `player_id` è nullable per il solo caso limite in cui uno slot perde il
     * titolare e non ha più nessuna alternativa disponibile: lo slot resta
     * visibile e vuoto invece di sparire dal piano.
     */
    public function up(): void
    {
        Schema::create('plan_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();

            // P / D / C / A: deve coincidere con il ruolo del giocatore.
            $table->string('role', 1);

            // 1-based dentro il ruolo: D#1..D#8.
            $table->unsignedInteger('slot_index');

            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('target_price')->default(1);
            $table->unsignedInteger('max_price')->default(1);

            // [{player_id, target_price}, ...] in ordine di preferenza, min 2 per slot pending.
            $table->json('alternatives')->nullable();

            // pending / acquired / lost.
            $table->string('slot_status')->default('pending');

            $table->timestamps();

            $table->unique(['plan_id', 'role', 'slot_index']);
            $table->index(['plan_id', 'slot_status']);
            $table->index('player_id');
        });
    }

    /**
     * Annulla la migrazione.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_slots');
    }
};

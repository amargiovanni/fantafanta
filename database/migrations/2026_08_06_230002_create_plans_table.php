<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esegue la migrazione.
     *
     * Il piano d'acquisto, versionato e append-only (briefing §4, §7.3): ogni
     * run di generate/replan crea la versione successiva, nessuna riga viene
     * mai riscritta. La UI mostra sempre l'ultima `ready`, così un replan in
     * corso non lascia mai la sala d'asta senza piano.
     *
     * `budget_summary` è un JSON per reparto {P: {allocated, spent}, ...}:
     * calcolato dal server dai suoi slot, non dichiarato dall'AI.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();

            // Progressivo per asta, assegnato dal server: max(version) + 1.
            $table->unsignedInteger('version');

            // initial / acquisition / manual.
            $table->string('trigger')->default('initial');

            // generating / ready / failed.
            $table->string('status')->default('generating');

            // Massimo 3 righe di razionale: il piano è la risposta, non il testo.
            $table->text('strategy_notes')->nullable();

            $table->json('budget_summary')->nullable();
            $table->timestamps();

            $table->unique(['auction_id', 'version']);
            $table->index(['auction_id', 'status', 'version']);
        });
    }

    /**
     * Annulla la migrazione.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};

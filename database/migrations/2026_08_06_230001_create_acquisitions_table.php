<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esegue la migrazione.
     *
     * Ogni aggiudicazione dell'asta (briefing §4). È il fatto da cui partono
     * il replanning, l'inflazione per ruolo e i crediti residui di tutti.
     *
     * Soft delete perché l'undo dell'asta (Fase 3) deve ripristinare crediti,
     * slot e stato del giocatore senza perdere la traccia di cosa è successo:
     * una battuta d'asta registrata per sbaglio si annulla, non si riscrive.
     *
     * `valuation_at_purchase` è uno scatto della valutazione al momento
     * dell'acquisto: l'inflazione per ruolo (briefing §5.4) confronta i prezzi
     * pagati con il valore che il giocatore aveva ALLORA, e quel valore cambia
     * a ogni segnale nuovo. Senza lo scatto l'inflazione si riscriverebbe da
     * sola a posteriori.
     */
    public function up(): void
    {
        Schema::create('acquisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // Crediti effettivamente pagati. 0 non è ammesso in asta: il minimo è 1.
            $table->unsignedInteger('price');

            $table->decimal('valuation_at_purchase', 8, 2)->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['auction_id', 'created_at']);
            $table->index(['team_id', 'deleted_at']);
            $table->index(['player_id', 'deleted_at']);
        });
    }

    /**
     * Annulla la migrazione.
     */
    public function down(): void
    {
        Schema::dropIfExists('acquisitions');
    }
};

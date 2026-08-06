<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esegue la migrazione.
     *
     * Alias di un giocatore: generati automaticamente all'import (cognome
     * solo, cognome + iniziale, nome invertito), dall'AI in Fase 1, o a mano
     * da backoffice. Usati dalla ricerca fuzzy per il match esatto (score 1.0).
     */
    public function up(): void
    {
        Schema::create('player_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias');
            $table->timestamps();

            // Univoco per giocatore, non globalmente: due giocatori diversi
            // possono generare lo stesso alias grezzo (es. omonimie di cognome).
            $table->unique(['player_id', 'normalized_alias']);
        });
    }

    /**
     * Annulla la migrazione.
     */
    public function down(): void
    {
        Schema::dropIfExists('player_aliases');
    }
};

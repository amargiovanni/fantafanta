<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esegue la migrazione.
     *
     * La sessione d'asta (briefing §4): tutto ciò che accade la sera dell'asta
     * — aggiudicazioni e versioni del piano — appende a una di queste righe.
     *
     * Esiste come tabella e non come singleton perché l'asta si ripete ogni
     * anno e perché il simulatore della Fase 5 deve poter aprire una sessione
     * di prova senza cancellare quella vera.
     */
    public function up(): void
    {
        Schema::create('auctions', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // setup (preparazione) / live (in corso) / closed (archiviata).
            $table->string('status')->default('setup');

            // Valorizzato al passaggio a `live`, non alla creazione.
            $table->timestamp('started_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Annulla la migrazione.
     */
    public function down(): void
    {
        Schema::dropIfExists('auctions');
    }
};

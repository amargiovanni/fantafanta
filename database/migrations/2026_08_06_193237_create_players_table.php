<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esegue la migrazione.
     *
     * Tabella anagrafica canonica dei giocatori, alimentata dall'import del
     * listone fantacalcio.it (vedi App\Services\ListoneImporter).
     */
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Nome normalizzato (minuscolo, senza accenti/punteggiatura): è la
            // chiave usata dal re-import per creare/aggiornare senza duplicati.
            $table->string('normalized_name');
            $table->string('role', 1);
            $table->string('real_team')->nullable();
            $table->unsignedInteger('quotazione')->default(0);
            $table->unsignedInteger('fvm')->default(0);
            // Tutte le colonne statistiche mappate dal CSV (fantamedia, media
            // voto, presenze, gol, assist, ammonizioni, espulsioni, rigori...).
            $table->json('season_stats')->nullable();
            $table->string('status')->default('available');
            $table->boolean('is_rigorista')->default(false);
            $table->float('expected_starter')->default(0.5);
            $table->timestamps();

            $table->unique('normalized_name');
        });
    }

    /**
     * Annulla la migrazione.
     */
    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};

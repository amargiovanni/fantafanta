<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esegue la migrazione.
     *
     * Squadre della lega, registrate prima dell'asta. `is_mine` individua la
     * squadra di Andrea; il vincolo "una sola" è applicativo (vedi
     * App\Models\Team), non a livello di database.
     * `credits_spent` NON è una colonna: è un accessor derivato dalle
     * acquisizioni, che arrivano in Fase 2 (per ora vale sempre 0).
     */
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_mine')->default(false);
            $table->unsignedInteger('credits_total')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Annulla la migrazione.
     */
    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};

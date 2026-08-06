<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esegue la migrazione.
     *
     * L'output del motore deterministico (briefing §5). Una riga per
     * giocatore, riscritta per intero a ogni ricalcolo: è la tabella che deve
     * rispondere in meno di 50 ms mentre un nome viene battuto all'asta, per
     * cui non contiene niente che vada calcolato al momento della lettura.
     *
     * `base_value` e `adjusted_value` sono decimali: il valore serve anche a
     * ordinare e a formare i quintili, e arrotondare a credito intero
     * appiattirebbe la coda del listone dove decine di giocatori valgono
     * "circa 1". `max_bid` invece è intero perché è un'offerta reale.
     */
    public function up(): void
    {
        Schema::create('valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->unique()->constrained()->cascadeOnDelete();

            // Valore da listone e budget di lega, prima di segnali e modificatori.
            $table->decimal('base_value', 8, 2);

            // Dopo segnali, modificatori di lega e titolarità attesa.
            $table->decimal('adjusted_value', 8, 2);

            // Offerta massima consigliata: inflazione e scarsità inclusi, budget residuo come tetto.
            $table->unsignedInteger('max_bid')->default(0);

            // 1 (top) .. 5, quintili di adjusted_value dentro il ruolo.
            $table->unsignedTinyInteger('tier')->default(5);

            // Domanda/offerta del profilo nel ruolo, clamp [0.5, 3].
            $table->decimal('scarcity_index', 4, 2)->default(1);

            $table->timestamp('computed_at');
            $table->timestamps();

            $table->index(['tier', 'adjusted_value']);
        });
    }

    /**
     * Annulla la migrazione.
     */
    public function down(): void
    {
        Schema::dropIfExists('valuations');
    }
};

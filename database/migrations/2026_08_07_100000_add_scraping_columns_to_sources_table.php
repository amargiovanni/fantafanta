<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esegue la migrazione.
     *
     * Fase 4: una source scaricata dallo scraping deve sapere da quale
     * testata viene (per il circuit breaker e i contatori in backoffice) e
     * quando l'articolo è stato pubblicato (per la finestra di dedup e per
     * filtrare "solo il nuovo" nei run schedulati). `queue_note` porta un
     * messaggio leggibile quando una source resta in coda perché il tetto di
     * estrazioni del run è stato raggiunto — distinto da `error`, che è
     * sempre un fallimento.
     */
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->foreignId('scrape_target_id')->nullable()->after('id')
                ->constrained('scrape_targets')->nullOnDelete();
            $table->timestamp('published_at')->nullable()->after('url');
            $table->string('queue_note')->nullable()->after('error');
        });
    }

    /**
     * Annulla la migrazione.
     */
    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scrape_target_id');
            $table->dropColumn(['published_at', 'queue_note']);
        });
    }
};

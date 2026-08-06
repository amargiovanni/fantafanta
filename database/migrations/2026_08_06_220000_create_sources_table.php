<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esegue la migrazione.
     *
     * Ogni cosa che entra nella base di conoscenza è una source: un link
     * incollato, un PDF caricato, una nota scritta a mano o un articolo
     * scaricato dallo scraping. `content_hash` è la chiave di dedup: la
     * stessa notizia ripresa da due testate non viene processata due volte.
     */
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('title');
            $table->string('url')->nullable();
            $table->longText('raw_content')->nullable();

            // Hash del testo estratto: unico, è il confine anti-duplicati.
            // Nullable perché il testo esiste solo dopo l'estrazione.
            $table->string('content_hash', 64)->nullable()->unique();

            $table->string('origin')->default('manual');
            $table->timestamp('processed_at')->nullable();
            $table->string('status')->default('queued');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Annulla la migrazione.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};

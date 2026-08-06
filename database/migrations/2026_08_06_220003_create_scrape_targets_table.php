<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esegue la migrazione.
     *
     * Testate fantacalcistiche da monitorare (briefing §4 e §7.4). Il seeder
     * popola le principali; Andrea può aggiungerne da backoffice. Lo scraping
     * vero e proprio è Fase 4: qui si prepara solo l'anagrafica.
     */
    public function up(): void
    {
        Schema::create('scrape_targets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url');
            $table->string('rss_url')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_scraped_at')->nullable();
            $table->timestamps();

            $table->unique('url');
        });
    }

    /**
     * Annulla la migrazione.
     */
    public function down(): void
    {
        Schema::dropIfExists('scrape_targets');
    }
};

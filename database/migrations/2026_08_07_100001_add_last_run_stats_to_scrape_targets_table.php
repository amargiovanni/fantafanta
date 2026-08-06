<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esegue la migrazione.
     *
     * "Articoli trovati ultimo run" in backoffice (spec Fase 4, §Backoffice).
     * Lo stato del circuito NON vive qui: è effimero (si autochiude dopo il
     * cooldown) e vive in cache, non nel database.
     */
    public function up(): void
    {
        Schema::table('scrape_targets', function (Blueprint $table) {
            $table->unsignedInteger('last_run_articles_found')->nullable()->after('last_scraped_at');
        });
    }

    /**
     * Annulla la migrazione.
     */
    public function down(): void
    {
        Schema::table('scrape_targets', function (Blueprint $table) {
            $table->dropColumn('last_run_articles_found');
        });
    }
};

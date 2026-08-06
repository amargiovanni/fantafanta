<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esegue la migrazione.
     *
     * Il cuore della conoscenza (briefing §4). Scritti dall'AI tramite i tool
     * MCP, corretti a mano da backoffice.
     *
     * `player_id` è nullable per un motivo preciso: quando l'AI non riesce a
     * risolvere un nome NON deve inventare un collegamento. Il segnale entra
     * con `raw_name` valorizzato e `needs_review` a true, e finisce nella coda
     * di revisione manuale. Il vincolo "o player_id o (raw_name + needs_review)"
     * è applicato server-side dal tool `save_signals`.
     *
     * `superseded_by` punta al segnale che ha reso obsoleto questo: un
     * "rientro" supera un "infortunio". I segnali superati restano per storico
     * ma non pesano più sulla valutazione.
     */
    public function up(): void
    {
        Schema::create('signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->json('payload')->nullable();

            // 0–1: quanto la fonte è affidabile su questa informazione.
            $table->decimal('confidence', 3, 2)->default(0.50);

            // -2..+2: effetto sull'appetibilità del giocatore in asta.
            $table->integer('impact')->default(0);

            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->date('event_date')->nullable();
            $table->foreignId('superseded_by')->nullable()->constrained('signals')->nullOnDelete();
            $table->boolean('needs_review')->default(false);

            // Nome così come appare nella fonte, quando non è stato risolto.
            $table->string('raw_name')->nullable();

            $table->timestamps();

            $table->index(['player_id', 'type']);
            $table->index(['needs_review', 'created_at']);
            $table->index('superseded_by');
        });
    }

    /**
     * Annulla la migrazione.
     */
    public function down(): void
    {
        Schema::dropIfExists('signals');
    }
};

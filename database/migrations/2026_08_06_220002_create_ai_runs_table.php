<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Esegue la migrazione.
     *
     * Audit di ogni esecuzione di `claude -p` (briefing §3): quale prompt,
     * con quale hash, quanto ha impiegato, cosa ha risposto, com'è finita.
     * Nessuna esecuzione AI è invisibile: se un segnale è sbagliato si risale
     * sempre al run che l'ha prodotto.
     */
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->id();
            $table->string('task');
            $table->string('prompt_file');

            // sha256 del prompt composto effettivamente inviato: due run con
            // lo stesso hash hanno ricevuto esattamente lo stesso input.
            $table->string('prompt_hash', 64);

            $table->string('status')->default('pending');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->longText('output_raw')->nullable();
            $table->text('error')->nullable();

            // Contesto del run (es. source_id), per collegare l'audit al dominio.
            $table->json('context')->nullable();

            $table->timestamps();

            $table->index(['task', 'created_at']);
            $table->index('status');
        });
    }

    /**
     * Annulla la migrazione.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};

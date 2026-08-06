<?php

namespace App\Jobs;

use App\Enums\SourceStatus;
use App\Models\Signal;
use App\Models\Source;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Chiude la pipeline di una source dopo l'estrazione dei segnali.
 *
 * Gira in catena dopo RunClaudeTask: se quello fallisce la catena si ferma e
 * questo job non viene eseguito, quindi lo stato "processed" significa davvero
 * "l'AI ha finito e non ha sbagliato".
 */
class FinalizeSourceProcessing implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $sourceId)
    {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $source = Source::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        $daRivedere = Signal::query()
            ->where('source_id', $source->id)
            ->where('needs_review', true)
            ->exists();

        $source->update([
            'status' => $daRivedere ? SourceStatus::NeedsReview : SourceStatus::Processed,
            'processed_at' => now(),
        ]);
    }
}

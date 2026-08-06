<?php

namespace App\Jobs;

use App\Models\ScrapeTarget;
use App\Scraping\ScrapeRunner;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Un job per testata dentro il batch del full scrape (spec Fase 4, §Full
 * scrape on demand). L'id del batch Horizon fa da `runId` per il tetto di
 * estrazioni: i job del batch possono girare in parallelo, e il tetto deve
 * essere condiviso fra tutti, non uno per testata.
 *
 * Il batch è dispatched con `allowFailures()`: un'eccezione qui non deve
 * cancellare le altre testate — ma `ScrapeRunner` cattura già i propri
 * errori internamente, quindi questo try/catch è solo una rete di sicurezza
 * per l'imprevisto (es. la testata è stata eliminata fra la creazione del
 * batch e l'esecuzione del job).
 */
class ScrapeTargetFull implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $scrapeTargetId,
        public readonly ?int $windowDays = null,
        public readonly ?int $htmlPages = null,
    ) {
        $this->onQueue((string) config('fanta.scraping.queue'));
    }

    public function handle(ScrapeRunner $runner): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $target = ScrapeTarget::query()->find($this->scrapeTargetId);

        if ($target === null || $this->batch() === null) {
            return;
        }

        try {
            $runner->runFull($target, $this->batch()->id, $this->windowDays, $this->htmlPages);
        } catch (Throwable $exception) {
            Log::warning("Full scrape fallito per la testata #{$target->id} ({$target->name}): {$exception->getMessage()}");
        }
    }
}

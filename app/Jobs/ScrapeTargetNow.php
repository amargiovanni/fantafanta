<?php

namespace App\Jobs;

use App\Models\ScrapeTarget;
use App\Scraping\ScrapeRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Il bottone "Scrape ora" di una singola testata in backoffice: lo stesso
 * comportamento del giro schedulato, fuori dal suo orario e per una sola
 * testata (spec Fase 4, §Backoffice).
 */
class ScrapeTargetNow implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $scrapeTargetId)
    {
        $this->onQueue((string) config('fanta.scraping.queue'));
    }

    public function handle(ScrapeRunner $runner): void
    {
        $target = ScrapeTarget::query()->find($this->scrapeTargetId);

        if ($target === null) {
            return;
        }

        try {
            $runner->runScheduled($target, (string) Str::uuid());
        } catch (Throwable $exception) {
            Log::warning("Scrape on-demand fallito per la testata #{$target->id} ({$target->name}): {$exception->getMessage()}");
        }
    }
}

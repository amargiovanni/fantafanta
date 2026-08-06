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
 * Il giro schedulato ogni `schedule_interval_minutes` (briefing §7.4): tutte
 * le testate abilitate, una dopo l'altra. Un solo job, non uno per testata —
 * lo scraping schedulato non ha bisogno del parallelismo del full scrape, e
 * tenerlo sequenziale rende banale il tetto di estrazioni (un contatore
 * dentro un solo giro, senza bisogno di coordinamento fra job concorrenti).
 *
 * Una testata che fallisce non ferma le altre: try/catch per ciascuna, log e
 * si passa oltre (spec Fase 4, §Architettura).
 */
class RunScheduledScrape implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue((string) config('fanta.scraping.queue'));
    }

    public function handle(ScrapeRunner $runner): void
    {
        $runId = (string) Str::uuid();

        ScrapeTarget::query()
            ->where('enabled', true)
            ->get()
            ->each(function (ScrapeTarget $target) use ($runner, $runId): void {
                try {
                    $result = $runner->runScheduled($target, $runId);

                    if ($result->skipped) {
                        Log::info("Scraping schedulato saltato per la testata #{$target->id} ({$target->name}): {$result->note}");
                    }
                } catch (Throwable $exception) {
                    Log::warning("Scraping schedulato fallito per la testata #{$target->id} ({$target->name}): {$exception->getMessage()}");
                }
            });
    }
}

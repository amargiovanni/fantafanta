<?php

namespace App\Jobs;

use App\Enums\PlanTrigger;
use App\Models\Auction;
use App\Services\Replanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Il risveglio del debounce (briefing §7.3, spec sala d'asta).
 *
 * Ne parte uno per ogni aggiudicazione, con venti secondi di ritardo e il
 * timestamp della propria schedulazione a bordo. Al risveglio quasi tutti
 * scoprono che nel frattempo è arrivato un evento più recente — quindi esiste
 * un job più giovane che farà il lavoro — ed escono senza fare nulla.
 *
 * Il job è deliberatamente stupido: la regola sta in Replanner, perché è la
 * stessa che deve valere per il bottone "Ricalcola ora".
 */
class ScheduleReplan implements ShouldQueue
{
    use Queueable;

    /**
     * Un solo tentativo: se questo job fallisce, il replan che avrebbe
     * lanciato è già inutile — lo stato dell'asta è andato avanti e sarà
     * l'aggiudicazione successiva a farne partire uno aggiornato.
     */
    public int $tries = 1;

    public int $timeout = 30;

    /**
     * Quante volte ci si può rimettere in coda perché un replan era già in
     * volo. Il tetto esiste solo per non lasciare un job che si riaccoda in
     * eterno se un run restasse appeso: dieci attese coprono ampiamente i
     * 30-90 secondi di un replan.
     */
    private const MAX_REQUEUES = 10;

    public function __construct(
        public readonly int $auctionId,
        public readonly int $scheduledAt,
        public readonly string $trigger = 'acquisition',
        public readonly int $requeues = 0,
    ) {
        $this->onQueue((string) config('fanta.replan.queue'));
    }

    public function handle(Replanner $replanner): void
    {
        if (! $replanner->shouldRun($this->auctionId, $this->scheduledAt)) {
            return;
        }

        $auction = Auction::query()->find($this->auctionId);

        if ($auction === null || ! $auction->isLive()) {
            return;
        }

        $trigger = PlanTrigger::tryFrom($this->trigger) ?? PlanTrigger::Acquisition;

        if ($replanner->launch($auction, $trigger) !== null) {
            return;
        }

        // Un replan era già in corso: questi acquisti non sono ancora entrati
        // in nessun piano. Si riprova quando quello in volo sarà atterrato.
        if ($this->requeues < self::MAX_REQUEUES) {
            self::dispatch($this->auctionId, $this->scheduledAt, $this->trigger, $this->requeues + 1)
                ->delay(now()->addSeconds(max(5, (int) config('fanta.replan.debounce'))));
        }
    }
}

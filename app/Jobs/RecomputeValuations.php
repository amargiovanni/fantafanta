<?php

namespace App\Jobs;

use App\Models\Auction;
use App\Services\ValuationEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Ricalcolo completo delle valutazioni (briefing §5).
 *
 * Sta in coda `default` e non in `ai`: è PHP puro, non tocca l'AI e non deve
 * mai mettersi in fila dietro a un replan che dura un minuto. Il costo è di
 * pochi secondi sull'intero listone, quindi si ricalcola tutto ogni volta
 * invece di inseguire quali giocatori sarebbero cambiati — un ricalcolo
 * parziale sbagliato non si vede finché non è tardi.
 */
class RecomputeValuations implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly ?int $auctionId = null)
    {
        $this->onQueue('default');
    }

    public function handle(ValuationEngine $engine): void
    {
        $auction = $this->auctionId !== null
            ? Auction::query()->find($this->auctionId)
            : null;

        $engine->recompute($auction);
    }
}

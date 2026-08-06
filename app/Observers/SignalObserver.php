<?php

namespace App\Observers;

use App\Jobs\RecomputeValuations;
use App\Models\Signal;

/**
 * Ogni movimento nella conoscenza rimette in discussione le valutazioni
 * (briefing §5): un segnale nuovo, una correzione dal backoffice, un segnale
 * superato da uno più recente o cancellato.
 *
 * Il ricalcolo parte dopo il commit: dentro la transazione di un batch di
 * segnali il job partirebbe leggendo dati che potrebbero non essere ancora
 * scritti — o non esserlo mai, se la transazione fallisce.
 */
class SignalObserver
{
    public function created(Signal $signal): void
    {
        $this->recompute();
    }

    public function updated(Signal $signal): void
    {
        $this->recompute();
    }

    public function deleted(Signal $signal): void
    {
        $this->recompute();
    }

    private function recompute(): void
    {
        RecomputeValuations::dispatch()->afterCommit();
    }
}

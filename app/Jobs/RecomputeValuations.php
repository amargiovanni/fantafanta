<?php

namespace App\Jobs;

use App\Models\Auction;
use App\Services\ValuationEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DebounceFor;

/**
 * Ricalcolo completo delle valutazioni (briefing §5).
 *
 * Sta in coda `default` e non in `ai`: è PHP puro, non tocca l'AI e non deve
 * mai mettersi in fila dietro a un replan che dura un minuto. Il costo è di
 * pochi secondi sull'intero listone, quindi si ricalcola tutto ogni volta
 * invece di inseguire quali giocatori sarebbero cambiati — un ricalcolo
 * parziale sbagliato non si vede finché non è tardi.
 *
 * Debounce (ADR 0004, obbligo dichiarato): una raffica di segnali dispatchava
 * un job per segnale, senza alcuna deduplica. Con l'ingestione automatica
 * della Fase 4 questo diventa una raffica vera, non più teorica. Si usa il
 * debounce nativo delle code (stesso principio marker+delay di `Replanner`,
 * ma qui non serve la coreografia della riga `plans` in `generating`): ogni
 * dispatch riscrive il marker e ritarda l'esecuzione di `debounceFor`
 * secondi; se arriva un dispatch più recente prima che parta, il job più
 * vecchio viene scartato silenziosamente all'esecuzione invece di girare.
 * `maxWait` è la stessa rete di sicurezza del replan: oltre quella soglia il
 * job parte comunque anche a raffica non finita.
 *
 * `maxWait` è un argomento di attributo PHP e deve restare una costante
 * letterale (gli attributi non possono chiamare `config()`); `debounceFor`
 * invece è riscritto nel costruttore da `config('fanta.recompute_valuations.debounce')`
 * — la proprietà pubblica sovrascrive il valore dell'attributo (vedi
 * `Illuminate\Support\Traits\ReadsClassAttributes::getAttributeValue()`), così
 * resta configurabile via env dove il tetto d'attesa no.
 */
#[DebounceFor(debounceFor: 5, maxWait: 30)]
class RecomputeValuations implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public ?int $debounceFor = null;

    public function __construct(public readonly ?int $auctionId = null)
    {
        $this->onQueue('default');
        $this->debounceFor = (int) config('fanta.recompute_valuations.debounce');
    }

    /**
     * Scopo del debounce: per asta (o un pool globale se non c'è ancora
     * un'asta corrente), così una raffica su un'asta non assorbe un
     * ricalcolo dovuto altrove.
     */
    public function debounceId(): string
    {
        return (string) ($this->auctionId ?? 'global');
    }

    public function handle(ValuationEngine $engine): void
    {
        $auction = $this->auctionId !== null
            ? Auction::query()->find($this->auctionId)
            : null;

        $engine->recompute($auction);
    }
}

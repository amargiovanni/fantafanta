<?php

namespace App\Observers;

use App\Enums\PlayerStatus;
use App\Jobs\RecomputeValuations;
use App\Models\Acquisition;
use App\Models\Auction;
use App\Models\Player;
use App\Models\Valuation;
use App\Services\PlanSlotPromoter;
use App\Services\Replanner;

/**
 * Tutto ciò che deve succedere, senza eccezioni, quando un giocatore viene
 * aggiudicato — o quando quell'aggiudicazione viene annullata.
 *
 * Sta in un observer e non nella UI d'asta perché la Fase 3 registrerà gli
 * acquisti da tastiera in meno di tre secondi, il simulatore della Fase 5 li
 * genererà a raffica e i test li creano con una factory: tre percorsi diversi
 * che non possono avere tre comportamenti diversi.
 *
 * L'ordine conta: prima la valutazione al momento dell'acquisto (serve il
 * valore PRIMA che il giocatore esca dal listone disponibile), poi lo stato
 * del giocatore, poi la promozione deterministica dell'alternativa — che è
 * sincrona, perché il replan arriva troppo tardi (briefing §9 Fase 3) — e solo
 * alla fine il ricalcolo in coda.
 */
class AcquisitionObserver
{
    public function __construct(
        private readonly PlanSlotPromoter $promoter,
        private readonly Replanner $replanner,
    ) {}

    /**
     * Scatto della valutazione corrente: l'inflazione per ruolo confronta i
     * prezzi pagati con il valore di allora, non con quello di adesso.
     */
    public function creating(Acquisition $acquisition): void
    {
        if ($acquisition->valuation_at_purchase !== null) {
            return;
        }

        $acquisition->valuation_at_purchase = Valuation::query()
            ->where('player_id', $acquisition->player_id)
            ->value('adjusted_value');
    }

    public function created(Acquisition $acquisition): void
    {
        $this->setPlayerStatus($acquisition, PlayerStatus::Acquired);

        $this->promoter->apply($acquisition);

        RecomputeValuations::dispatch($acquisition->auction_id)->afterCommit();

        $this->scheduleReplan($acquisition);
    }

    /**
     * Undo (soft delete): il giocatore torna disponibile, i crediti tornano
     * alla squadra — effetto derivato del conteggio, nessun campo da toccare —
     * e il piano torna com'era, promozione compresa.
     */
    public function deleted(Acquisition $acquisition): void
    {
        $this->setPlayerStatus($acquisition, PlayerStatus::Available);

        $this->promoter->revert($acquisition);

        RecomputeValuations::dispatch($acquisition->auction_id)->afterCommit();

        $this->scheduleReplan($acquisition);
    }

    public function restored(Acquisition $acquisition): void
    {
        $this->setPlayerStatus($acquisition, PlayerStatus::Acquired);

        $this->promoter->apply($acquisition);

        RecomputeValuations::dispatch($acquisition->auction_id)->afterCommit();

        $this->scheduleReplan($acquisition);
    }

    /**
     * Il piano è diventato vecchio: si programma un replan, in coda al
     * silenzio (App\Services\Replanner).
     *
     * Solo per l'asta in corso: gli acquisti registrati su una sessione in
     * `setup` o già chiusa — i seed, i test, il simulatore della Fase 5 —
     * non devono far girare Claude.
     *
     * L'asta si rilegge invece di seguire la relazione perché in `deleted` il
     * modello non è "appena creato" e lo strict mode vieta il lazy loading.
     */
    private function scheduleReplan(Acquisition $acquisition): void
    {
        $auction = Auction::query()->find($acquisition->auction_id);

        if ($auction === null || ! $auction->isLive()) {
            return;
        }

        $this->replanner->schedule($auction);
    }

    /**
     * Update diretto e non salvataggio del modello: lo stato non entra
     * nell'indice di ricerca, quindi non c'è nulla da sincronizzare, e questo
     * percorso è dentro il budget dei tre secondi della sala d'asta.
     */
    private function setPlayerStatus(Acquisition $acquisition, PlayerStatus $status): void
    {
        Player::query()
            ->whereKey($acquisition->player_id)
            ->where('status', '!=', PlayerStatus::Removed)
            ->update(['status' => $status]);
    }
}

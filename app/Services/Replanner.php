<?php

namespace App\Services;

use App\Enums\PlanStatus;
use App\Enums\PlanTrigger;
use App\Jobs\RunClaudeTask;
use App\Jobs\ScheduleReplan;
use App\Models\Auction;
use App\Models\Plan;
use Illuminate\Support\Facades\Cache;

/**
 * Il ciclo di vita di un replan (briefing §7.3).
 *
 * Tre responsabilità che stanno insieme perché sono tre facce dello stesso
 * problema — quando far girare Claude, e come far vedere che sta girando:
 *
 *  1. il **debounce**: dopo ogni aggiudicazione il piano è vecchio, ma un run
 *     per acquisto vorrebbe dire tre run sovrapposti durante una raffica. Il
 *     run parte in coda al silenzio;
 *  2. la riga `plans` in stato `generating`, creata all'**avvio** del run e non
 *     alla sua fine: è quella che accende il badge "ricalcolo in corso" nella
 *     sala e in dashboard, e che fa rispondere `newer_version_generating` al
 *     tool `get_current_plan`. Senza, lo stato "sta girando" non esisterebbe da
 *     nessuna parte se non nella coda di Horizon, che la UI non guarda;
 *  3. la chiusura: se il run muore, quella riga diventa `failed` invece di
 *     restare `generating` per sempre (vedi RunClaudeTask::failed()).
 *
 * Nessun metodo qui dentro chiama l'AI in modo sincrono: il percorso caldo
 * della sala scrive una chiave in cache e mette un job in coda, e basta.
 */
class Replanner
{
    /**
     * Marker della raffica in corso. Porta due timestamp: `first` è il primo
     * evento non ancora servito da un run — quello su cui si misura il tetto
     * di attesa — e `last` è l'ultimo arrivato, quello che sposta in avanti la
     * scadenza del debounce.
     */
    public static function markerKey(int $auctionId): string
    {
        return "replan:pending:{$auctionId}";
    }

    /**
     * Registra l'evento e programma il risveglio.
     *
     * Ogni evento aggiorna `last` e mette in coda un `ScheduleReplan` che porta
     * il proprio timestamp: al risveglio, il job che scopre di non essere il
     * più giovane esce senza fare nulla. Di una raffica di tre acquisti
     * sopravvive quindi un solo run, quello schedulato dall'ultimo.
     */
    public function schedule(Auction $auction, PlanTrigger $trigger = PlanTrigger::Acquisition): void
    {
        $now = now();
        $marker = Cache::get(self::markerKey($auction->id));

        $first = (int) ($marker['first'] ?? $now->timestamp);

        Cache::put(
            self::markerKey($auction->id),
            ['first' => $first, 'last' => $now->timestamp],
            now()->addSeconds($this->maxWait() + $this->debounce() + 60),
        );

        ScheduleReplan::dispatch($auction->id, $now->timestamp, $trigger->value)
            ->delay(now()->addSeconds($this->debounce()))
            ->afterCommit();
    }

    /**
     * Il job si è svegliato: tocca a lui far partire il run?
     *
     * Sì solo se è il più giovane dei job in volo — nessun evento è arrivato
     * dopo la sua schedulazione — oppure se la raffica dura da più di
     * `max_wait` secondi, nel qual caso si parte comunque perché aspettare
     * ancora vorrebbe dire non ripianificare mai.
     */
    public function shouldRun(int $auctionId, int $scheduledAt): bool
    {
        $marker = Cache::get(self::markerKey($auctionId));

        if ($marker === null) {
            // Un altro job ha già fatto partire il run per questi eventi.
            return false;
        }

        if ((int) $marker['last'] <= $scheduledAt) {
            return true;
        }

        return now()->timestamp - (int) $marker['first'] >= $this->maxWait();
    }

    /**
     * Fa partire il run adesso, saltando il debounce: è il bottone "Ricalcola
     * ora" e l'uscita normale del job di debounce.
     *
     * Restituisce la riga `plans` in stato `generating` appena creata, oppure
     * null se un replan è già in volo — due run concorrenti scriverebbero due
     * versioni del piano a partire dallo stesso stato, e vincerebbe l'ultimo
     * ad arrivare, non il più informato.
     */
    public function launch(Auction $auction, PlanTrigger $trigger = PlanTrigger::Acquisition): ?Plan
    {
        // Il marker sopravvive al rifiuto: quegli eventi non sono stati serviti
        // da nessun run, e buttarli via qui significherebbe restare col piano
        // vecchio finché non arriva la prossima aggiudicazione.
        if ($this->isRunning($auction)) {
            return null;
        }

        Cache::forget(self::markerKey($auction->id));

        $plan = Plan::query()->create([
            'auction_id' => $auction->id,
            'version' => (int) Plan::query()->where('auction_id', $auction->id)->max('version') + 1,
            'trigger' => $trigger,
            'status' => PlanStatus::Generating,
        ]);

        RunClaudeTask::dispatch(
            task: 'replan',
            promptFile: 'replan.md',
            context: ['auction_id' => $auction->id, 'plan_id' => $plan->id],
            variables: [
                'today' => now()->toDateString(),
                'auction_id' => $auction->id,
                'trigger' => $trigger->value,
            ],
            queue: (string) config('fanta.replan.queue'),
        );

        return $plan;
    }

    /**
     * C'è già una versione in generazione più recente dell'ultima pronta.
     */
    public function isRunning(Auction $auction): bool
    {
        return $auction->plans()
            ->where('status', PlanStatus::Generating)
            ->exists();
    }

    private function debounce(): int
    {
        return max(0, (int) config('fanta.replan.debounce'));
    }

    private function maxWait(): int
    {
        return max($this->debounce(), (int) config('fanta.replan.max_wait'));
    }
}

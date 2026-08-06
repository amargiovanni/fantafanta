<?php

namespace App\Scraping\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Tetto di spesa estrazioni per giro di scraping (spec Fase 4, §Tetto).
 *
 * Ogni articolo nuovo è un run Claude a pagamento: oltre `max_extractions_per_scrape`
 * le source restano `queued` con una nota, non spariscono. Il contatore vive
 * in cache, chiavato per `runId` (un uuid per il run schedulato o per il
 * singolo target on-demand, l'id del batch Horizon per il full scrape) così
 * che job concorrenti sullo stesso giro — i job del full scrape girano uno
 * per testata, potenzialmente in parallelo — condividano lo stesso tetto.
 *
 * `Cache::increment` è atomico sul driver Redis (usato in produzione), che è
 * ciò che rende sicura la concorrenza fra i job del batch; sul driver array
 * dei test non c'è concorrenza reale quindi la garanzia non serve.
 */
class ExtractionGate
{
    /**
     * Consuma un posto nel tetto. True se l'estrazione può partire subito,
     * false se il tetto per questo giro è già stato raggiunto.
     */
    public function tryAcquire(string $runId): bool
    {
        $key = $this->key($runId);
        $count = Cache::increment($key);

        if ($count === 1) {
            // Solo il primo incremento imposta la scadenza: rileggere il
            // valore adesso e riscriverlo con TTL non perde nessun conteggio,
            // nel peggiore dei casi allunga di poco la vita della chiave.
            Cache::put($key, $count, now()->addHours(6));
        }

        return $count <= $this->cap();
    }

    public function count(string $runId): int
    {
        return (int) Cache::get($this->key($runId), 0);
    }

    public function cap(): int
    {
        return max(0, (int) config('fanta.scraping.max_extractions_per_scrape'));
    }

    private function key(string $runId): string
    {
        return "scraping:extractions:{$runId}";
    }
}

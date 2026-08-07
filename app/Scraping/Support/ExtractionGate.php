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
 * `Cache::increment` è atomico sul driver Redis: una chiave assente parte da
 * 0 e l'INCR la crea, tutto in un colpo. NON è così su tutti i driver: il
 * driver `database` (quello effettivamente in produzione, vedi CACHE_STORE
 * in .env) fa un semplice UPDATE sulla riga esistente e ritorna `false`
 * SENZA crearla se la chiave non c'è ancora — e la chiave del gate è sempre
 * nuova a inizio giro (un uuid per lo schedulato, l'id del batch Horizon per
 * il full scrape). Senza il seed esplicito qui sotto, `tryAcquire` autorizza
 * sempre (`false <= cap()` è vero in PHP): è la causa radice dell'incidente
 * del 2026-08-06, dove il tetto non ha retto su un full scrape (130 run
 * reali di Claude eseguiti prima dell'intervento manuale). `Cache::add` seeda
 * la chiave con un INSERT ... IGNORE atomico anche sul driver `database`
 * (unique index su `key`), quindi resta sicuro con più job del batch che
 * chiamano `tryAcquire` in concorrenza sullo stesso runId.
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

        // Seed idempotente e atomico: non sovrascrive se un altro job del
        // batch l'ha già creata (o incrementata) nel frattempo.
        Cache::add($key, 0, now()->addHours(6));

        $count = Cache::increment($key);

        return $count !== false && $count <= $this->cap();
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

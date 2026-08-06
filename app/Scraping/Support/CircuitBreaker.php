<?php

namespace App\Scraping\Support;

use App\Models\ScrapeTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Circuit breaker per testata (spec Fase 4, §Etica e robustezza).
 *
 * Stato in cache, non in tabella — lo stesso stile del marker di debounce di
 * `Replanner`: è effimero per natura (si autochiude da solo dopo il
 * cooldown) e non ha senso sopravviva a un `php artisan cache:clear`. Nessun
 * `Cache::lock`: un contatore letto-e-riscritto è sufficiente perché lo
 * scraping di una singola testata non gira mai in parallelo con se stesso
 * (un solo job scheduler, o un job per testata nel full scrape — mai due
 * job per la STESSA testata contemporaneamente).
 *
 * 5 fallimenti consecutivi → aperto per `circuit_breaker_cooldown_minutes`;
 * un successo azzera tutto, non solo il contatore: un circuito che si è
 * appena richiuso merita fiducia piena, non un contatore a metà.
 */
class CircuitBreaker
{
    public static function key(int $scrapeTargetId): string
    {
        return "scraping:circuit:{$scrapeTargetId}";
    }

    public function isOpen(ScrapeTarget $target): bool
    {
        $state = $this->read($target);

        if ($state['opened_until'] === null) {
            return false;
        }

        if (CarbonImmutable::now()->lt($state['opened_until'])) {
            return true;
        }

        // Cooldown scaduto: il circuito si richiude da solo al prossimo controllo.
        $this->clear($target);

        return false;
    }

    public function recordFailure(ScrapeTarget $target): void
    {
        $state = $this->read($target);
        $failures = $state['failures'] + 1;
        $openedUntil = $state['opened_until'];

        if ($failures >= $this->threshold()) {
            $openedUntil = CarbonImmutable::now()->addMinutes($this->cooldownMinutes());
        }

        Cache::put(self::key($target->id), [
            'failures' => $failures,
            'opened_until' => $openedUntil?->toIso8601String(),
        ], now()->addDay());
    }

    public function recordSuccess(ScrapeTarget $target): void
    {
        $this->clear($target);
    }

    /**
     * @return array{open: bool, failures: int, opened_until: ?CarbonImmutable}
     */
    public function state(ScrapeTarget $target): array
    {
        $open = $this->isOpen($target);
        $state = $this->read($target);

        return [
            'open' => $open,
            'failures' => $state['failures'],
            'opened_until' => $state['opened_until'],
        ];
    }

    private function clear(ScrapeTarget $target): void
    {
        Cache::forget(self::key($target->id));
    }

    /**
     * @return array{failures: int, opened_until: ?CarbonImmutable}
     */
    private function read(ScrapeTarget $target): array
    {
        $payload = Cache::get(self::key($target->id));

        return [
            'failures' => (int) ($payload['failures'] ?? 0),
            'opened_until' => isset($payload['opened_until'])
                ? CarbonImmutable::parse($payload['opened_until'])
                : null,
        ];
    }

    private function threshold(): int
    {
        return max(1, (int) config('fanta.scraping.circuit_breaker_threshold'));
    }

    private function cooldownMinutes(): int
    {
        return max(1, (int) config('fanta.scraping.circuit_breaker_cooldown_minutes'));
    }
}

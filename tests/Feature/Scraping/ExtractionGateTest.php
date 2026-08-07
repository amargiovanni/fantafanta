<?php

use App\Scraping\Support\ExtractionGate;
use Illuminate\Support\Str;

/**
 * Riproduce a livello minimo la causa radice dell'incidente del 2026-08-06
 * (vedi ScrapeRunnerTest per lo scenario completo): `ExtractionGate` è
 * testato di default sotto il driver cache `array` (phpunit.xml), che
 * auto-inizializza un contatore al primo `Cache::increment`. Il driver
 * `database`, usato in produzione (.env: CACHE_STORE=database), NON lo fa —
 * `Illuminate\Cache\DatabaseStore::increment` ritorna `false` senza scrivere
 * nulla quando la chiave non esiste ancora. Siccome la chiave del gate è
 * sempre nuova (un runId per giro), il tetto non scattava mai:
 * `false <= cap()` è vero in PHP, quindi `tryAcquire` autorizzava sempre.
 */
it('applica il tetto anche sul cache store "database" usato in produzione, non solo su "array"', function () {
    config(['cache.default' => 'database']);

    $gate = app(ExtractionGate::class);
    $runId = (string) Str::uuid();
    $cap = $gate->cap();

    $acquired = collect(range(1, $cap + 3))
        ->map(fn () => $gate->tryAcquire($runId));

    expect($acquired->filter()->count())->toBe($cap)
        ->and($gate->count($runId))->toBe($cap + 3);
});

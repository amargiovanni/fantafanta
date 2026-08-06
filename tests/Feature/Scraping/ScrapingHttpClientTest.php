<?php

use App\Models\ScrapeTarget;
use App\Scraping\Support\CircuitBreaker;
use App\Scraping\Support\Exceptions\CircuitOpenException;
use App\Scraping\Support\Exceptions\RobotsDisallowedException;
use App\Scraping\Support\Exceptions\ScrapingHttpException;
use App\Scraping\Support\ScrapingHttpClient;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(fn () => Cache::flush());
afterEach(fn () => Carbon\Carbon::setTestNow());

/**
 * robots.txt "permetti tutto", per i test che non riguardano robots.txt.
 */
function allowAllRobots(string $origin): array
{
    return ["{$origin}/robots.txt" => Http::response("User-agent: *\nDisallow:")];
}

// Spec Fase 4, test 4 (parte 1): 429 → backoff esponenziale, nessun martellamento.
it('ritenta con backoff su 429 senza martellare il dominio', function () {
    $target = ScrapeTarget::factory()->create(['url' => 'https://www.esempio.it']);
    $url = 'https://www.esempio.it/articolo';

    Sleep::fake();
    Http::fake([
        ...allowAllRobots('https://www.esempio.it'),
        $url => Http::sequence()
            ->push('', 429)
            ->push('', 429)
            ->push('ok', 200),
    ]);

    $response = app(ScrapingHttpClient::class)->get($target, $url);

    expect($response->successful())->toBeTrue();

    // Tre richieste in tutto (più quella a robots.txt): il tentativo
    // iniziale più i due ritentativi previsti dal backoff, non uno di più.
    Http::assertSentCount(4);

    // L'attesa segue esattamente la sequenza configurata (30s, 120s), mai a raffica.
    Sleep::assertSequence([
        Sleep::for(30000)->milliseconds(),
        Sleep::for(120000)->milliseconds(),
    ]);

    expect(app(CircuitBreaker::class)->isOpen($target))->toBeFalse();
});

// Spec Fase 4, test 4 (parte 2): il circuito si apre dopo 5 fallimenti
// consecutivi e si richiude da solo dopo il cooldown.
it('apre il circuito dopo 5 fallimenti consecutivi e lo richiude dopo il cooldown', function () {
    config(['fanta.scraping.circuit_breaker_threshold' => 5, 'fanta.scraping.circuit_breaker_cooldown_minutes' => 30]);

    $target = ScrapeTarget::factory()->create(['url' => 'https://www.esempio.it']);
    $url = 'https://www.esempio.it/articolo';

    Sleep::fake();
    Http::fake([
        ...allowAllRobots('https://www.esempio.it'),
        $url => Http::response('', 503),
    ]);

    $client = app(ScrapingHttpClient::class);
    $breaker = app(CircuitBreaker::class);

    for ($i = 0; $i < 5; $i++) {
        expect(fn () => $client->get($target, $url))->toThrow(ScrapingHttpException::class);
    }

    expect($breaker->isOpen($target))->toBeTrue();

    // Il circuito aperto blocca la richiesta PRIMA di fare rete: nessuna
    // richiesta HTTP in più rispetto a quelle già contate sopra.
    $sentBefore = count(Http::recorded());
    expect(fn () => $client->get($target, $url))->toThrow(CircuitOpenException::class);
    expect(count(Http::recorded()))->toBe($sentBefore);

    Carbon\Carbon::setTestNow(now()->addMinutes(31));

    expect($breaker->isOpen($target))->toBeFalse();
});

it('un successo azzera il contatore dei fallimenti del circuito', function () {
    config(['fanta.scraping.circuit_breaker_threshold' => 5]);

    $target = ScrapeTarget::factory()->create(['url' => 'https://www.esempio.it']);
    $url = 'https://www.esempio.it/articolo';

    Sleep::fake();
    $breaker = app(CircuitBreaker::class);
    $client = app(ScrapingHttpClient::class);

    // Http::fake() non sostituisce le regole di uno stub già registrato per lo
    // stesso URL, le accoda: un'unica regola stateful — 503 per i primi 9
    // tentativi (3 chiamate × 3 tentativi ciascuna per il backoff), poi 200 —
    // evita di richiamare Http::fake() due volte a metà test.
    $attempts = 0;
    Http::fake([
        ...allowAllRobots('https://www.esempio.it'),
        $url => function () use (&$attempts) {
            $attempts++;

            return $attempts <= 9 ? Http::response('', 503) : Http::response('ok', 200);
        },
    ]);

    for ($i = 0; $i < 3; $i++) {
        expect(fn () => $client->get($target, $url))->toThrow(ScrapingHttpException::class);
    }
    expect($breaker->state($target)['failures'])->toBe(3);

    $client->get($target, $url);

    expect($breaker->state($target)['failures'])->toBe(0)
        ->and($breaker->isOpen($target))->toBeFalse();
});

// Spec Fase 4, test 5: robots.txt con Disallow → URL saltato.
it('salta un URL vietato da robots.txt senza nemmeno richiederlo', function () {
    $target = ScrapeTarget::factory()->create(['url' => 'https://www.esempio.it']);

    Http::fake([
        'https://www.esempio.it/robots.txt' => Http::response(<<<'TXT'
            User-agent: *
            Disallow: /riservato/
            TXT),
    ]);

    expect(fn () => app(ScrapingHttpClient::class)->get($target, 'https://www.esempio.it/riservato/pagina'))
        ->toThrow(RobotsDisallowedException::class);

    // Solo la richiesta a robots.txt è partita: l'URL vietato non è mai stato chiamato.
    Http::assertSentCount(1);
});

it('permette un URL non coperto dal Disallow di robots.txt', function () {
    $target = ScrapeTarget::factory()->create(['url' => 'https://www.esempio.it']);

    Http::fake([
        'https://www.esempio.it/robots.txt' => Http::response(<<<'TXT'
            User-agent: *
            Disallow: /riservato/
            TXT),
        'https://www.esempio.it/pubblico/articolo' => Http::response('ok', 200),
    ]);

    $response = app(ScrapingHttpClient::class)->get($target, 'https://www.esempio.it/pubblico/articolo');

    expect($response->successful())->toBeTrue();
});

it('mette in cache robots.txt per 24h invece di richiederlo a ogni fetch', function () {
    $target = ScrapeTarget::factory()->create(['url' => 'https://www.esempio.it']);

    Http::fake([
        'https://www.esempio.it/robots.txt' => Http::response("User-agent: *\nDisallow:"),
        'https://www.esempio.it/*' => Http::response('ok', 200),
    ]);

    $client = app(ScrapingHttpClient::class);
    $client->get($target, 'https://www.esempio.it/articolo-1');
    $client->get($target, 'https://www.esempio.it/articolo-2');

    Http::assertSentCount(3); // 1 robots.txt + 2 articoli, non 2+2
});

// Spec Fase 4, test 6: rate limit ≥2s fra due richieste allo stesso dominio.
it('rispetta il rate limit minimo fra due richieste allo stesso dominio', function () {
    config(['fanta.scraping.rate_limit_seconds' => 2]);

    $target = ScrapeTarget::factory()->create(['url' => 'https://www.esempio.it']);

    Sleep::fake();
    Http::fake(['https://www.esempio.it/*' => Http::response('ok', 200)]);

    $client = app(ScrapingHttpClient::class);
    $client->get($target, 'https://www.esempio.it/articolo-1');
    $client->get($target, 'https://www.esempio.it/articolo-2');

    // La seconda richiesta arriva a ridosso della prima (siamo nello stesso
    // test, in millisecondi reali): il rate limiter deve aver imposto
    // un'attesa vicina al minimo configurato.
    Sleep::assertSlept(fn (CarbonInterval $duration) => $duration->totalMilliseconds >= 1900, 1);
});

it('non aspetta prima della primissima richiesta a un dominio', function () {
    config(['fanta.scraping.rate_limit_seconds' => 2]);

    $target = ScrapeTarget::factory()->create(['url' => 'https://www.esempio.it']);

    Sleep::fake();
    Http::fake(['https://www.esempio.it/*' => Http::response('ok', 200)]);

    app(ScrapingHttpClient::class)->get($target, 'https://www.esempio.it/articolo-1');

    Sleep::assertNeverSlept();
});

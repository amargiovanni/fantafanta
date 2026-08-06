<?php

use App\Jobs\ProcessSource;
use App\Jobs\RunScheduledScrape;
use App\Models\ScrapeTarget;
use App\Models\Source;
use App\Scraping\ScrapeRunner;
use App\Scraping\Support\CircuitBreaker;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Cache::flush();
    Sleep::fake();
    Bus::fake([ProcessSource::class]);
});

it('scandisce tutte le testate abilitate e salta quelle disabilitate', function () {
    $abilitata = ScrapeTarget::factory()->create([
        'url' => 'https://www.abilitata.it',
        'rss_url' => 'https://www.abilitata.it/feed/',
        'enabled' => true,
    ]);
    $disabilitata = ScrapeTarget::factory()->create([
        'url' => 'https://www.disabilitata.it',
        'rss_url' => 'https://www.disabilitata.it/feed/',
        'enabled' => false,
    ]);

    $pubDate = now()->toRfc2822String();

    Http::fake([
        $abilitata->rss_url => Http::response(<<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel>
                <item><title>Notizia della testata abilitata</title><link>https://www.abilitata.it/notizia</link><pubDate>{$pubDate}</pubDate></item>
            </channel></rss>
            XML),
        '*' => Http::response('non dovrebbe partire', 500),
    ]);

    (new RunScheduledScrape)->handle(app(ScrapeRunner::class));

    expect(Source::query()->where('scrape_target_id', $abilitata->id)->exists())->toBeTrue()
        ->and($disabilitata->fresh()->last_scraped_at)->toBeNull();

    Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), 'disabilitata.it'));
});

it('una testata che fallisce non impedisce alle altre di essere scandite', function () {
    $rotta = ScrapeTarget::factory()->create(['url' => 'https://www.rotta.it', 'rss_url' => 'https://www.rotta.it/feed/']);
    $sana = ScrapeTarget::factory()->create(['url' => 'https://www.sana.it', 'rss_url' => 'https://www.sana.it/feed/']);

    $pubDate = now()->toRfc2822String();

    Http::fake([
        $rotta->rss_url => Http::response('errore interno', 500),
        $sana->rss_url => Http::response(<<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel>
                <item><title>Notizia dalla testata sana</title><link>https://www.sana.it/notizia</link><pubDate>{$pubDate}</pubDate></item>
            </channel></rss>
            XML),
        // Copre robots.txt di entrambe le testate e l'eventuale fallback HTML
        // di "rotta" (il suo feed fallisce, quindi ScrapeRunner tenta anche il
        // crawl della pagina lista): un 500 qui è comunque un fallimento
        // coerente, non introduce risultati inattesi.
        '*' => Http::response('errore', 500),
    ]);

    (new RunScheduledScrape)->handle(app(ScrapeRunner::class));

    expect(Source::query()->where('scrape_target_id', $sana->id)->exists())->toBeTrue()
        ->and(Source::query()->where('scrape_target_id', $rotta->id)->exists())->toBeFalse()
        ->and($rotta->fresh()->last_scraped_at)->not->toBeNull(); // il tentativo è stato fatto, solo senza risultati
});

it('salta una testata con il circuito aperto senza fare richieste', function () {
    config(['fanta.scraping.circuit_breaker_threshold' => 1]);

    $target = ScrapeTarget::factory()->create(['url' => 'https://www.esempio.it', 'rss_url' => 'https://www.esempio.it/feed/']);

    app(CircuitBreaker::class)->recordFailure($target);

    Http::fake(['*' => Http::response('non dovrebbe partire', 500)]);

    (new RunScheduledScrape)->handle(app(ScrapeRunner::class));

    Http::assertNothingSent();
});

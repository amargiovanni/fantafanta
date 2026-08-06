<?php

use App\Enums\SourceOrigin;
use App\Enums\SourceStatus;
use App\Enums\SourceType;
use App\Jobs\ProcessSource;
use App\Models\ScrapeTarget;
use App\Models\Source;
use App\Scraping\ScrapeRunner;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake([ProcessSource::class]);
});

function rssFeed(array $items): string
{
    $entries = collect($items)->map(fn (array $item) => <<<XML
        <item>
            <title>{$item['title']}</title>
            <link>{$item['url']}</link>
            <pubDate>{$item['pubDate']}</pubDate>
        </item>
        XML)->implode("\n");

    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0">
            <channel>
                <title>Feed di prova</title>
                {$entries}
            </channel>
        </rss>
        XML;
}

// Spec Fase 4, test 1: RSS con 3 articoli, 1 già noto (stesso URL già in
// archivio) → 2 sources nuove, origin corretta.
it('crea solo le source nuove da un feed RSS, saltando l\'articolo già noto', function () {
    $target = ScrapeTarget::factory()->create([
        'url' => 'https://www.fantamaster.it',
        'rss_url' => 'https://www.fantamaster.it/feed/',
    ]);

    Source::factory()->create(['url' => 'https://www.fantamaster.it/articolo-gia-noto']);

    Http::fake([
        $target->rss_url => Http::response(rssFeed([
            ['title' => 'Osimhen salta il derby per infortunio', 'url' => 'https://www.fantamaster.it/articolo-gia-noto', 'pubDate' => now()->toRfc2822String()],
            ['title' => 'Nuovo terzino per l\'Inter', 'url' => 'https://www.fantamaster.it/nuovo-terzino', 'pubDate' => now()->toRfc2822String()],
            ['title' => 'Ballottaggio in porta per la Roma', 'url' => 'https://www.fantamaster.it/ballottaggio-porta', 'pubDate' => now()->toRfc2822String()],
        ])),
    ]);

    $result = app(ScrapeRunner::class)->runScheduled($target, (string) Str::uuid());

    expect($result->created)->toBe(2)
        ->and(Source::query()->count())->toBe(3);

    $new = Source::query()->where('url', '!=', 'https://www.fantamaster.it/articolo-gia-noto')->get();

    expect($new)->toHaveCount(2)
        ->and($new->pluck('type')->unique()->all())->toBe([SourceType::ScrapedArticle])
        ->and($new->pluck('origin')->unique()->all())->toBe([SourceOrigin::ScheduledScrape])
        ->and($new->pluck('scrape_target_id')->unique()->all())->toBe([$target->id]);

    Bus::assertDispatchedTimes(ProcessSource::class, 2);
});

// Spec Fase 4, test 2: stesso articolo da due testate con titoli quasi
// identici → una sola source.
it('non duplica un articolo ripreso con titolo quasi identico da un\'altra testata', function () {
    $gazzetta = ScrapeTarget::factory()->create(['url' => 'https://www.gazzetta.it', 'rss_url' => 'https://www.gazzetta.it/rss/serie-a.xml']);
    $fantaMaster = ScrapeTarget::factory()->create(['url' => 'https://www.fantamaster.it', 'rss_url' => 'https://www.fantamaster.it/feed/']);

    Http::fake([
        $gazzetta->rss_url => Http::response(rssFeed([
            ['title' => 'Osimhen si ferma: salta il derby per un problema muscolare', 'url' => 'https://www.gazzetta.it/osimhen-derby', 'pubDate' => now()->toRfc2822String()],
        ])),
        $fantaMaster->rss_url => Http::response(rssFeed([
            ['title' => 'Osimhen si ferma, salta il derby per un problema muscolare', 'url' => 'https://www.fantamaster.it/osimhen-derby-salta', 'pubDate' => now()->toRfc2822String()],
        ])),
    ]);

    $runner = app(ScrapeRunner::class);
    $runId = (string) Str::uuid();

    $runner->runScheduled($gazzetta, $runId);
    $runner->runScheduled($fantaMaster, $runId);

    expect(Source::query()->count())->toBe(1);
});

it('non deduplica due articoli sulla stessa notizia con titoli sufficientemente diversi', function () {
    // Diverso taglio giornalistico della stessa notizia: qui la dedup NON deve
    // scattare — sono le due source risultanti, entrambe processate, a far
    // corroborare il segnale (SignalWriter, già testato in Fase 1).
    $gazzetta = ScrapeTarget::factory()->create(['url' => 'https://www.gazzetta.it', 'rss_url' => 'https://www.gazzetta.it/rss/serie-a.xml']);
    $sosFanta = ScrapeTarget::factory()->create(['url' => 'https://www.sosfanta.com', 'rss_url' => 'https://www.sosfanta.com/feed/']);

    Http::fake([
        $gazzetta->rss_url => Http::response(rssFeed([
            ['title' => 'Osimhen, guaio muscolare: a rischio per il derby', 'url' => 'https://www.gazzetta.it/a', 'pubDate' => now()->toRfc2822String()],
        ])),
        $sosFanta->rss_url => Http::response(rssFeed([
            ['title' => 'Infortunio Osimhen, le condizioni dell\'attaccante azzurro', 'url' => 'https://www.sosfanta.com/b', 'pubDate' => now()->toRfc2822String()],
        ])),
    ]);

    $runner = app(ScrapeRunner::class);
    $runId = (string) Str::uuid();

    $runner->runScheduled($gazzetta, $runId);
    $runner->runScheduled($sosFanta, $runId);

    expect(Source::query()->count())->toBe(2);
});

// Spec Fase 4, test 3: fallback HTML quando rss_url è null.
it('scopre ed estrae articoli dal crawl HTML quando la testata non ha un feed RSS', function () {
    $target = ScrapeTarget::factory()->create(['url' => 'https://www.esempio-news.it/calcio', 'rss_url' => null]);

    $listHtml = <<<'HTML'
        <html><body>
            <nav>
                <a href="/">Home</a>
                <a href="/abbonati">Abbonati ora</a>
            </nav>
            <main>
                <a href="https://www.esempio-news.it/calcio/lukaku-guaio-fisico-salta-la-prossima">Lukaku, guaio fisico: salta la prossima di campionato</a>
                <a href="https://www.esempio-news.it/calcio/rientro-in-gruppo-per-il-difensore">Rientro in gruppo per il difensore centrale titolare</a>
            </main>
            <footer><a href="/privacy">Informativa privacy e cookie</a></footer>
        </body></html>
        HTML;

    Http::fake([
        $target->url => Http::response($listHtml),
        'https://www.esempio-news.it/calcio/lukaku-guaio-fisico-salta-la-prossima' => Http::response(
            '<html><head><title>Lukaku, guaio fisico: salta la prossima | Esempio News</title></head>'
            .'<body><article><h1>Lukaku, guaio fisico: salta la prossima</h1><p>L\'attaccante ha accusato un affaticamento muscolare in allenamento.</p></article></body></html>'
        ),
        'https://www.esempio-news.it/calcio/rientro-in-gruppo-per-il-difensore' => Http::response(
            '<html><head><title>Rientro in gruppo per il difensore | Esempio News</title></head>'
            .'<body><article><h1>Rientro in gruppo per il difensore</h1><p>Il centrale titolare si è allenato con la squadra senza limitazioni.</p></article></body></html>'
        ),
    ]);

    $result = app(ScrapeRunner::class)->runScheduled($target, (string) Str::uuid());

    expect($result->created)->toBe(2);

    $sources = Source::query()->get();

    expect($sources)->toHaveCount(2)
        ->and($sources->pluck('type')->unique()->all())->toBe([SourceType::ScrapedArticle])
        ->and($sources->pluck('title'))->toContain('Lukaku, guaio fisico: salta la prossima | Esempio News');

    // I link di navigazione e footer non sono diventati source.
    expect(Source::query()->where('url', 'like', '%abbonati%')->exists())->toBeFalse()
        ->and(Source::query()->where('url', 'like', '%privacy%')->exists())->toBeFalse();
});

// Spec Fase 4, test 8: tetto di estrazioni.
it('rispetta il tetto di estrazioni per giro, mettendo il resto in coda con nota', function () {
    config(['fanta.scraping.max_extractions_per_scrape' => 20]);

    $target = ScrapeTarget::factory()->create([
        'url' => 'https://www.fantamaster.it',
        'rss_url' => 'https://www.fantamaster.it/feed/',
    ]);

    // Titoli deliberatamente eterogenei: 25 notizie diverse, non varianti
    // ravvicinate l'una dell'altra — altrimenti la dedup per titolo (test 2)
    // ne scarterebbe alcune, ed è un altro comportamento che questo test non
    // vuole misurare.
    $soggetti = [
        'Osimhen', 'Lautaro Martinez', 'Leao', 'Kvaratskhelia', 'Chiesa', 'Zaccagni', 'Barella',
        'Retegui', 'Dybala', 'Kean', 'Vlahovic', 'Thuram', 'Calhanoglu', 'Di Lorenzo', 'Bastoni',
        'Theo Hernandez', 'Pellegrini', 'Zielinski', 'Berardi', 'Immobile', 'Politano', 'Frattesi',
        'Scamacca', 'Orsolini', 'Ferguson',
    ];
    $eventi = [
        'salta la prossima per un problema muscolare', 'torna in gruppo dopo lo stop', 'in dubbio per un fastidio alla caviglia',
        'squalificato per un turno dal giudice sportivo', 'rinnova il contratto fino al 2029', 'ballottaggio apertissimo per la maglia da titolare',
        'ko per un risentimento al polpaccio', 'recupera e sarà convocato', 'nel mirino di un club di Premier League',
        'positivo a un affaticamento, valutazioni in corso', 'pronto al rientro dal 1° minuto', 'si opera e salta il resto della stagione',
        'convocato in nazionale nonostante l\'acciacco', 'obiettivo di mercato per gennaio', 'fuori lista per scelta tecnica',
        'espulso nel finale, salterà il derby', 'vicino al ritorno dopo l\'intervento', 'stringe i denti e sarà in campo',
        'lascia il ritiro per motivi familiari', 'sotto osservazione per un problema al ginocchio', 'via libera dello staff medico per la ripresa',
        'nuovo like sospetto sui social, mercato in fermento', 'incontro con l\'agente per discutere il futuro', 'primo gol in campionato dopo il rientro',
        'panchina per squalifica, spazio al vice',
    ];

    $items = collect(range(0, 24))->map(fn (int $i) => [
        'title' => "{$soggetti[$i]}, {$eventi[$i]}",
        'url' => "https://www.fantamaster.it/notizia-{$i}",
        'pubDate' => now()->toRfc2822String(),
    ])->all();

    Http::fake([$target->rss_url => Http::response(rssFeed($items))]);

    $result = app(ScrapeRunner::class)->runScheduled($target, (string) Str::uuid());

    expect($result->created)->toBe(25);
    expect(Source::query()->count())->toBe(25);

    Bus::assertDispatchedTimes(ProcessSource::class, 20);

    expect(Source::query()->whereNotNull('queue_note')->count())->toBe(5)
        ->and(Source::query()->whereNull('queue_note')->count())->toBe(20)
        ->and(Source::query()->whereNotNull('queue_note')->first()->status)->toBe(SourceStatus::Queued);
});

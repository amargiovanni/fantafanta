<?php

use App\Jobs\ProcessSource;
use App\Jobs\ScrapeTargetNow;
use App\Livewire\Knowledge\Targets;
use App\Models\ScrapeTarget;
use App\Models\Source;
use App\Scraping\ScrapeRunner;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();
    Sleep::fake();
});

it('aggiunge una nuova testata', function () {
    Livewire::test(Targets::class)
        ->set('name', 'Nuova Testata')
        ->set('url', 'https://www.nuova-testata.it')
        ->set('rssUrl', 'https://www.nuova-testata.it/feed/')
        ->call('save')
        ->assertHasNoErrors();

    $target = ScrapeTarget::query()->sole();

    expect($target->name)->toBe('Nuova Testata')
        ->and($target->rss_url)->toBe('https://www.nuova-testata.it/feed/')
        ->and($target->enabled)->toBeTrue();
});

it('modifica una testata esistente', function () {
    $target = ScrapeTarget::factory()->create(['name' => 'Vecchio nome']);

    Livewire::test(Targets::class)
        ->call('edit', $target->id)
        ->set('name', 'Nome aggiornato')
        ->call('save')
        ->assertHasNoErrors();

    expect($target->fresh()->name)->toBe('Nome aggiornato');
});

it('elimina una testata', function () {
    $target = ScrapeTarget::factory()->create();

    Livewire::test(Targets::class)->call('delete', $target->id);

    expect(ScrapeTarget::query()->count())->toBe(0);
});

it('avvia uno scrape on demand per una singola testata', function () {
    Bus::fake([ScrapeTargetNow::class]);
    $target = ScrapeTarget::factory()->create();

    Livewire::test(Targets::class)->call('scrapeNow', $target->id);

    Bus::assertDispatched(ScrapeTargetNow::class, fn (ScrapeTargetNow $job) => $job->scrapeTargetId === $target->id);
});

// Spec Fase 4, test 7: full scrape con batch, avanzamento, cancellazione, e
// una testata rotta non ferma le altre.
it('avvia un full scrape come batch con un job per ciascuna testata abilitata', function () {
    Bus::fake();

    ScrapeTarget::factory()->count(3)->create(['enabled' => true]);
    ScrapeTarget::factory()->create(['enabled' => false]);

    Livewire::test(Targets::class)->call('fullScrape');

    Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 3);
});

it('mostra l\'avanzamento del batch e permette di annullarlo', function () {
    // La coda di test è `sync` (phpunit.xml): un batch non fake esegue i job
    // per davvero, nella stessa richiesta — un fake HTTP permissivo evita che
    // tocchino la rete, dato che questo test non si cura del loro contenuto.
    // Con la coda sincrona il batch risulta già concluso non appena
    // `fullScrape()` ritorna: è comunque la prova che la UI legge il
    // progresso reale del batch Horizon, non un suo stato inventato.
    Http::fake(['*' => Http::response("User-agent: *\nDisallow:", 200)]);

    ScrapeTarget::factory()->count(2)->create(['enabled' => true]);

    $component = Livewire::test(Targets::class)->call('fullScrape');

    $component->assertViewHas('batch', fn (?array $batch) => $batch !== null && $batch['total'] === 2 && $batch['processed'] === 2);

    $activeBatchId = $component->get('activeBatchId');
    expect($activeBatchId)->not->toBeNull();

    $component->call('cancelFullScrape');

    expect(Bus::findBatch($activeBatchId)->cancelled())->toBeTrue();
});

it('riprende a mostrare l\'avanzamento di un batch attivo dopo un refresh della pagina', function () {
    // Un batch reale (non fake) le cui closure non toccano rete: basta a
    // provare che `mount()` ritrova l'id dalla cache, indipendentemente dai
    // tempi di esecuzione dei job veri del full scrape.
    $batch = Bus::batch([function () {}])->dispatch();
    Cache::put('scraping:full-scrape:active-batch', $batch->id, now()->addHours(6));

    $fresh = Livewire::test(Targets::class);

    expect($fresh->get('activeBatchId'))->toBe($batch->id);
});

it('il fallimento di una testata nel full scrape non ferma le altre', function () {
    $rotta = ScrapeTarget::factory()->create(['url' => 'https://www.rotta.it', 'rss_url' => 'https://www.rotta.it/feed/']);
    $sana = ScrapeTarget::factory()->create(['url' => 'https://www.sana.it', 'rss_url' => 'https://www.sana.it/feed/']);

    $pubDate = now()->toRfc2822String();

    Http::fake([
        'https://www.rotta.it/*' => Http::response('errore', 500),
        'https://www.sana.it/feed/' => Http::response(<<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel>
                <item><title>Notizia dalla testata sana</title><link>https://www.sana.it/notizia</link><pubDate>{$pubDate}</pubDate></item>
            </channel></rss>
            XML),
        '*' => Http::response('', 500),
    ]);

    Queue::fake([ProcessSource::class]);

    // Stesso pattern del batch: un giro per testata, il fallimento di una non
    // deve impedire all'altra di produrre le sue source (`ScrapeRunner` non
    // propaga mai un'eccezione di rete al chiamante — la cattura e restituisce
    // un risultato "0 trovati").
    $runner = app(ScrapeRunner::class);
    $runId = (string) Str::uuid();
    $runner->runFull($rotta, $runId);
    $runner->runFull($sana, $runId);

    expect(Source::query()->where('scrape_target_id', $sana->id)->exists())->toBeTrue()
        ->and(Source::query()->where('scrape_target_id', $rotta->id)->exists())->toBeFalse();
});

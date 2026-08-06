<?php

use App\Enums\SourceStatus;
use App\Enums\SourceType;
use App\Jobs\FinalizeSourceProcessing;
use App\Jobs\ProcessSource;
use App\Jobs\RunClaudeTask;
use App\Models\Signal;
use App\Models\Source;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Bus::fake([RunClaudeTask::class, FinalizeSourceProcessing::class]);
});

function processa(Source $source): Source
{
    app()->call([new ProcessSource($source->id), 'handle']);

    return $source->fresh();
}

it('tiene il testo di una nota e avvia la pipeline di estrazione', function () {
    $source = Source::factory()->create([
        'type' => SourceType::Note,
        'title' => 'Sentito in radio',
        'raw_content' => 'Lautaro Martinez ha saltato l\'allenamento per un fastidio al flessore.',
        'content_hash' => null,
        'status' => SourceStatus::Queued,
    ]);

    $source = processa($source);

    expect($source->raw_content)->toContain('flessore')
        ->and($source->content_hash)->toHaveLength(64)
        ->and($source->status)->toBe(SourceStatus::Processing);

    Bus::assertChained([RunClaudeTask::class, FinalizeSourceProcessing::class]);
});

it('estrae il testo da un PDF testuale e lo manda in pipeline', function () {
    Storage::fake('local');

    $stored = 'sources/report-medico.pdf';
    Storage::disk('local')->put($stored, file_get_contents(base_path('tests/Fixtures/report-medico.pdf')));

    $source = Source::factory()->create([
        'type' => SourceType::Pdf,
        'title' => 'Report medico',
        'file_path' => $stored,
        'raw_content' => null,
        'content_hash' => null,
    ]);

    $source = processa($source);

    expect($source->raw_content)->toContain('LAUTARO Martinez')
        ->and($source->raw_content)->toContain('lesione al flessore')
        ->and($source->status)->toBe(SourceStatus::Processing);

    Bus::assertChained([RunClaudeTask::class, FinalizeSourceProcessing::class]);
});

it('estrae il testo di un articolo da una pagina web scartando il contorno', function () {
    Http::fake(['*' => Http::response(<<<'HTML'
        <html>
          <head><title>Lautaro out tre settimane | FantaMaster</title></head>
          <body>
            <nav><a href="/">Home</a><a href="/serie-a">Serie A</a></nav>
            <script>var pubblicita = 1;</script>
            <article>
              <h1>Lautaro out tre settimane</h1>
              <p>L'attaccante dell'Inter ha riportato una lesione al flessore destro.</p>
              <p>Il rientro e' previsto per la terza giornata di campionato.</p>
            </article>
            <footer>Iscriviti alla newsletter per non perdere nessun aggiornamento</footer>
          </body>
        </html>
        HTML)]);

    $source = Source::factory()->create([
        'type' => SourceType::Link,
        'title' => 'https://www.fantamaster.it/lautaro',
        'url' => 'https://www.fantamaster.it/lautaro',
        'raw_content' => null,
        'content_hash' => null,
    ]);

    $source = processa($source);

    expect($source->raw_content)
        ->toContain('lesione al flessore destro')
        ->toContain('terza giornata')
        ->not->toContain('pubblicita')
        ->not->toContain('newsletter')
        ->and($source->title)->toBe('Lautaro out tre settimane | FantaMaster');
});

it('scarta come duplicata una fonte con lo stesso contenuto di una già presente', function () {
    $testo = 'Lautaro Martinez salta le prime tre giornate per infortunio.';

    Source::factory()->create([
        'title' => 'Prima fonte',
        'raw_content' => $testo,
        'content_hash' => Source::hashContent($testo),
        'status' => SourceStatus::Processed,
    ]);

    $seconda = Source::factory()->create([
        'type' => SourceType::Note,
        'title' => 'Stessa notizia da altra parte',
        'raw_content' => $testo,
        'content_hash' => null,
    ]);

    $seconda = processa($seconda);

    expect($seconda->status)->toBe(SourceStatus::Duplicate)
        ->and($seconda->error)->toContain('Contenuto identico alla fonte #1');

    // Nessuna esecuzione di Claude sprecata su un doppione.
    Bus::assertNothingDispatched();
});

it('mette la fonte in errore invece di mandare testo vuoto all\'AI', function (array $attributi, string $errore) {
    $source = processa(Source::factory()->create([...$attributi, 'content_hash' => null]));

    expect($source->status)->toBe(SourceStatus::Failed)
        ->and($source->error)->toContain($errore);

    Bus::assertNothingDispatched();
})->with([
    'nota vuota' => [
        ['type' => SourceType::Note, 'raw_content' => '   '],
        'Nessun testo estratto',
    ],
    'pdf senza file' => [
        ['type' => SourceType::Pdf, 'file_path' => null, 'raw_content' => null],
        'senza file allegato',
    ],
    'link senza url' => [
        ['type' => SourceType::Link, 'url' => null, 'raw_content' => null],
        'senza URL',
    ],
]);

it('mette la fonte in errore se la pagina non risponde', function () {
    Http::fake(['*' => Http::response('Non trovato', 404)]);

    $source = processa(Source::factory()->create([
        'type' => SourceType::Link,
        'url' => 'https://www.esempio.it/pagina-sparita',
        'raw_content' => null,
        'content_hash' => null,
    ]));

    expect($source->status)->toBe(SourceStatus::Failed)
        ->and($source->error)->toContain('ha risposto 404');
});

it('chiude la fonte come processata quando nessun segnale richiede revisione', function () {
    $source = Source::factory()->create(['status' => SourceStatus::Processing]);
    Signal::factory()->create(['source_id' => $source->id, 'needs_review' => false]);

    (new FinalizeSourceProcessing($source->id))->handle();

    expect($source->fresh())
        ->status->toBe(SourceStatus::Processed)
        ->processed_at->not->toBeNull();
});

it('chiude la fonte in revisione se ha prodotto segnali con nomi non risolti', function () {
    $source = Source::factory()->create(['status' => SourceStatus::Processing]);
    Signal::factory()->needsReview('il Toro')->create(['source_id' => $source->id]);

    (new FinalizeSourceProcessing($source->id))->handle();

    expect($source->fresh()->status)->toBe(SourceStatus::NeedsReview);
});

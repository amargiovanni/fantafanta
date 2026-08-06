<?php

use App\Enums\SourceStatus;
use App\Enums\SourceType;
use App\Jobs\ProcessSource;
use App\Livewire\Knowledge\Index;
use App\Models\Signal;
use App\Models\Source;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => Queue::fake());

it('trasforma un link incollato in una fonte di tipo link e la mette in coda', function () {
    Livewire::test(Index::class)
        ->set('content', 'https://www.fantamaster.it/lautaro-infortunio')
        ->call('submit')
        ->assertHasNoErrors();

    $source = Source::query()->sole();

    expect($source->type)->toBe(SourceType::Link)
        ->and($source->url)->toBe('https://www.fantamaster.it/lautaro-infortunio')
        ->and($source->status)->toBe(SourceStatus::Queued);

    Queue::assertPushed(ProcessSource::class);
});

it('trasforma del testo incollato in una nota', function () {
    Livewire::test(Index::class)
        ->set('content', 'Sentito a radio: Lautaro out tre settimane per il flessore.')
        ->call('submit')
        ->assertHasNoErrors();

    $source = Source::query()->sole();

    expect($source->type)->toBe(SourceType::Note)
        ->and($source->raw_content)->toContain('Lautaro out tre settimane')
        ->and($source->title)->toContain('Sentito a radio');

    Queue::assertPushed(ProcessSource::class);
});

it('accetta un PDF caricato e lo conserva su disco', function () {
    Storage::fake('local');

    $pdf = UploadedFile::fake()->createWithContent(
        'report-medico.pdf',
        file_get_contents(base_path('tests/Fixtures/report-medico.pdf')),
    );

    Livewire::test(Index::class)
        ->set('file', $pdf)
        ->call('submit')
        ->assertHasNoErrors();

    $source = Source::query()->sole();

    expect($source->type)->toBe(SourceType::Pdf)
        ->and($source->title)->toBe('report-medico.pdf')
        ->and($source->file_path)->not->toBeNull();

    Storage::disk('local')->assertExists($source->file_path);
    Queue::assertPushed(ProcessSource::class);
});

it('non crea niente se non è stato dato nulla', function () {
    Livewire::test(Index::class)
        ->call('submit')
        ->assertHasErrors('content');

    expect(Source::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rifiuta un formato di file non gestito', function () {
    Livewire::test(Index::class)
        ->set('file', UploadedFile::fake()->create('foto.jpg', 100))
        ->call('submit')
        ->assertHasErrors('file');

    expect(Source::query()->count())->toBe(0);
});

it('mostra i segnali estratti espandendo la fonte', function () {
    $source = Source::factory()->processed()->create(['title' => 'Gazzetta — Lautaro ko']);
    Signal::factory()->create(['source_id' => $source->id, 'impact' => -2]);

    Livewire::test(Index::class)
        ->assertSee('Gazzetta — Lautaro ko')
        ->call('toggle', $source->id)
        ->assertSee('Infortunio')
        ->assertSee('impatto -2');
});

it('rimette in coda una fonte fallita azzerandone l\'hash', function () {
    $source = Source::factory()->create([
        'status' => SourceStatus::Failed,
        'error' => 'La pagina ha risposto 404',
    ]);

    Livewire::test(Index::class)->call('retry', $source->id);

    expect($source->fresh())
        ->status->toBe(SourceStatus::Queued)
        ->error->toBeNull()
        ->content_hash->toBeNull();

    Queue::assertPushed(ProcessSource::class);
});

it('elimina una fonte insieme ai suoi segnali', function () {
    $source = Source::factory()->create();
    Signal::factory()->create(['source_id' => $source->id]);

    Livewire::test(Index::class)->call('delete', $source->id);

    expect(Source::query()->count())->toBe(0)
        ->and(Signal::query()->count())->toBe(0);
});

it('conta le fonti per stato della pipeline', function () {
    Source::factory()->create(['status' => SourceStatus::Queued]);
    Source::factory()->create(['status' => SourceStatus::Processed]);
    Source::factory()->create(['status' => SourceStatus::NeedsReview]);
    Source::factory()->create(['status' => SourceStatus::Failed]);

    Livewire::test(Index::class)
        ->assertViewHas('counters', fn (array $c) => $c === [
            'in_coda' => 1, 'processate' => 1, 'da_rivedere' => 1, 'in_errore' => 1,
        ]);
});

it('il polling non ridisegna la lista se non è cambiato niente', function () {
    Source::factory()->create();

    $componente = Livewire::test(Index::class);
    $impronta = $componente->get('stateHash');

    $componente->call('syncState')->assertSet('stateHash', $impronta);

    // Una nuova fonte cambia lo stato: al giro dopo l'impronta è diversa e
    // il componente si ridisegna.
    Source::factory()->create();

    $componente->call('syncState');

    expect($componente->get('stateHash'))->not->toBe($impronta);
});

<?php

use App\Enums\AiRunStatus;
use App\Enums\SignalType;
use App\Enums\SourceStatus;
use App\Jobs\FinalizeSourceProcessing;
use App\Jobs\ProcessSource;
use App\Jobs\RunClaudeTask;
use App\Livewire\Knowledge\Index as KnowledgeIndex;
use App\Livewire\Knowledge\Review;
use App\Mcp\Servers\FantaAstaServer;
use App\Mcp\Tools\GetSignalsTool;
use App\Mcp\Tools\ResolvePlayerNameTool;
use App\Mcp\Tools\SaveSignalsTool;
use App\Mcp\Tools\SearchPlayerTool;
use App\Models\AiRun;
use App\Models\Player;
use App\Models\PlayerAlias;
use App\Models\Signal;
use App\Models\Source;
use App\Services\ListoneImporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
use Livewire\Livewire;

/**
 * Acceptance della Fase 1 (briefing §9).
 *
 * Il lato Claude è simulato chiamando direttamente i tool MCP: sono
 * esattamente le stesse chiamate che il processo `claude -p` esegue sul
 * server, quindi ciò che qui passa è ciò che in produzione avviene davvero.
 * Il collaudo con Claude vero è un'esecuzione a parte, fuori dalla suite.
 */
beforeEach(function () {
    // Listone reale del fixture: nomi veri, non inventati dai test.
    $csv = file_get_contents(base_path('tests/Fixtures/listone-sample.csv'));

    app(ListoneImporter::class)->import($csv, [
        'name' => 'Nome',
        'role' => 'R',
        'real_team' => 'Squadra',
        'quotazione' => 'Qt.A',
        'fvm' => 'FVM',
        'stats' => ['Pv', 'Mv', 'Fm', 'Gf', 'Ass'],
    ]);
});

/**
 * Simula il turno di Claude: risolve il nome con il tool MCP e, a seconda
 * dell'esito, scrive il segnale agganciato o in revisione. È esattamente la
 * procedura descritta in `resources/prompts/extract-signals.md`.
 *
 * @param  array<string, mixed>  $signal
 * @return array{status: string, player_id: int|null}
 */
function claudeEstrae(int $sourceId, string $rawName, array $signal): array
{
    $esito = null;

    FantaAstaServer::tool(ResolvePlayerNameTool::class, ['name' => $rawName])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$esito) {
            $esito = $json->toArray();
            $json->etc();
        });

    $risolto = $esito['status'] === 'matched';
    $playerId = $risolto ? (int) $esito['player']['id'] : null;

    $payload = $risolto
        ? [...$signal, 'player_id' => $playerId, 'source_id' => $sourceId]
        : [...$signal, 'source_id' => $sourceId, 'needs_review' => true, 'raw_name' => $rawName];

    FantaAstaServer::tool(SaveSignalsTool::class, ['signals' => [$payload]])->assertOk();

    return ['status' => $esito['status'], 'player_id' => $playerId];
}

it('da un articolo con un infortunio noto produce il segnale sul giocatore giusto, con la fonte linkata', function () {
    Queue::fake();

    // 1. L'articolo entra dalla drop zone come qualunque altra cosa.
    Livewire::test(KnowledgeIndex::class)
        ->set('title', 'Inter, lesione al flessore per Lautaro Martinez')
        ->set('content', 'https://www.fantamaster.it/inter-lautaro-lesione-flessore')
        ->call('submit')
        ->assertHasNoErrors();

    $source = Source::query()->sole();
    Queue::assertPushed(ProcessSource::class);

    // 2. Testo estratto dalla pipeline (qui iniettato: l'estrazione ha test suoi).
    $source->update([
        'raw_content' => 'Brutte notizie per l\'Inter: Lautaro Martinez ha riportato una lesione al flessore '
            .'della coscia destra e salterà le prime tre giornate di campionato.',
        'content_hash' => Source::hashContent('articolo-lautaro'),
        'status' => SourceStatus::Processing,
    ]);

    // 3. Il turno di Claude, tramite i tool MCP.
    claudeEstrae($source->id, 'Lautaro Martinez', [
        'type' => 'infortunio',
        'confidence' => 0.9,
        'impact' => -2,
        'event_date' => '2026-08-06',
        'payload' => ['stop_stimato_giornate' => 3, 'parte_lesa' => 'flessore coscia destra'],
    ]);

    (new FinalizeSourceProcessing($source->id))->handle();

    // 4. Il segnale è sul giocatore giusto, del tipo giusto, con la fonte linkata.
    $signal = Signal::query()->with(['player', 'source'])->sole();

    expect($signal->player->name)->toBe('Martinez Lautaro')
        ->and($signal->player->real_team)->toBe('Inter')
        ->and($signal->type)->toBe(SignalType::Infortunio)
        ->and($signal->impact)->toBe(-2)
        ->and($signal->needs_review)->toBeFalse()
        ->and($signal->source->id)->toBe($source->id)
        ->and($signal->source->url)->toBe('https://www.fantamaster.it/inter-lautaro-lesione-flessore')
        ->and($signal->payload['stop_stimato_giornate'])->toBe(3);

    expect($source->fresh()->status)->toBe(SourceStatus::Processed);
});

it('un nome non risolvibile finisce in revisione, mai come segnale orfano silenzioso', function () {
    $source = Source::factory()->create(['title' => 'Voci di mercato']);

    claudeEstrae($source->id, 'Giovanni Inventato', [
        'type' => 'mercato_in',
        'confidence' => 0.5,
        'impact' => 1,
    ]);

    (new FinalizeSourceProcessing($source->id))->handle();

    $signal = Signal::query()->sole();

    // Il segnale esiste ed è visibile, ma non è agganciato a nessuno.
    expect($signal->player_id)->toBeNull()
        ->and($signal->needs_review)->toBeTrue()
        ->and($signal->raw_name)->toBe('Giovanni Inventato');

    // La fonte lo dichiara: non si chiude come "processata e a posto".
    expect($source->fresh()->status)->toBe(SourceStatus::NeedsReview);

    // E compare nella coda di revisione del backoffice.
    Livewire::test(Review::class)->assertSee('Giovanni Inventato');
});

it('non attribuisce a caso un cognome condiviso da due giocatori', function () {
    // In listone ci sono THURAM K. (Juventus) e THURAM Marcus (Inter).
    $source = Source::factory()->create();

    claudeEstrae($source->id, 'Thuram', [
        'type' => 'forma',
        'confidence' => 0.6,
        'impact' => 1,
    ]);

    $signal = Signal::query()->sole();

    expect($signal->player_id)->toBeNull()
        ->and($signal->needs_review)->toBeTrue()
        ->and($signal->raw_name)->toBe('Thuram');
});

it('il rientro supera l\'infortunio precedente', function () {
    $lautaro = Player::query()->where('name', 'Martinez Lautaro')->sole();

    $primaFonte = Source::factory()->create(['title' => 'Lautaro ko']);
    $secondaFonte = Source::factory()->create(['title' => 'Lautaro recuperato']);

    FantaAstaServer::tool(SaveSignalsTool::class, ['signals' => [[
        'player_id' => $lautaro->id,
        'source_id' => $primaFonte->id,
        'type' => 'infortunio',
        'confidence' => 0.9,
        'impact' => -2,
        'event_date' => '2026-08-01',
    ]]])->assertOk();

    $infortunio = Signal::query()->sole();

    // Claude rilegge lo stato prima di scrivere, come chiede il prompt.
    FantaAstaServer::tool(GetSignalsTool::class, ['player_id' => $lautaro->id])
        ->assertOk()
        ->assertSee('infortunio');

    FantaAstaServer::tool(SaveSignalsTool::class, ['signals' => [[
        'player_id' => $lautaro->id,
        'source_id' => $secondaFonte->id,
        'type' => 'rientro',
        'confidence' => 0.85,
        'impact' => 1,
        'event_date' => '2026-08-20',
        'supersedes' => [$infortunio->id],
    ]]])->assertOk();

    $rientro = Signal::query()->where('type', 'rientro')->sole();

    expect($infortunio->fresh()->superseded_by)->toBe($rientro->id)
        ->and($infortunio->fresh()->isActive())->toBeFalse()
        ->and(Signal::query()->active()->pluck('id')->all())->toBe([$rientro->id]);
});

it('marca comunque il superamento anche se Claude si dimentica di dichiararlo', function () {
    $lautaro = Player::query()->where('name', 'Martinez Lautaro')->sole();
    $source = Source::factory()->create();

    FantaAstaServer::tool(SaveSignalsTool::class, ['signals' => [[
        'player_id' => $lautaro->id, 'source_id' => $source->id,
        'type' => 'infortunio', 'confidence' => 0.9, 'impact' => -2,
    ]]])->assertOk();

    $altraFonte = Source::factory()->create();

    // Nessun `supersedes`: la rete di sicurezza deterministica lo copre.
    FantaAstaServer::tool(SaveSignalsTool::class, ['signals' => [[
        'player_id' => $lautaro->id, 'source_id' => $altraFonte->id,
        'type' => 'rientro', 'confidence' => 0.9, 'impact' => 1,
    ]]])->assertOk();

    expect(Signal::query()->active()->pluck('type')->all())->toBe([SignalType::Rientro]);
});

it('traccia in ai_runs ogni esecuzione di Claude', function () {
    Process::preventStrayProcesses();
    Process::fake(['*claude*' => Process::result(output: json_encode([
        'type' => 'result',
        'is_error' => false,
        'result' => '{"source_id":1,"signals_written":1,"needs_review":0}',
    ]))]);

    $source = Source::factory()->create(['raw_content' => 'Lautaro out tre giornate.']);

    (new RunClaudeTask('extract-signals', 'extract-signals.md', ['source_id' => $source->id], [
        'today' => '2026-08-06',
        'source_id' => $source->id,
        'source_type' => 'Nota',
        'source_title' => $source->title,
        'source_url' => '—',
        'source_content' => $source->raw_content,
    ]))->handle();

    $run = AiRun::query()->sole();

    expect($run->task)->toBe('extract-signals')
        ->and($run->prompt_file)->toBe('extract-signals.md')
        ->and($run->prompt_hash)->toHaveLength(64)
        ->and($run->status)->toBe(AiRunStatus::Succeeded)
        ->and($run->context)->toBe(['source_id' => $source->id])
        ->and($run->output_raw)->toContain('signals_written')
        ->and($run->duration_ms)->not->toBeNull();
});

it('da un PDF testuale caricato estrae il testo e arriva ai segnali', function () {
    Storage::fake('local');
    Bus::fake([RunClaudeTask::class, FinalizeSourceProcessing::class]);

    // 1. Caricamento dalla drop zone.
    Livewire::test(KnowledgeIndex::class)
        ->set('file', UploadedFile::fake()->createWithContent(
            'report-medico.pdf',
            file_get_contents(base_path('tests/Fixtures/report-medico.pdf')),
        ))
        ->call('submit')
        ->assertHasNoErrors();

    $source = Source::query()->sole();

    // 2. Estrazione reale del testo dal PDF.
    app()->call([new ProcessSource($source->id), 'handle']);
    $source->refresh();

    expect($source->raw_content)->toContain('LAUTARO Martinez')
        ->and($source->raw_content)->toContain('lesione al flessore');

    // 3. La pipeline di estrazione segnali è stata messa in coda col testo giusto.
    Bus::assertChained([RunClaudeTask::class, FinalizeSourceProcessing::class]);

    // 4. Il turno di Claude sul testo estratto.
    claudeEstrae($source->id, 'LAUTARO Martinez', [
        'type' => 'infortunio',
        'confidence' => 0.95,
        'impact' => -2,
        'payload' => ['stop_stimato_giorni' => 21],
    ]);

    $signal = Signal::query()->with('player')->sole();

    expect($signal->player->name)->toBe('Martinez Lautaro')
        ->and($signal->type)->toBe(SignalType::Infortunio)
        ->and($signal->source_id)->toBe($source->id);
});

it('assegnando a mano un nome non risolto insegna l\'alias per la volta dopo', function () {
    $lautaro = Player::query()->where('name', 'Martinez Lautaro')->sole();
    $source = Source::factory()->create();

    // Prima volta: "il Toro" non lo conosce nessuno.
    claudeEstrae($source->id, 'il Toro', ['type' => 'forma', 'confidence' => 0.6, 'impact' => 1]);

    $signal = Signal::query()->sole();
    expect($signal->needs_review)->toBeTrue();

    // Andrea lo assegna dal backoffice.
    Livewire::test(Review::class)->call('assign', $signal->id, $lautaro->id);

    expect($signal->fresh()->player_id)->toBe($lautaro->id)
        ->and(PlayerAlias::query()->where('player_id', $lautaro->id)->pluck('alias')->all())
        ->toContain('il Toro');

    // Seconda volta: lo stesso nome si risolve da solo.
    FantaAstaServer::tool(SearchPlayerTool::class, ['name' => 'il Toro'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('candidates.0.player_id', $lautaro->id)
            ->where('candidates.0.similarity', fn ($s) => (float) $s === 1.0)
            ->etc()
        );
});

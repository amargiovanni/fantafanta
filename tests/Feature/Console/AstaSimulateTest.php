<?php

use App\Enums\AuctionStatus;
use App\Enums\PlayerRole;
use App\Models\Acquisition;
use App\Models\AiRun;
use App\Models\Auction;
use App\Models\Team;
use App\Services\PlanWriter;
use App\Services\ValuationEngine;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

/**
 * Il simulatore è il collaudo dell'asta senza aspettarne una vera (spec Fase
 * 5, §5): deve passare dal funnel vero (`Acquisition::create`), e non deve MAI
 * poter chiamare `claude` per davvero a meno che non venga passato `--replan`
 * esplicito. Quest'ultimo vincolo è quello che questi test proteggono con più
 * attenzione: un test che lo violasse per sbaglio spenderebbe sottoscrizione
 * reale ad ogni esecuzione della suite.
 */
function outputClaudeFinto(): string
{
    return json_encode([
        'type' => 'result',
        'subtype' => 'success',
        'is_error' => false,
        'duration_ms' => 1200,
        'num_turns' => 3,
        'result' => 'ok',
    ]);
}

function preparaAstaConPiano(): Auction
{
    $auction = Auction::factory()->live()->create();
    $listone = listonePerPiano();

    app(ValuationEngine::class)->recompute($auction);
    app(PlanWriter::class)->save($auction, pianoValido($listone, prezzo: 15), 'Piano di collaudo.');

    return $auction;
}

beforeEach(function () {
    configuraLega();
    registraSquadre();
});

it('rifiuta di simulare senza squadre registrate', function () {
    Team::query()->delete();

    $this->artisan('asta:simulate', ['--events' => 3])->assertExitCode(1);

    expect(Acquisition::query()->count())->toBe(0);
});

it('rifiuta di simulare con il listone vuoto', function () {
    $this->artisan('asta:simulate', ['--events' => 3])->assertExitCode(1);

    expect(Acquisition::query()->count())->toBe(0);
});

it('apre e avvia una sessione d\'asta se non ce n\'è nessuna', function () {
    Queue::fake();
    giocatore(PlayerRole::Attaccante);

    expect(Auction::query()->count())->toBe(0);

    $this->artisan('asta:simulate', ['--events' => 1])->assertExitCode(0);

    $auction = Auction::query()->sole();
    expect($auction->status)->toBe(AuctionStatus::Live);
});

it('registra gli acquisti sul funnel vero: Acquisition::create, non una sua imitazione', function () {
    Queue::fake();

    $auction = preparaAstaConPiano();

    $this->artisan('asta:simulate', ['--events' => 5])->assertExitCode(0);

    $acquisti = Acquisition::query()->with('player')->where('auction_id', $auction->id)->get();

    expect($acquisti)->toHaveCount(5)
        // Ogni acquisto ha fatto scattare l'observer: il giocatore non è più
        // "available" (altrimenti la promozione/il ricalcolo non sarebbero
        // mai partiti, il segno che il funnel non è stato percorso davvero).
        ->and($acquisti->every(fn (Acquisition $a) => $a->player->status->value === 'acquired'))->toBeTrue();
});

it('rispetta il numero di eventi richiesto da --events', function () {
    Queue::fake();
    preparaAstaConPiano();

    $this->artisan('asta:simulate', ['--events' => 2])->assertExitCode(0);

    expect(Acquisition::query()->count())->toBe(2);
});

it('di default non chiama mai claude reale: nessun ai_run, il flag interno è quello a proteggere', function () {
    preparaAstaConPiano();

    // Niente Queue::fake() qui, di proposito: la garanzia deve venire dal
    // comando stesso (Queue::fake() interno), non dal test.
    Process::preventStrayProcesses();

    $this->artisan('asta:simulate', ['--events' => 10])
        ->expectsOutputToContain('Modalità finta')
        ->assertExitCode(0);

    expect(AiRun::query()->count())->toBe(0)
        ->and(Acquisition::query()->count())->toBe(10);
});

it('con --replan lascia passare i dispatch reali (claude resta finto solo via Process::fake)', function () {
    Process::preventStrayProcesses();
    Process::fake(['*claude*' => Process::result(output: outputClaudeFinto())]);

    preparaAstaConPiano();

    $this->artisan('asta:simulate', ['--events' => 1, '--replan' => true])
        ->expectsOutputToContain('--replan attivo')
        ->assertExitCode(0);

    // Coda `sync` in test: l'intera catena (observer -> Replanner::schedule
    // -> ScheduleReplan -> Replanner::launch -> RunClaudeTask) gira nello
    // stesso processo, subito. Un ai_run scritto è la prova che il dispatch
    // non era finto — claude stesso resta finto solo grazie a Process::fake,
    // mai per davvero.
    expect(AiRun::query()->count())->toBeGreaterThan(0);
});

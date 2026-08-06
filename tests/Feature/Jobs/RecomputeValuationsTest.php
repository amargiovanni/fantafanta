<?php

use App\Enums\PlayerRole;
use App\Enums\PlayerStatus;
use App\Enums\SignalType;
use App\Jobs\RecomputeValuations;
use App\Models\Acquisition;
use App\Models\Auction;
use App\Models\LeagueConfig;
use App\Models\Signal;
use App\Models\Team;
use App\Models\Valuation;
use App\Services\ValuationEngine;
use Illuminate\Support\Facades\Queue;

/**
 * Il ricalcolo deve partire da solo. Una valutazione ferma a ieri è peggio di
 * una valutazione assente: sembra un dato aggiornato e non lo è.
 */
beforeEach(function () {
    configuraLega();
    registraSquadre();
});

it('parte quando nasce un segnale', function () {
    Queue::fake();

    $player = giocatore(PlayerRole::Attaccante);

    Signal::factory()->create(['player_id' => $player->id, 'type' => SignalType::Infortunio]);

    Queue::assertPushed(RecomputeValuations::class);
});

it('parte quando un segnale viene corretto o superato', function () {
    $signal = Signal::factory()->create(['player_id' => giocatore(PlayerRole::Difensore)->id]);

    Queue::fake();

    $signal->update(['confidence' => 0.4]);
    Queue::assertPushed(RecomputeValuations::class, 1);

    $signal->update(['superseded_by' => Signal::factory()->create(['player_id' => $signal->player_id])->id]);
    Queue::assertPushed(RecomputeValuations::class, 3);
});

it('parte quando un segnale viene cancellato dal backoffice', function () {
    $signal = Signal::factory()->create(['player_id' => giocatore(PlayerRole::Centrocampista)->id]);

    Queue::fake();

    $signal->delete();

    Queue::assertPushed(RecomputeValuations::class);
});

it('parte quando cambia la configurazione della lega', function () {
    Queue::fake();

    LeagueConfig::current()->update(['modifier_defense' => false]);

    Queue::assertPushed(RecomputeValuations::class);
});

it('parte a ogni aggiudicazione e a ogni annullamento, sull\'asta giusta', function () {
    $auction = Auction::factory()->create();
    $player = giocatore(PlayerRole::Attaccante);

    Queue::fake();

    $acquisto = Acquisition::factory()->create([
        'auction_id' => $auction->id,
        'player_id' => $player->id,
        'team_id' => Team::query()->first()->id,
        'price' => 30,
    ]);

    Queue::assertPushed(RecomputeValuations::class, fn (RecomputeValuations $job) => $job->auctionId === $auction->id);

    $acquisto->delete();

    Queue::assertPushed(RecomputeValuations::class, 2);
});

it('un\'aggiudicazione toglie il giocatore dai disponibili e l\'undo ce lo rimette', function () {
    $auction = Auction::factory()->create();
    $player = giocatore(PlayerRole::Attaccante);

    $acquisto = Acquisition::factory()->create([
        'auction_id' => $auction->id,
        'player_id' => $player->id,
        'team_id' => Team::query()->first()->id,
        'price' => 30,
    ]);

    expect($player->fresh()->status)->toBe(PlayerStatus::Acquired);

    $acquisto->delete();

    expect($player->fresh()->status)->toBe(PlayerStatus::Available);
});

it('scatta la valutazione al momento dell\'acquisto, per non riscrivere l\'inflazione a posteriori', function () {
    $auction = Auction::factory()->create();
    $player = giocatore(PlayerRole::Attaccante, quotazione: 30, fvm: 250);

    app(ValuationEngine::class)->recompute($auction);
    $valoreDiAllora = valutazione($player)->adjusted_value;

    $acquisto = Acquisition::factory()->create([
        'auction_id' => $auction->id,
        'player_id' => $player->id,
        'team_id' => Team::query()->first()->id,
        'price' => 55,
    ]);

    expect($acquisto->valuation_at_purchase)->toBe($valoreDiAllora);

    // Un infortunio successivo abbassa il valore corrente ma non lo scatto.
    Signal::factory()->create([
        'player_id' => $player->id,
        'type' => SignalType::Infortunio,
        'payload' => ['stop_stimato_giorni' => 150],
        'confidence' => 0.9,
        'impact' => -2,
    ]);

    app(ValuationEngine::class)->recompute($auction);

    expect($acquisto->fresh()->valuation_at_purchase)->toBe($valoreDiAllora)
        ->and(valutazione($player)->adjusted_value)->toBeLessThan($valoreDiAllora);
});

it('il job scrive davvero le valutazioni quando la coda lo esegue', function () {
    giocatore(PlayerRole::Portiere);
    giocatore(PlayerRole::Attaccante);

    (new RecomputeValuations)->handle(app(ValuationEngine::class));

    expect(Valuation::query()->count())->toBe(2);
});

it('gira sulla coda default, non su quelle dell\'AI', function () {
    expect((new RecomputeValuations)->queue)->toBe('default');
});

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
use Illuminate\Bus\DebounceLock;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Queue\Attributes\DebounceFor;
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
    // Queue::fake() registra ogni dispatch così com'è, delay compreso: il
    // debounce (ADR 0004) scarta i job superati solo all'esecuzione reale
    // in coda (vedi CallQueuedHandler::commandShouldBeDebounced()), non al
    // push. Tre dispatch restano quindi visibili qui; la deduplica vera è
    // coperta più sotto, sull'owner token del marker di debounce.
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

it('debounce: di una raffica sulla stessa asta sopravvive solo l\'ultimo dispatch', function () {
    Queue::fake();

    $auction = Auction::factory()->create();

    RecomputeValuations::dispatch($auction->id);
    RecomputeValuations::dispatch($auction->id);
    RecomputeValuations::dispatch($auction->id);

    $jobs = Queue::pushed(RecomputeValuations::class)->values();
    expect($jobs)->toHaveCount(3);

    $lock = new DebounceLock(app(Cache::class));
    $currentOwner = $lock->getCurrentOwner($jobs->last());

    expect($jobs[0]->debounceOwner)->not->toBe($currentOwner)
        ->and($jobs[1]->debounceOwner)->not->toBe($currentOwner)
        ->and($jobs[2]->debounceOwner)->toBe($currentOwner)
        ->and($jobs[2]->delay)->toBe(5);
});

it('debounce: aste diverse non si cancellano a vicenda', function () {
    Queue::fake();

    $auctionA = Auction::factory()->create();
    $auctionB = Auction::factory()->create();

    RecomputeValuations::dispatch($auctionA->id);
    RecomputeValuations::dispatch($auctionB->id);

    $lock = new DebounceLock(app(Cache::class));

    foreach (Queue::pushed(RecomputeValuations::class) as $job) {
        expect($lock->getCurrentOwner($job))->toBe($job->debounceOwner);
    }
});

it('il debounce è configurabile via config, il tetto d\'attesa no (limite degli attributi PHP)', function () {
    config(['fanta.recompute_valuations.debounce' => 12]);

    expect((new RecomputeValuations)->debounceFor)->toBe(12);

    $attribute = (new ReflectionClass(RecomputeValuations::class))
        ->getAttributes(DebounceFor::class)[0]->newInstance();

    expect($attribute->maxWait)->toBe(30);
});

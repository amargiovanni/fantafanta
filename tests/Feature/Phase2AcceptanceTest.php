<?php

use App\Enums\PlayerRole;
use App\Enums\SignalType;
use App\Mcp\Servers\FantaAstaServer;
use App\Mcp\Tools\GetAvailablePlayersTool;
use App\Mcp\Tools\GetCurrentPlanTool;
use App\Mcp\Tools\SavePlanTool;
use App\Models\Auction;
use App\Models\Plan;
use App\Models\Player;
use App\Models\Signal;
use App\Models\Valuation;
use App\Services\ValuationEngine;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;

/**
 * Gli acceptance criteria della Fase 2 (briefing §9), uno per uno, nella
 * forma in cui il PO li verificherà.
 *
 * Non ripetono i test unitari: percorrono la strada intera, dal segnale
 * scritto dall'AI al piano che ne esce, passando dagli stessi tool MCP che
 * usa il processo `claude` headless.
 */
beforeEach(function () {
    Queue::fake();

    configuraLega();
    $this->squadre = registraSquadre();
    $this->auction = Auction::factory()->create();
    $this->listone = listonePerPiano();

    app(ValuationEngine::class)->recompute($this->auction);
});

it('accetta un piano da 25 slot con ruoli esatti, budget rispettato e almeno 2 alternative', function () {
    FantaAstaServer::tool(SavePlanTool::class, [
        'strategy_notes' => "Difesa concentrata, un top in attacco.\nFascia media affidabile a centrocampo.",
        'slots' => pianoValido($this->listone, prezzo: 15),
    ])->assertOk();

    $piano = $this->auction->latestReadyPlan();
    $slots = $piano->slots;

    expect($slots)->toHaveCount(25)
        ->and($slots->where('role', PlayerRole::Portiere))->toHaveCount(3)
        ->and($slots->where('role', PlayerRole::Difensore))->toHaveCount(8)
        ->and($slots->where('role', PlayerRole::Centrocampista))->toHaveCount(8)
        ->and($slots->where('role', PlayerRole::Attaccante))->toHaveCount(6)
        ->and($slots->sum('target_price'))->toBeLessThanOrEqual(500)
        ->and($slots->every(fn ($slot) => count($slot->alternatives) >= 2))->toBeTrue()
        ->and($slots->pluck('player_id')->unique())->toHaveCount(25);

    // Ogni titolare occupa uno slot del proprio ruolo.
    $ruoli = Player::query()->whereIn('id', $slots->pluck('player_id'))->pluck('role', 'id');

    expect($slots->every(fn ($slot) => $ruoli[$slot->player_id] === $slot->role))->toBeTrue();
});

it('rifiuta un piano invalido con errori chiari e va a buon fine al secondo tentativo, come farebbe un run reale', function () {
    // Primo turno: Claude propone un piano con quattro errori diversi.
    $primoTentativo = pianoValido($this->listone, prezzo: 40);   // 25 × 40 = 1000, budget 500
    $primoTentativo[3]['player_id'] = $primoTentativo[2]['player_id'];  // stesso titolare due volte
    $primoTentativo[11]['alternatives'] = [];                           // slot senza ripieghi
    $primoTentativo[0]['player_id'] = $this->listone['C'][0]->id;       // ruolo sbagliato

    $rifiuto = FantaAstaServer::tool(SavePlanTool::class, [
        'strategy_notes' => 'Primo tentativo.',
        'slots' => $primoTentativo,
    ]);

    $rifiuto->assertHasErrors();
    $rifiuto->assertSee('Budget sforato');
    $rifiuto->assertSee('è titolare di più slot');
    $rifiuto->assertSee('servono almeno 2 alternative');
    $rifiuto->assertSee('non può occupare uno slot P');

    expect(Plan::query()->count())->toBe(0);

    // Secondo turno: corretto tutto quello che l'errore elencava.
    FantaAstaServer::tool(SavePlanTool::class, [
        'strategy_notes' => "Rientrato nel budget con prezzi da 15.\nRuoli e alternative sistemati.",
        'slots' => pianoValido($this->listone, prezzo: 15),
    ])->assertOk();

    expect(Plan::query()->where('status', 'ready')->count())->toBe(1);

    FantaAstaServer::tool(GetCurrentPlanTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('plan.version', 1)
            ->where('plan.slots', fn ($slots) => count($slots) === 25)
            ->etc()
        );
});

it('un infortunio grave abbassa il valore e fa uscire il giocatore dai piani rigenerati', function () {
    $titolare = $this->listone['A'][0];

    $prima = valutazione($titolare);

    expect($prima->tier)->toBe(1);

    // Il giocatore è nel piano corrente come titolare dello slot A#1.
    FantaAstaServer::tool(SavePlanTool::class, [
        'strategy_notes' => 'Attacco costruito sul bomber.',
        'slots' => pianoValido($this->listone, prezzo: 15),
    ])->assertOk();

    // Arriva la notizia: crociato, cinque mesi.
    Signal::factory()->create([
        'player_id' => $titolare->id,
        'type' => SignalType::Infortunio,
        'payload' => ['stop_stimato_giorni' => 150, 'parte_lesa' => 'crociato'],
        'confidence' => 0.9,
        'impact' => -2,
        'event_date' => now()->toDateString(),
    ]);

    app(ValuationEngine::class)->recompute($this->auction);

    $dopo = valutazione($titolare);

    expect($dopo->adjusted_value)->toBeLessThan($prima->adjusted_value * 0.25)
        ->and($dopo->tier)->toBeGreaterThan($prima->tier);

    // Il tool con cui si costruisce il piano nuovo lo declassa: non è più fra
    // i primi del ruolo, e sparisce da una selezione dei tier alti.
    $risposta = FantaAstaServer::tool(GetAvailablePlayersTool::class, ['role' => 'A', 'max_tier' => 2])->assertOk();

    $risposta->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('players', fn ($players) => ! collect($players)->pluck('player_id')->contains($titolare->id))
        ->etc()
    );

    // E quando compare, compare in fondo.
    $ordinati = FantaAstaServer::tool(GetAvailablePlayersTool::class, ['role' => 'A', 'limit' => 3])->assertOk();

    $ordinati->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('players', fn ($players) => ! collect($players)->pluck('player_id')->contains($titolare->id))
        ->etc()
    );
});

it('ricalcola le valutazioni dell\'intero listone in meno di 10 secondi', function () {
    $distribuzione = ['P' => 60, 'D' => 180, 'C' => 200, 'A' => 160];

    foreach ($distribuzione as $ruolo => $quanti) {
        Player::factory()->count($quanti)->create([
            'role' => PlayerRole::from($ruolo),
            'season_stats' => ['Pv' => '28', 'Mv' => '6.2', 'Fm' => '6.6', 'Am' => '3'],
        ]);
    }

    Signal::factory()->count(30)->create([
        'player_id' => fn () => Player::query()->inRandomOrder()->value('id'),
        'type' => SignalType::Forma,
        'impact' => 1,
        'confidence' => 0.7,
    ]);

    $inizio = microtime(true);
    app(ValuationEngine::class)->recompute($this->auction);
    $durata = microtime(true) - $inizio;

    expect(Valuation::query()->count())->toBe(Player::query()->count())
        ->and($durata)->toBeLessThan(10.0);
});

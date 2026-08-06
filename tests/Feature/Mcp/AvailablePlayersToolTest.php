<?php

use App\Enums\PlayerRole;
use App\Enums\SignalType;
use App\Mcp\Servers\FantaAstaServer;
use App\Mcp\Tools\GetAvailablePlayersTool;
use App\Mcp\Tools\GetPlayerTool;
use App\Models\Acquisition;
use App\Models\Auction;
use App\Models\Signal;
use App\Services\ValuationEngine;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;

/**
 * Il tool con cui si sfoglia il listone mentre si costruisce il piano, e la
 * scheda del singolo giocatore. Devono dire la verità sulla valutazione: è
 * l'unico numero su cui si decide.
 */
beforeEach(function () {
    Queue::fake();

    configuraLega();
    $this->squadre = registraSquadre();
    $this->auction = Auction::factory()->live()->create();

    $this->attaccanti = collect(range(1, 12))->map(fn (int $i) => giocatore(
        PlayerRole::Attaccante,
        quotazione: 40 - $i,
        fvm: 300 - $i * 20,
        squadra: $i <= 3 ? 'Inter' : 'Lecce',
    ));

    $this->difensori = collect(range(1, 10))->map(fn (int $i) => giocatore(
        PlayerRole::Difensore,
        quotazione: 20 - $i,
        fvm: 120 - $i * 8,
    ));

    app(ValuationEngine::class)->recompute($this->auction);
});

it('elenca i disponibili con la valutazione, dal più forte', function () {
    FantaAstaServer::tool(GetAvailablePlayersTool::class, ['role' => 'A', 'limit' => 5])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('count', 5)
            ->where('players.0.player_id', $this->attaccanti[0]->id)
            ->where('players.0.valuation.tier', 1)
            ->where('players.0.valuation.adjusted_value', fn ($v) => $v > 0)
            ->where('players.0.valuation.max_bid', fn ($v) => $v > 0)
            ->where('without_valuation', 0)
            ->etc()
        );
});

it('non mostra chi è già stato aggiudicato, se non glielo si chiede', function () {
    $preso = $this->attaccanti[0];

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $preso->id,
        'team_id' => $this->squadre[3]->id,
        'price' => 90,
    ]);

    FantaAstaServer::tool(GetAvailablePlayersTool::class, ['role' => 'A'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('count', 11)
            ->where('players.0.player_id', fn ($id) => $id !== $preso->id)
            ->etc()
        );

    FantaAstaServer::tool(GetAvailablePlayersTool::class, ['role' => 'A', 'include_unavailable' => true])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('count', 12)->etc());
});

it('filtra per tier, per fascia di valore e per squadra reale', function () {
    FantaAstaServer::tool(GetAvailablePlayersTool::class, ['role' => 'A', 'max_tier' => 2])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('players', fn ($players) => collect($players)->every(fn ($p) => $p['valuation']['tier'] <= 2))
            ->etc()
        );

    FantaAstaServer::tool(GetAvailablePlayersTool::class, ['role' => 'A', 'real_team' => 'Inter'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('count', 3)->etc());

    $mediana = valutazione($this->attaccanti[5])->adjusted_value;

    FantaAstaServer::tool(GetAvailablePlayersTool::class, ['role' => 'A', 'max_value' => $mediana])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('players', fn ($players) => collect($players)->every(fn ($p) => $p['valuation']['adjusted_value'] <= $mediana))
            ->etc()
        );
});

it('ordina come richiesto, anche al contrario', function () {
    FantaAstaServer::tool(GetAvailablePlayersTool::class, [
        'role' => 'D',
        'sort' => 'adjusted_value',
        'direction' => 'asc',
        'limit' => 1,
    ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('players.0.player_id', $this->difensori->last()->id)
            ->etc()
        );
});

it('conta i segnali attivi di ciascun giocatore, così si sa dove approfondire', function () {
    Signal::factory()->count(2)->create([
        'player_id' => $this->attaccanti[0]->id,
        'type' => SignalType::Forma,
        'impact' => 1,
        'confidence' => 0.6,
    ]);

    FantaAstaServer::tool(GetAvailablePlayersTool::class, ['role' => 'A', 'limit' => 1])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('players.0.active_signals_count', 2)
            ->etc()
        );
});

it('get_player mostra la valutazione corrente e, se c\'è, a chi è andato', function () {
    $player = $this->attaccanti[0];

    FantaAstaServer::tool(GetPlayerTool::class, ['player_id' => $player->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('valuation.tier', 1)
            ->where('valuation.adjusted_value', valutazione($player)->adjusted_value)
            ->where('acquisition', null)
            ->etc()
        );

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $player->id,
        'team_id' => $this->squadre[4]->id,
        'price' => 88,
    ]);

    FantaAstaServer::tool(GetPlayerTool::class, ['player_id' => $player->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('acquisition.team_name', 'Avversario 5')
            ->where('acquisition.price', 88)
            ->where('acquisition.is_mine', false)
            ->etc()
        );
});

it('dice quanti giocatori non hanno ancora una valutazione', function () {
    giocatore(PlayerRole::Centrocampista);

    FantaAstaServer::tool(GetAvailablePlayersTool::class, ['role' => 'C'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('without_valuation', 1)
            ->where('players.0.valuation', null)
            ->etc()
        );
});

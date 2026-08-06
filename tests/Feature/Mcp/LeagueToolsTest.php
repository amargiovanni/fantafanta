<?php

use App\Enums\PlayerRole;
use App\Mcp\Servers\FantaAstaServer;
use App\Mcp\Tools\GetAuctionLogTool;
use App\Mcp\Tools\GetBudgetAnalysisTool;
use App\Mcp\Tools\GetLeagueStateTool;
use App\Models\Acquisition;
use App\Models\Auction;
use App\Services\ValuationEngine;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;

/**
 * I tool con cui Claude capisce come sta andando l'asta prima di decidere
 * quanto offrire. Sono in sola lettura, ma se raccontano male lo stato il
 * piano che ne esce è sbagliato in silenzio.
 */
beforeEach(function () {
    Queue::fake();

    configuraLega();
    $this->squadre = registraSquadre();
    $this->auction = Auction::factory()->live()->create();

    $this->attaccanti = collect(range(1, 10))->map(fn (int $i) => giocatore(
        PlayerRole::Attaccante,
        quotazione: 40 - $i,
        fvm: 300 - $i * 20,
    ));

    collect(range(1, 6))->each(fn (int $i) => giocatore(PlayerRole::Portiere, quotazione: 5 + $i, fvm: 30 + $i));

    app(ValuationEngine::class)->recompute($this->auction);
});

it('get_league_state racconta crediti e slot aperti di tutti, miei per primi', function () {
    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $this->attaccanti[0]->id,
        'team_id' => $this->squadre[0]->id,
        'price' => 120,
    ]);

    FantaAstaServer::tool(GetLeagueStateTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('league.total_credits', 500)
            ->where('league.teams_count', 8)
            ->where('league.modifier_defense', true)
            ->where('league.credit_pool', 4000)
            ->where('league.total_slots', 25)
            ->where('auction.status', 'live')
            ->where('my_team.credits_spent', 120)
            ->where('my_team.credits_remaining', 380)
            ->where('my_team.open_slots_total', 24)
            ->where('my_team.open_slots_by_role.A', 5)
            ->where('teams', fn ($teams) => count($teams) === 8)
            ->where('listone.acquired', 1)
            ->etc()
        );
});

it('get_league_state funziona anche prima che l\'asta sia aperta', function () {
    Auction::query()->delete();

    FantaAstaServer::tool(GetLeagueStateTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('auction', null)
            ->where('my_team.credits_remaining', 500)
            ->etc()
        );
});

it('get_budget_analysis misura l\'inflazione del reparto solo quando c\'è abbastanza mercato', function () {
    FantaAstaServer::tool(GetBudgetAnalysisTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('roles.A.inflation.acquisitions', 0)
            ->where('roles.A.inflation.effective', fn ($eff) => (float) $eff === 1.0)
            ->where('my_budget.credits_remaining', 500)
            ->where('my_budget.max_single_bid', 476)
            ->etc()
        );

    // Tre attaccanti pagati il 30% sopra il valore: adesso l'indicatore parla.
    foreach ($this->attaccanti->take(3) as $indice => $attaccante) {
        Acquisition::factory()->create([
            'auction_id' => $this->auction->id,
            'player_id' => $attaccante->id,
            'team_id' => $this->squadre[$indice + 1]->id,
            'price' => (int) round(valutazione($attaccante)->adjusted_value * 1.3),
        ]);
    }

    FantaAstaServer::tool(GetBudgetAnalysisTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('roles.A.inflation.acquisitions', 3)
            ->where('roles.A.inflation.raw', fn ($raw) => abs((float) $raw - 1.3) < 0.02)
            ->where('roles.A.inflation.effective', fn ($eff) => abs((float) $eff - 1.21) < 0.02)
            ->where('roles.A.acquired_in_league', 3)
            ->where('roles.A.available_players', 7)
            ->where('roles.P.inflation.effective', fn ($eff) => (float) $eff === 1.0)
            ->etc()
        );
});

it('get_budget_analysis dice quanti giocatori restano per tier e quanto scarseggiano', function () {
    FantaAstaServer::tool(GetBudgetAnalysisTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('roles.A.scarcity_by_tier.1.available_up_to_tier', fn ($n) => $n >= 1)
            ->where('roles.A.scarcity_by_tier.5.available_up_to_tier', 10)
            ->where('roles.A.opponent_open_slots', fn ($n) => $n > 0)
            ->where('opponents', fn ($avversari) => count($avversari) === 7)
            ->etc()
        );
});

it('get_auction_log elenca le aggiudicazioni in ordine con lo scostamento dal valore', function () {
    $primo = $this->attaccanti[0];
    $valore = valutazione($primo)->adjusted_value;

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $primo->id,
        'team_id' => $this->squadre[2]->id,
        'price' => (int) round($valore * 1.5),
    ]);

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $this->attaccanti[1]->id,
        'team_id' => $this->squadre[0]->id,
        'price' => 3,
    ]);

    FantaAstaServer::tool(GetAuctionLogTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('count', 2)
            ->where('acquisitions.0.player_name', $primo->name)
            ->where('acquisitions.0.team_name', 'Avversario 3')
            ->where('acquisitions.0.is_mine', false)
            ->where('acquisitions.0.delta_percent', fn ($delta) => $delta > 45 && $delta < 55)
            ->where('acquisitions.1.is_mine', true)
            ->where('acquisitions.1.delta_percent', fn ($delta) => $delta < 0)
            ->etc()
        );
});

it('get_auction_log non mostra le aggiudicazioni annullate', function () {
    $acquisto = Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $this->attaccanti[0]->id,
        'team_id' => $this->squadre[2]->id,
        'price' => 50,
    ]);

    $acquisto->delete();

    FantaAstaServer::tool(GetAuctionLogTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('count', 0)->etc());
});

it('get_auction_log filtra per ruolo e per squadra', function () {
    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $this->attaccanti[0]->id,
        'team_id' => $this->squadre[2]->id,
        'price' => 50,
    ]);

    FantaAstaServer::tool(GetAuctionLogTool::class, ['role' => 'P'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('count', 0)->etc());

    FantaAstaServer::tool(GetAuctionLogTool::class, ['team_id' => $this->squadre[2]->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('count', 1)->etc());
});

it('get_auction_log spiega che manca la sessione d\'asta invece di rispondere vuoto', function () {
    Auction::query()->delete();

    FantaAstaServer::tool(GetAuctionLogTool::class, [])
        ->assertHasErrors(['Nessuna sessione d\'asta aperta: non c\'è nessun registro da leggere.']);
});

<?php

use App\Enums\SignalType;
use App\Enums\SourceType;
use App\Mcp\Servers\FantaAstaServer;
use App\Mcp\Tools\GetPlayerTool;
use App\Mcp\Tools\GetSignalsTool;
use App\Mcp\Tools\SearchPlayerTool;
use App\Models\Player;
use App\Models\PlayerAlias;
use App\Models\Signal;
use App\Models\Source;

beforeEach(function () {
    $this->lautaro = Player::factory()->create([
        'name' => 'MARTINEZ Lautaro',
        'real_team' => 'Inter',
    ]);
    PlayerAlias::query()->create(['player_id' => $this->lautaro->id, 'alias' => 'Lautaro']);
    PlayerAlias::query()->create(['player_id' => $this->lautaro->id, 'alias' => 'Martinez L.']);
});

it('trova il giocatore da tutte le forme del nome', function (string $query) {
    $response = FantaAstaServer::tool(SearchPlayerTool::class, ['name' => $query])->assertOk();

    $response->assertStructuredContent(fn ($json) => $json
        ->where('count', fn (int $count) => $count >= 1)
        ->where('candidates.0.player_id', $this->lautaro->id)
        ->where('candidates.0.similarity', fn (float $s) => $s >= 0.85)
        ->etc()
    );
})->with(['lautaro', 'Martinez L.', 'martinez lautaro', 'LAUTARO MARTINEZ']);

it('non restituisce candidati per un nome che non esiste', function () {
    FantaAstaServer::tool(SearchPlayerTool::class, ['name' => 'Zzzqqq Inesistente'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('count', 0)->etc());
});

it('rifiuta una ricerca senza nome', function () {
    FantaAstaServer::tool(SearchPlayerTool::class, [])->assertHasErrors();
});

it('restituisce la scheda del giocatore con i segnali attivi e la loro fonte', function () {
    $source = Source::factory()->create([
        'title' => 'Lautaro out due settimane',
        'url' => 'https://www.fantamaster.it/lautaro-out',
        'type' => SourceType::Link,
    ]);

    Signal::factory()->create([
        'player_id' => $this->lautaro->id,
        'source_id' => $source->id,
        'type' => SignalType::Infortunio,
        'impact' => -2,
        'confidence' => 0.9,
    ]);

    FantaAstaServer::tool(GetPlayerTool::class, ['player_id' => $this->lautaro->id])
        ->assertOk()
        ->assertSee('MARTINEZ Lautaro')
        ->assertStructuredContent(fn ($json) => $json
            ->where('player.id', $this->lautaro->id)
            ->where('player.real_team', 'Inter')
            ->where('active_signals_count', 1)
            ->where('active_signals.0.type', 'infortunio')
            ->where('active_signals.0.source.title', 'Lautaro out due settimane')
            ->where('active_signals.0.source.url', 'https://www.fantamaster.it/lautaro-out')
            ->etc()
        );
});

it('esclude dalla scheda i segnali superati', function () {
    $superato = Signal::factory()->create([
        'player_id' => $this->lautaro->id,
        'type' => SignalType::Infortunio,
    ]);
    $attuale = Signal::factory()->create([
        'player_id' => $this->lautaro->id,
        'type' => SignalType::Rientro,
        'impact' => 1,
    ]);
    $superato->update(['superseded_by' => $attuale->id]);

    FantaAstaServer::tool(GetPlayerTool::class, ['player_id' => $this->lautaro->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('active_signals_count', 1)
            ->where('active_signals.0.type', 'rientro')
            ->etc()
        );
});

it('spiega che il giocatore non esiste invece di restituire una scheda vuota', function () {
    FantaAstaServer::tool(GetPlayerTool::class, ['player_id' => 999999])
        ->assertHasErrors(['Nessun giocatore con id 999999 nel listone. Usa search_player per trovare l\'id corretto.']);
});

it('filtra i segnali per giocatore, tipo e revisione', function () {
    $altro = Player::factory()->create(['name' => 'DUMFRIES Denzel']);

    Signal::factory()->create(['player_id' => $this->lautaro->id, 'type' => SignalType::Infortunio]);
    Signal::factory()->create(['player_id' => $altro->id, 'type' => SignalType::Rigorista, 'impact' => 2]);
    Signal::factory()->needsReview('Tizio Ignoto')->create(['type' => SignalType::Ballottaggio]);

    FantaAstaServer::tool(GetSignalsTool::class, ['player_id' => $this->lautaro->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('count', 1)->where('signals.0.type', 'infortunio')->etc());

    FantaAstaServer::tool(GetSignalsTool::class, ['type' => 'rigorista'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('count', 1)->where('signals.0.player_id', $altro->id)->etc());

    FantaAstaServer::tool(GetSignalsTool::class, ['needs_review' => true])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('count', 1)->where('signals.0.raw_name', 'Tizio Ignoto')->etc());
});

it('rifiuta un tipo di segnale fuori enum nel filtro', function () {
    FantaAstaServer::tool(GetSignalsTool::class, ['type' => 'raffreddore'])->assertHasErrors();
});

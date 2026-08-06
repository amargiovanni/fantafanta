<?php

use App\Mcp\Servers\FantaAstaServer;
use App\Mcp\Tools\ResolvePlayerNameTool;
use App\Models\Player;
use App\Models\PlayerAlias;

it('risolve un nome sicuro e registra l\'alias perché non serva più cercarlo', function () {
    $player = Player::factory()->create(['name' => 'MARTINEZ Lautaro', 'real_team' => 'Inter']);
    PlayerAlias::query()->create(['player_id' => $player->id, 'alias' => 'Lautaro']);

    FantaAstaServer::tool(ResolvePlayerNameTool::class, ['name' => 'Lautaro Martinez'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'matched')
            ->where('player.id', $player->id)
            ->where('alias_created', true)
            ->etc()
        );

    expect(PlayerAlias::query()->where('player_id', $player->id)->pluck('alias'))
        ->toContain('Lautaro Martinez');
});

it('non registra due volte lo stesso alias', function () {
    $player = Player::factory()->create(['name' => 'MARTINEZ Lautaro']);
    PlayerAlias::query()->create(['player_id' => $player->id, 'alias' => 'Lautaro']);

    FantaAstaServer::tool(ResolvePlayerNameTool::class, ['name' => 'Lautaro'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'matched')
            ->where('alias_created', false)
            ->etc()
        );

    expect(PlayerAlias::query()->where('player_id', $player->id)->count())->toBe(1);
});

it('non sceglie fra due omonimi e non scrive nulla', function () {
    Player::factory()->create(['name' => 'THURAM Marcus', 'real_team' => 'Inter']);
    Player::factory()->create(['name' => 'THURAM Khephren', 'real_team' => 'Juventus']);

    FantaAstaServer::tool(ResolvePlayerNameTool::class, ['name' => 'Thuram'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('status', 'ambiguous')
            ->where('player', null)
            ->where('alias_created', false)
            ->etc()
        )
        ->assertSee('needs_review');

    expect(PlayerAlias::query()->count())->toBe(0);
});

it('dichiara not_found quando il nome non esiste nel listone', function () {
    Player::factory()->create(['name' => 'MARTINEZ Lautaro']);

    FantaAstaServer::tool(ResolvePlayerNameTool::class, ['name' => 'Cristiano Ronaldo'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('status', 'not_found')->where('player', null)->etc());

    expect(PlayerAlias::query()->count())->toBe(0);
});

it('rifiuta una richiesta senza nome', function () {
    FantaAstaServer::tool(ResolvePlayerNameTool::class, [])->assertHasErrors();
});

<?php

use App\Enums\PlayerRole;
use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\PlayerAlias;

it('normalizes the name automatically when set', function () {
    $player = Player::factory()->create(['name' => "N'Dicka Evan"]);

    expect($player->normalized_name)->toBe('ndicka evan');
});

it('casts role and status to their enums', function () {
    $player = Player::factory()->create(['role' => PlayerRole::Attaccante, 'status' => PlayerStatus::Available]);

    expect($player->role)->toBe(PlayerRole::Attaccante)
        ->and($player->status)->toBe(PlayerStatus::Available);
});

it('casts season_stats to an array', function () {
    $player = Player::factory()->create(['season_stats' => ['presenze' => 30, 'gol' => 12]]);

    expect($player->fresh()->season_stats)->toBe(['presenze' => 30, 'gol' => 12]);
});

it('has many aliases and can query them without lazy loading', function () {
    $player = Player::factory()->create();
    PlayerAlias::create(['player_id' => $player->id, 'alias' => 'Martinez']);

    expect($player->aliases()->count())->toBe(1);
});

it('builds a searchable array including concatenated aliases without lazy loading', function () {
    $player = Player::factory()->create(['name' => 'Lautaro Martinez']);
    PlayerAlias::create(['player_id' => $player->id, 'alias' => 'Martinez']);
    PlayerAlias::create(['player_id' => $player->id, 'alias' => 'Martinez L.']);

    $searchable = $player->toSearchableArray();

    expect($searchable['name'])->toBe('Lautaro Martinez')
        ->and($searchable['aliases'])->toContain('Martinez')
        ->and($searchable['aliases'])->toContain('Martinez L.');
});

it('is not searchable once removed from the listone', function () {
    $player = Player::factory()->create(['status' => PlayerStatus::Removed]);

    expect($player->shouldBeSearchable())->toBeFalse();
});

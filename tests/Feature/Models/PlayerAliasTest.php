<?php

use App\Models\Player;
use App\Models\PlayerAlias;
use Illuminate\Database\QueryException;

it('normalizes the alias automatically when set', function () {
    $player = Player::factory()->create();

    $alias = PlayerAlias::create(['player_id' => $player->id, 'alias' => 'Martínez L.']);

    expect($alias->normalized_alias)->toBe('martinez l');
});

it('rejects duplicate normalized aliases for the same player', function () {
    $player = Player::factory()->create();
    PlayerAlias::create(['player_id' => $player->id, 'alias' => 'Martinez']);

    expect(fn () => PlayerAlias::create(['player_id' => $player->id, 'alias' => 'MARTINEZ']))
        ->toThrow(QueryException::class);
});

it('allows the same alias text for two different players (surname homonyms)', function () {
    $playerA = Player::factory()->create();
    $playerB = Player::factory()->create();

    PlayerAlias::create(['player_id' => $playerA->id, 'alias' => 'Martinez']);
    $second = PlayerAlias::create(['player_id' => $playerB->id, 'alias' => 'Martinez']);

    expect($second->exists)->toBeTrue();
});

<?php

use App\Enums\PlayerRole;
use App\Livewire\Listone\Index;
use App\Models\Player;
use Livewire\Livewire;

it('renders the listone route', function () {
    $this->get(route('listone.index'))->assertOk();
});

it('lists imported players', function () {
    Player::factory()->create(['name' => 'Lautaro Martinez', 'role' => PlayerRole::Attaccante]);
    Player::factory()->create(['name' => 'Alessandro Bastoni', 'role' => PlayerRole::Difensore]);

    Livewire::test(Index::class)
        ->assertSee('Lautaro Martinez')
        ->assertSee('Alessandro Bastoni');
});

it('filters by search text', function () {
    Player::factory()->create(['name' => 'Lautaro Martinez']);
    Player::factory()->create(['name' => 'Alessandro Bastoni']);

    Livewire::test(Index::class)
        ->set('search', 'martinez')
        ->assertSee('Lautaro Martinez')
        ->assertDontSee('Alessandro Bastoni');
});

it('filters by role', function () {
    Player::factory()->create(['name' => 'Lautaro Martinez', 'role' => PlayerRole::Attaccante]);
    Player::factory()->create(['name' => 'Alessandro Bastoni', 'role' => PlayerRole::Difensore]);

    Livewire::test(Index::class)
        ->set('roleFilter', PlayerRole::Difensore->value)
        ->assertSee('Alessandro Bastoni')
        ->assertDontSee('Lautaro Martinez');
});

<?php

use App\Livewire\Dashboard;
use App\Models\Player;
use App\Models\Team;
use Livewire\Livewire;

it('renders the dashboard route', function () {
    $this->get(route('dashboard'))->assertOk();
});

it('shows counts of players and teams', function () {
    Player::factory()->count(3)->create();
    Team::factory()->create(['is_mine' => true, 'name' => 'La mia squadra']);

    Livewire::test(Dashboard::class)
        ->assertSee('3')
        ->assertSee('La mia squadra');
});

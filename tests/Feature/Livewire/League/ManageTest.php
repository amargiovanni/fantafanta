<?php

use App\Livewire\League\Manage;
use App\Models\LeagueConfig;
use App\Models\Team;
use Livewire\Livewire;

it('renders the lega route', function () {
    $this->get(route('lega.manage'))->assertOk();
});

it('saves the league configuration', function () {
    Livewire::test(Manage::class)
        ->set('totalCredits', 650)
        ->set('teamsCount', 10)
        ->set('modifierDefense', false)
        ->set('slotP', 3)
        ->set('slotD', 9)
        ->set('slotC', 7)
        ->set('slotA', 6)
        ->call('saveConfig');

    $config = LeagueConfig::current();

    expect($config->total_credits)->toBe(650)
        ->and($config->teams_count)->toBe(10)
        ->and($config->modifier_defense)->toBeFalse()
        ->and($config->slots)->toBe(['P' => 3, 'D' => 9, 'C' => 7, 'A' => 6]);
});

it('adds a new team', function () {
    Livewire::test(Manage::class)
        ->set('newTeamName', 'I Fenomeni')
        ->set('newTeamCredits', 500)
        ->set('newTeamIsMine', true)
        ->call('addTeam');

    $team = Team::where('name', 'I Fenomeni')->firstOrFail();

    expect($team->is_mine)->toBeTrue()
        ->and($team->credits_total)->toBe(500);
});

it('enforces a single is_mine team through the UI, unsetting the previous one', function () {
    $first = Team::factory()->create(['is_mine' => true, 'name' => 'Prima Squadra']);

    Livewire::test(Manage::class)
        ->set('newTeamName', 'Seconda Squadra')
        ->set('newTeamIsMine', true)
        ->call('addTeam');

    expect($first->fresh()->is_mine)->toBeFalse()
        ->and(Team::where('name', 'Seconda Squadra')->firstOrFail()->is_mine)->toBeTrue();
});

it('edits an existing team', function () {
    $team = Team::factory()->create(['name' => 'Vecchio Nome', 'credits_total' => 400]);

    Livewire::test(Manage::class)
        ->call('startEdit', $team->id)
        ->set('editName', 'Nuovo Nome')
        ->set('editCredits', 480)
        ->call('saveEdit');

    expect($team->fresh()->name)->toBe('Nuovo Nome')
        ->and($team->fresh()->credits_total)->toBe(480);
});

it('deletes a team', function () {
    $team = Team::factory()->create();

    Livewire::test(Manage::class)->call('deleteTeam', $team->id);

    expect(Team::find($team->id))->toBeNull();
});

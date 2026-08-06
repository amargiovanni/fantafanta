<?php

use App\Livewire\Dashboard;
use App\Models\Player;
use App\Models\Signal;
use App\Models\Team;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

/**
 * La dashboard sonda i servizi reali: nella suite le sonde sono finte, così i
 * test non dipendono dal fatto che Redis o Meilisearch girino sulla macchina
 * di chi li esegue.
 */
beforeEach(function () {
    Cache::forget('dashboard.health');

    Process::fake(['*claude*' => Process::result(output: '2.1.220 (Claude Code)')]);
    Http::fake([
        '*/health' => Http::response(['status' => 'available']),
        '*/mcp' => Http::response(['result' => ['tools' => [['name' => 'search_player']]]]),
    ]);
});

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

it('mostra lo stato dei servizi', function () {
    Livewire::test(Dashboard::class)
        ->assertSee('Stato dei servizi')
        ->assertSee('Claude Code CLI')
        ->assertSee('2.1.220 (Claude Code)')
        ->assertSee('Server MCP fanta-asta');
});

it('segnala un servizio giù', function () {
    Process::fake(['*claude*' => Process::result(output: '', errorOutput: 'command not found', exitCode: 127)]);
    Cache::forget('dashboard.health');

    Livewire::test(Dashboard::class)->assertSee('command not found');
});

it('mostra i contatori della pipeline di conoscenza', function () {
    Signal::factory()->count(2)->create();
    Signal::factory()->needsReview('il Toro')->create();

    Livewire::test(Dashboard::class)
        ->assertViewHas('signalsCount', 3)
        ->assertViewHas('signalsToReview', 1)
        ->assertSee('Segnali attivi');
});

<?php

namespace App\Livewire;

use App\Models\LeagueConfig;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Dashboard pre-asta. In Fase 0 è un placeholder con lo stato essenziale
 * della lega: la dashboard completa (piano, segnali, salute pipeline) arriva
 * in Fase 2-3.
 */
class Dashboard extends Component
{
    public function render(): View
    {
        return view('livewire.dashboard', [
            'config' => LeagueConfig::current(),
            'playersCount' => Player::query()->count(),
            'teamsCount' => Team::query()->count(),
            'myTeam' => Team::query()->where('is_mine', true)->first(),
        ]);
    }
}

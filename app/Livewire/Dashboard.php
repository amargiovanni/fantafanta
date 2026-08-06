<?php

namespace App\Livewire;

use App\Models\LeagueConfig;
use App\Models\Player;
use App\Models\Signal;
use App\Models\Source;
use App\Models\Team;
use App\Services\SystemHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * Dashboard pre-asta. Il piano d'acquisto arriva in Fase 2: qui, per ora,
 * lo stato della lega e la salute della pipeline di conoscenza.
 */
class Dashboard extends Component
{
    /** Chiave di cache dello stato dei servizi. */
    private const HEALTH_CACHE = 'dashboard.health';

    /**
     * Ricontrolla i servizi su richiesta, saltando la cache.
     */
    public function refreshHealth(): void
    {
        Cache::forget(self::HEALTH_CACHE);
    }

    public function render(SystemHealth $health): View
    {
        return view('livewire.dashboard', [
            'config' => LeagueConfig::current(),
            'playersCount' => Player::query()->count(),
            'teamsCount' => Team::query()->count(),
            'myTeam' => Team::query()->where('is_mine', true)->first(),

            // Le sonde sono chiamate di rete: si tengono 60 secondi in cache
            // così aprire la dashboard non costa tre round-trip ogni volta.
            'health' => Cache::remember(self::HEALTH_CACHE, now()->addMinute(), fn () => $health->check()),

            'sourcesQueued' => Source::query()->whereIn('status', ['queued', 'processing'])->count(),
            'signalsCount' => Signal::query()->active()->count(),
            'signalsToReview' => Signal::query()->pendingReview()->count(),
        ]);
    }
}

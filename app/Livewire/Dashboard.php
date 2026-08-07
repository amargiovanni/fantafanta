<?php

namespace App\Livewire;

use App\Enums\AuctionStatus;
use App\Enums\PlanStatus;
use App\Enums\PlanTrigger;
use App\Jobs\RecomputeValuations;
use App\Jobs\RunClaudeTask;
use App\Models\Auction;
use App\Models\LeagueConfig;
use App\Models\Plan;
use App\Models\PlanSlot;
use App\Models\Player;
use App\Models\Signal;
use App\Models\Source;
use App\Models\Team;
use App\Models\Valuation;
use App\Services\LeagueState;
use App\Services\SystemHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * Dashboard pre-asta (briefing §8.3).
 *
 * Risponde alle tre domande che ci si fa la sera prima: qual è il piano, cosa
 * è cambiato nella conoscenza, e la pipeline è viva. Le due azioni — generare
 * il piano e ricalcolare le valutazioni — sono entrambe asincrone: da qui non
 * parte nessuna elaborazione sincrona, men che meno una chiamata all'AI
 * (design §3).
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

    /**
     * Apre la sessione d'asta. Resta in `setup` finché non comincia davvero:
     * il passaggio a `live` è della sala d'asta (Fase 3).
     */
    public function openAuction(): void
    {
        if (Auction::current() instanceof Auction) {
            return;
        }

        Auction::query()->create([
            'name' => 'Asta '.now()->year,
            'status' => AuctionStatus::Setup,
        ]);

        session()->flash('dashboard', 'Sessione d\'asta aperta.');
    }

    /**
     * Mette in coda la generazione del piano. È l'unico punto della UI da cui
     * parte un run di Claude, e non blocca la richiesta: il piano comparirà
     * quando il job avrà finito.
     *
     * Crea subito la riga `plans` in stato `generating`, esattamente come fa
     * `Replanner::launch()` per il replan: senza, il badge "generazione in
     * corso" non ha nulla da guardare finché il primo piano non esiste ancora,
     * e un doppio click in quella finestra metterebbe in coda due run che
     * scriverebbero due volte la versione 1.
     */
    public function generatePlan(): void
    {
        $auction = Auction::current();

        if (! $auction instanceof Auction) {
            session()->flash('dashboard-error', 'Apri prima la sessione d\'asta: il piano deve appartenere a un\'asta.');

            return;
        }

        if (Player::query()->count() === 0) {
            session()->flash('dashboard-error', 'Il listone è vuoto: non c\'è niente su cui costruire un piano.');

            return;
        }

        if ($auction->plans()->where('status', PlanStatus::Generating)->exists()) {
            session()->flash('dashboard-error', 'C\'è già una generazione in corso: aspetta che finisca.');

            return;
        }

        $plan = Plan::query()->create([
            'auction_id' => $auction->id,
            'version' => (int) Plan::query()->where('auction_id', $auction->id)->max('version') + 1,
            'trigger' => PlanTrigger::Initial,
            'status' => PlanStatus::Generating,
        ]);

        RunClaudeTask::dispatch(
            task: 'generate-plan',
            promptFile: 'generate-plan.md',
            context: ['auction_id' => $auction->id, 'plan_id' => $plan->id],
            variables: [
                'today' => now()->toDateString(),
                'auction_id' => $auction->id,
            ],
            // Lavoro interattivo (l'utente aspetta la risposta): va sulla
            // stessa coda prioritaria del replan, non sulla coda 'ai' bulk
            // dello scraping — dove è rimasto affamato dietro al backlog di
            // un full scrape per 45+ minuti (incidente 2026-08-06).
            queue: (string) config('fanta.replan.queue'),
        );

        session()->flash('dashboard', 'Generazione del piano avviata: comparirà qui appena pronta.');
    }

    public function recomputeValuations(): void
    {
        RecomputeValuations::dispatch(Auction::current()?->id);

        session()->flash('dashboard', 'Ricalcolo delle valutazioni in coda.');
    }

    public function render(SystemHealth $health): View
    {
        $auction = Auction::current();
        $plan = $auction?->latestReadyPlan();

        return view('livewire.dashboard', [
            'config' => LeagueConfig::current(),
            'playersCount' => Player::query()->count(),
            'teamsCount' => Team::query()->count(),
            'myTeam' => Team::query()->where('is_mine', true)->first(),
            'auction' => $auction,
            'plan' => $plan,
            'planSlots' => $this->slotsByRole($plan),
            'planGenerating' => $this->isGenerating($auction, $plan),
            'state' => LeagueState::load($auction),

            // Le sonde sono chiamate di rete: si tengono 60 secondi in cache
            // così aprire la dashboard non costa tre round-trip ogni volta.
            'health' => Cache::remember(self::HEALTH_CACHE, now()->addMinute(), fn () => $health->check()),

            'sourcesQueued' => Source::query()->whereIn('status', ['queued', 'processing'])->count(),
            'signalsCount' => Signal::query()->active()->count(),
            'signalsToReview' => Signal::query()->pendingReview()->count(),
            'recentSignals' => $this->recentSignals(),
            'valuationsCount' => Valuation::query()->count(),
            'valuationsComputedAt' => Valuation::query()->max('computed_at'),
        ]);
    }

    /**
     * Gli slot del piano raggruppati per reparto, con i nomi già risolti:
     * la view non deve fare query.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function slotsByRole(?Plan $plan): array
    {
        if ($plan === null) {
            return [];
        }

        $slots = PlanSlot::query()
            ->where('plan_id', $plan->id)
            ->orderBy('slot_index')
            ->get();

        $ids = $slots->flatMap(fn (PlanSlot $slot) => $slot->involvedPlayerIds())->unique();
        $names = Player::query()->whereIn('id', $ids)->pluck('name', 'id');

        $byRole = [];

        foreach (array_keys(LeagueConfig::DEFAULT_SLOTS) as $role) {
            foreach ($slots->where('role.value', $role) as $slot) {
                $byRole[$role][] = [
                    'slot' => $slot,
                    'player_name' => $names[$slot->player_id] ?? null,
                    'alternatives' => array_map(fn (array $alternative) => [
                        'name' => $names[(int) $alternative['player_id']] ?? '—',
                        'target_price' => (int) ($alternative['target_price'] ?? 1),
                    ], $slot->alternatives ?? []),
                ];
            }
        }

        return $byRole;
    }

    private function isGenerating(?Auction $auction, ?Plan $plan): bool
    {
        if ($auction === null) {
            return false;
        }

        return $auction->plans()
            ->where('status', PlanStatus::Generating)
            ->where('version', '>', $plan?->version ?? 0)
            ->exists();
    }

    /**
     * @return Collection<int, Signal>
     */
    private function recentSignals(): Collection
    {
        return Signal::query()
            ->with('player:id,name,role')
            ->active()
            ->where('needs_review', false)
            ->whereNotNull('player_id')
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->limit(8)
            ->get();
    }
}

<?php

namespace App\Livewire\League;

use App\Models\LeagueConfig;
use App\Models\Team;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Setup lega: configurazione (crediti, squadre, modificatori) e CRUD squadre.
 * Nessuna auth: applicazione single-user locale (briefing §1, non-goals).
 */
class Manage extends Component
{
    public int $totalCredits = 500;

    public int $teamsCount = 8;

    public bool $modifierDefense = true;

    public bool $modifierFairplay = true;

    public int $slotP = 3;

    public int $slotD = 8;

    public int $slotC = 8;

    public int $slotA = 6;

    public string $newTeamName = '';

    public bool $newTeamIsMine = false;

    public int $newTeamCredits = 500;

    public ?int $editingTeamId = null;

    public string $editName = '';

    public bool $editIsMine = false;

    public int $editCredits = 0;

    public function mount(): void
    {
        $config = LeagueConfig::current();

        $this->totalCredits = $config->total_credits;
        $this->teamsCount = $config->teams_count;
        $this->modifierDefense = $config->modifier_defense;
        $this->modifierFairplay = $config->modifier_fairplay;
        $this->slotP = $config->slots['P'] ?? 3;
        $this->slotD = $config->slots['D'] ?? 8;
        $this->slotC = $config->slots['C'] ?? 8;
        $this->slotA = $config->slots['A'] ?? 6;
        $this->newTeamCredits = $this->totalCredits;
    }

    public function saveConfig(): void
    {
        $this->validate([
            'totalCredits' => ['required', 'integer', 'min:1'],
            'teamsCount' => ['required', 'integer', 'min:2'],
            'slotP' => ['required', 'integer', 'min:1'],
            'slotD' => ['required', 'integer', 'min:1'],
            'slotC' => ['required', 'integer', 'min:1'],
            'slotA' => ['required', 'integer', 'min:1'],
        ]);

        LeagueConfig::current()->update([
            'total_credits' => $this->totalCredits,
            'teams_count' => $this->teamsCount,
            'modifier_defense' => $this->modifierDefense,
            'modifier_fairplay' => $this->modifierFairplay,
            'slots' => ['P' => $this->slotP, 'D' => $this->slotD, 'C' => $this->slotC, 'A' => $this->slotA],
        ]);
    }

    public function addTeam(): void
    {
        $this->validate([
            'newTeamName' => ['required', 'string', 'max:255'],
            'newTeamCredits' => ['required', 'integer', 'min:0'],
        ]);

        Team::create([
            'name' => $this->newTeamName,
            'is_mine' => $this->newTeamIsMine,
            'credits_total' => $this->newTeamCredits,
        ]);

        $this->reset(['newTeamName', 'newTeamIsMine']);
        $this->newTeamCredits = $this->totalCredits;
    }

    public function startEdit(int $teamId): void
    {
        $team = Team::findOrFail($teamId);

        $this->editingTeamId = $team->id;
        $this->editName = $team->name;
        $this->editIsMine = $team->is_mine;
        $this->editCredits = $team->credits_total;
    }

    public function cancelEdit(): void
    {
        $this->editingTeamId = null;
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editCredits' => ['required', 'integer', 'min:0'],
        ]);

        Team::findOrFail($this->editingTeamId)->update([
            'name' => $this->editName,
            'is_mine' => $this->editIsMine,
            'credits_total' => $this->editCredits,
        ]);

        $this->editingTeamId = null;
    }

    public function deleteTeam(int $teamId): void
    {
        Team::destroy($teamId);

        if ($this->editingTeamId === $teamId) {
            $this->editingTeamId = null;
        }
    }

    public function render(): View
    {
        return view('livewire.league.manage', [
            'teams' => $this->teams(),
        ]);
    }

    /**
     * @return Collection<int, Team>
     */
    private function teams(): Collection
    {
        return Team::query()->orderByDesc('is_mine')->orderBy('name')->get();
    }
}

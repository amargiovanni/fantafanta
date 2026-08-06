<?php

namespace App\Livewire\Listone;

use App\Enums\PlayerRole;
use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Support\NameNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Vista di sola lettura del listone importato: filtro per ruolo, ricerca
 * testuale sul nome. La ricerca fuzzy vera e propria (per la sala d'asta)
 * è App\Services\PlayerSearch, usata altrove; qui basta un filtro semplice
 * sul nome per consultazione.
 */
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $roleFilter = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingRoleFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.listone.index', [
            'players' => $this->players(),
            'roles' => PlayerRole::cases(),
        ]);
    }

    private function players(): LengthAwarePaginator
    {
        return Player::query()
            ->where('status', '!=', PlayerStatus::Removed->value)
            ->when(
                $this->search !== '',
                fn ($query) => $query->where('normalized_name', 'like', '%'.NameNormalizer::normalize($this->search).'%')
            )
            ->when($this->roleFilter !== '', fn ($query) => $query->where('role', $this->roleFilter))
            ->orderByDesc('quotazione')
            ->paginate(20);
    }
}

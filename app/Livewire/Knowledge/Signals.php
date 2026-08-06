<?php

namespace App\Livewire\Knowledge;

use App\Enums\SignalType;
use App\Models\Player;
use App\Models\Signal;
use App\Support\NameNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Segnali per giocatore, con correzione e cancellazione manuale.
 *
 * L'AI è l'unica scrittrice della tabella (briefing §4), ma non è infallibile:
 * qui una persona può correggere tipo, impatto, confidenza e data, marcare un
 * segnale come superato, o cancellarlo.
 */
class Signals extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $typeFilter = '';

    public bool $onlyActive = true;

    public ?int $editing = null;

    /** @var array<string, mixed> */
    public array $form = [
        'type' => '',
        'impact' => 0,
        'confidence' => 0.5,
        'event_date' => '',
    ];

    public function edit(int $signalId): void
    {
        $signal = Signal::query()->findOrFail($signalId);

        $this->editing = $signalId;
        $this->form = [
            'type' => $signal->type->value,
            'impact' => $signal->impact,
            'confidence' => (float) $signal->confidence,
            'event_date' => $signal->event_date?->toDateString() ?? '',
        ];
    }

    public function cancelEdit(): void
    {
        $this->editing = null;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'form.type' => ['required', 'string', 'in:'.implode(',', SignalType::values())],
            'form.impact' => ['required', 'integer', 'between:-2,2'],
            'form.confidence' => ['required', 'numeric', 'between:0,1'],
            'form.event_date' => ['nullable', 'date'],
        ], [], [
            'form.type' => 'tipo',
            'form.impact' => 'impatto',
            'form.confidence' => 'confidenza',
            'form.event_date' => 'data',
        ])['form'];

        Signal::query()->findOrFail($this->editing)->update([
            'type' => $validated['type'],
            'impact' => (int) $validated['impact'],
            'confidence' => round((float) $validated['confidence'], 2),
            'event_date' => $validated['event_date'] !== '' ? $validated['event_date'] : null,
        ]);

        $this->editing = null;

        session()->flash('segnali', 'Segnale aggiornato.');
    }

    public function delete(int $signalId): void
    {
        Signal::query()->findOrFail($signalId)->delete();

        session()->flash('segnali', 'Segnale eliminato.');
    }

    /**
     * Riattiva un segnale marcato come superato per errore.
     */
    public function reactivate(int $signalId): void
    {
        Signal::query()->findOrFail($signalId)->update(['superseded_by' => null]);

        session()->flash('segnali', 'Segnale riportato fra quelli attivi.');
    }

    public function render(): View
    {
        return view('livewire.knowledge.signals', [
            'grouped' => $this->grouped(),
            'types' => SignalType::cases(),
        ]);
    }

    /**
     * Segnali raggruppati per giocatore, in ordine di attività recente.
     *
     * @return Collection<int, array{player: ?Player, signals: Collection<int, Signal>}>
     */
    private function grouped(): Collection
    {
        $query = Signal::query()->with(['player', 'source']);

        if ($this->onlyActive) {
            $query->active();
        }

        if ($this->typeFilter !== '') {
            $query->where('type', $this->typeFilter);
        }

        if (trim($this->search) !== '') {
            $normalized = NameNormalizer::normalize($this->search);

            $playerIds = Player::query()
                ->where('normalized_name', 'like', '%'.$normalized.'%')
                ->pluck('id');

            $query->where(function ($inner) use ($playerIds, $normalized) {
                $inner->whereIn('player_id', $playerIds)
                    ->orWhere('raw_name', 'like', '%'.$normalized.'%');
            });
        }

        return $query->orderByDesc('id')->get()
            ->groupBy(fn (Signal $signal) => $signal->player_id ?? 0)
            ->map(fn (Collection $signals) => [
                'player' => $signals->first()->player,
                'signals' => $signals,
            ])
            ->values();
    }
}

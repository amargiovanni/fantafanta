<?php

namespace App\Livewire\Knowledge;

use App\Enums\SourceStatus;
use App\Models\Player;
use App\Models\PlayerAlias;
use App\Models\Signal;
use App\Models\Source;
use App\Services\PlayerResolver;
use App\Support\NameNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Coda dei segnali il cui nome l'AI non ha saputo risolvere.
 *
 * Il punto di questa pagina non è correggere un segnale: è fare in modo che
 * lo stesso nome non torni mai più qui. Assegnando il giocatore si crea un
 * alias, quindi la prossima volta che una testata scrive "il Toro" la
 * risoluzione è automatica (briefing §8.1).
 */
class Review extends Component
{
    /** Ricerca manuale per segnale: [signal_id => testo cercato]. */
    /** @var array<int, string> */
    public array $queries = [];

    public function mount(): void
    {
        // Si parte dal nome grezzo: nove volte su dieci basta guardarlo.
        $this->queries = $this->pending()
            ->mapWithKeys(fn (Signal $signal) => [$signal->id => (string) $signal->raw_name])
            ->all();
    }

    /**
     * Assegna il giocatore, crea l'alias e chiude la revisione del segnale.
     */
    public function assign(int $signalId, int $playerId): void
    {
        $signal = Signal::query()->findOrFail($signalId);
        $player = Player::query()->findOrFail($playerId);

        $rawName = trim((string) $signal->raw_name);

        if ($rawName !== '') {
            $normalized = NameNormalizer::normalize($rawName);

            $giaNoto = PlayerAlias::query()
                ->where('player_id', $player->id)
                ->where('normalized_alias', $normalized)
                ->exists();

            if (! $giaNoto && $normalized !== $player->normalized_name) {
                PlayerAlias::query()->create([
                    'player_id' => $player->id,
                    'alias' => $rawName,
                ]);
            }
        }

        $signal->update([
            'player_id' => $player->id,
            'needs_review' => false,
        ]);

        $this->refreshSourceStatus($signal->source_id);

        unset($this->queries[$signalId]);

        session()->flash('revisione', "«{$rawName}» ora è {$player->name}: l'alias è stato memorizzato.");
    }

    /**
     * Il segnale non è recuperabile (nome di un giocatore fuori listone,
     * estrazione sbagliata): si elimina.
     */
    public function discard(int $signalId): void
    {
        $signal = Signal::query()->findOrFail($signalId);
        $sourceId = $signal->source_id;

        $signal->delete();
        $this->refreshSourceStatus($sourceId);

        unset($this->queries[$signalId]);

        session()->flash('revisione', 'Segnale eliminato.');
    }

    /**
     * Quando l'ultima revisione di una fonte si chiude, la fonte torna processata.
     */
    private function refreshSourceStatus(int $sourceId): void
    {
        $source = Source::query()->find($sourceId);

        if ($source === null || $source->status === SourceStatus::Failed) {
            return;
        }

        $restano = Signal::query()
            ->where('source_id', $sourceId)
            ->where('needs_review', true)
            ->exists();

        $source->update([
            'status' => $restano ? SourceStatus::NeedsReview : SourceStatus::Processed,
        ]);
    }

    /**
     * @return Collection<int, Signal>
     */
    public function pending(): Collection
    {
        return Signal::query()
            ->with('source')
            ->pendingReview()
            ->orderBy('id')
            ->get();
    }

    public function render(PlayerResolver $resolver): View
    {
        $signals = $this->pending();

        // Candidati proposti per ciascun segnale, ricalcolati a ogni digitazione.
        $suggestions = $signals->mapWithKeys(function (Signal $signal) use ($resolver) {
            $query = trim($this->queries[$signal->id] ?? (string) $signal->raw_name);

            if ($query === '') {
                return [$signal->id => []];
            }

            return [$signal->id => array_slice($resolver->candidates(NameNormalizer::normalize($query), 8), 0, 5)];
        });

        return view('livewire.knowledge.review', [
            'signals' => $signals,
            'suggestions' => $suggestions,
        ]);
    }
}

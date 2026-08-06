<?php

namespace App\Livewire\Knowledge;

use App\Enums\SourceOrigin;
use App\Enums\SourceStatus;
use App\Enums\SourceType;
use App\Jobs\ProcessSource;
use App\Models\Source;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Drop zone universale della base di conoscenza.
 *
 * Un solo gesto per qualunque cosa: si trascina un PDF, si incolla un link o
 * si scrive una nota, e diventa una source in coda. Nessuna scelta di tipo da
 * parte dell'utente — il tipo si deduce da cosa è stato dato (briefing §8.1,
 * "zero configurazione per item").
 *
 * Qui non gira nessuna AI: si crea la riga e si mette in coda. La pipeline
 * lavora dopo, e la lista mostra lo stato.
 */
class Index extends Component
{
    use WithFileUploads, WithPagination;

    public $file = null;

    /** Campo unico: ci si incolla dentro un URL oppure il testo di una nota. */
    public string $content = '';

    public string $title = '';

    public ?int $expandedSource = null;

    public function submit(): void
    {
        $this->validate([
            'file' => ['nullable', 'file', 'extensions:pdf,txt,md,csv', 'max:20480'],
            'content' => ['nullable', 'string', 'max:200000'],
            'title' => ['nullable', 'string', 'max:200'],
        ], [], [
            'file' => 'file',
            'content' => 'contenuto',
            'title' => 'titolo',
        ]);

        if ($this->file === null && trim($this->content) === '') {
            $this->addError('content', 'Carica un file, incolla un link oppure scrivi una nota.');

            return;
        }

        $source = $this->file !== null
            ? $this->sourceFromFile()
            : $this->sourceFromContent();

        ProcessSource::dispatch($source->id);

        $this->reset(['file', 'content', 'title']);
        $this->resetPage();

        session()->flash('conoscenza', "Fonte «{$source->title}» presa in carico: l'analisi parte in coda.");
    }

    private function sourceFromFile(): Source
    {
        $originalName = $this->file->getClientOriginalName();
        $extension = strtolower($this->file->getClientOriginalExtension());
        $path = $this->file->store('sources', 'local');

        return Source::query()->create([
            'type' => $extension === 'pdf' ? SourceType::Pdf : SourceType::Doc,
            'title' => trim($this->title) !== '' ? trim($this->title) : $originalName,
            'file_path' => $path,
            'origin' => SourceOrigin::Manual,
            'status' => SourceStatus::Queued,
        ]);
    }

    private function sourceFromContent(): Source
    {
        $content = trim($this->content);
        $isUrl = filter_var($content, FILTER_VALIDATE_URL) !== false
            && str_starts_with($content, 'http');

        if ($isUrl) {
            return Source::query()->create([
                'type' => SourceType::Link,
                'title' => trim($this->title) !== '' ? trim($this->title) : $content,
                'url' => $content,
                'origin' => SourceOrigin::Manual,
                'status' => SourceStatus::Queued,
            ]);
        }

        return Source::query()->create([
            'type' => SourceType::Note,
            'title' => trim($this->title) !== '' ? trim($this->title) : mb_substr($content, 0, 80),
            'raw_content' => $content,
            'origin' => SourceOrigin::Manual,
            'status' => SourceStatus::Queued,
        ]);
    }

    public function toggle(int $sourceId): void
    {
        $this->expandedSource = $this->expandedSource === $sourceId ? null : $sourceId;
    }

    /**
     * Rimette in coda una fonte finita in errore.
     */
    public function retry(int $sourceId): void
    {
        $source = Source::query()->findOrFail($sourceId);

        $source->update([
            'status' => SourceStatus::Queued,
            'error' => null,
            // L'hash si ricalcola: senza azzerarlo la fonte si troverebbe
            // duplicata di se stessa.
            'content_hash' => null,
        ]);

        ProcessSource::dispatch($source->id);

        session()->flash('conoscenza', 'Fonte rimessa in coda.');
    }

    public function delete(int $sourceId): void
    {
        Source::query()->findOrFail($sourceId)->delete();

        session()->flash('conoscenza', 'Fonte eliminata insieme ai suoi segnali.');
    }

    /**
     * Una source scaricata dallo scraping può restare in coda oltre il tetto
     * di estrazioni del giro (spec Fase 4, §Tetto): questo bottone la manda
     * comunque in pipeline, fuori tetto — è una scelta esplicita di Andrea,
     * non automatica.
     */
    public function processAnyway(int $sourceId): void
    {
        $source = Source::query()->findOrFail($sourceId);
        $source->update(['queue_note' => null]);

        ProcessSource::dispatch($source->id);

        session()->flash('conoscenza', 'Fonte messa in coda per l\'estrazione, fuori dal tetto del giro.');
    }

    public function render(): View
    {
        return view('livewire.knowledge.index', [
            'sources' => $this->sources(),
            'counters' => $this->counters(),
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, Source>
     */
    private function sources(): LengthAwarePaginator
    {
        return Source::query()
            ->with(['signals' => fn ($query) => $query->with('player')->orderByDesc('id')])
            ->withCount('signals')
            ->latest('id')
            ->paginate(15);
    }

    /**
     * @return array<string, int>
     */
    private function counters(): array
    {
        $byStatus = Source::query()
            ->selectRaw('status, count(*) as totale')
            ->groupBy('status')
            ->pluck('totale', 'status');

        return [
            'in_coda' => (int) $byStatus->get(SourceStatus::Queued->value, 0)
                + (int) $byStatus->get(SourceStatus::Processing->value, 0),
            'processate' => (int) $byStatus->get(SourceStatus::Processed->value, 0),
            'da_rivedere' => (int) $byStatus->get(SourceStatus::NeedsReview->value, 0),
            'in_errore' => (int) $byStatus->get(SourceStatus::Failed->value, 0),
        ];
    }
}

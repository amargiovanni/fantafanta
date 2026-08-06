<?php

namespace App\Livewire\Knowledge;

use App\Jobs\ScrapeTargetFull;
use App\Jobs\ScrapeTargetNow;
use App\Models\ScrapeTarget;
use App\Scraping\Support\CircuitBreaker;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * Gestione testate dello scraping (briefing §8.1, spec Fase 4 §Backoffice):
 * CRUD, stato del circuito, "Scrape ora" per singola testata, "Full scrape"
 * globale con barra di avanzamento e cancellazione.
 *
 * L'id del batch attivo vive in cache (non solo nella proprietà Livewire):
 * un refresh della pagina durante un full scrape in corso deve continuare a
 * mostrarne l'avanzamento, non perderlo.
 */
class Targets extends Component
{
    private const ACTIVE_BATCH_CACHE_KEY = 'scraping:full-scrape:active-batch';

    public ?int $editingId = null;

    public string $name = '';

    public string $url = '';

    public string $rssUrl = '';

    public bool $enabled = true;

    public ?string $activeBatchId = null;

    public function mount(): void
    {
        $this->activeBatchId = Cache::get(self::ACTIVE_BATCH_CACHE_KEY);
    }

    public function startCreate(): void
    {
        $this->reset(['editingId', 'name', 'url', 'rssUrl']);
        $this->enabled = true;
    }

    public function edit(int $id): void
    {
        $target = ScrapeTarget::query()->findOrFail($id);

        $this->editingId = $target->id;
        $this->name = $target->name;
        $this->url = $target->url;
        $this->rssUrl = (string) $target->rss_url;
        $this->enabled = $target->enabled;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:150'],
            'url' => ['required', 'url', 'max:255', 'unique:scrape_targets,url,'.($this->editingId ?? 'NULL')],
            'rssUrl' => ['nullable', 'url', 'max:255'],
            'enabled' => ['boolean'],
        ], [], [
            'name' => 'nome',
            'url' => 'URL',
            'rssUrl' => 'URL del feed RSS',
        ]);

        $values = [
            'name' => $data['name'],
            'url' => $data['url'],
            'rss_url' => blank($data['rssUrl']) ? null : $data['rssUrl'],
            'enabled' => $data['enabled'],
        ];

        // `id` non è mass-assegnabile (giustamente): updateOrCreate([id => null], ...)
        // lo proverebbe comunque, quindi si sceglie fra create/update esplicitamente.
        if ($this->editingId === null) {
            ScrapeTarget::query()->create($values);
        } else {
            ScrapeTarget::query()->whereKey($this->editingId)->update($values);
        }

        session()->flash('conoscenza', $this->editingId ? 'Testata aggiornata.' : 'Testata aggiunta.');

        $this->startCreate();
    }

    public function delete(int $id): void
    {
        ScrapeTarget::query()->findOrFail($id)->delete();

        session()->flash('conoscenza', 'Testata eliminata.');
    }

    public function scrapeNow(int $id): void
    {
        ScrapeTargetNow::dispatch($id);

        session()->flash('conoscenza', 'Scrape avviato per questa testata: i nuovi articoli compariranno in coda a breve.');
    }

    public function fullScrape(): void
    {
        $targets = ScrapeTarget::query()->where('enabled', true)->get();

        if ($targets->isEmpty()) {
            session()->flash('conoscenza', 'Nessuna testata abilitata da scansionare.');

            return;
        }

        $jobs = $targets->map(fn (ScrapeTarget $target) => new ScrapeTargetFull($target->id))->all();

        $batch = Bus::batch($jobs)
            ->name('full-scrape-'.now()->format('Y-m-d-His'))
            ->onQueue((string) config('fanta.scraping.queue'))
            ->allowFailures()
            ->dispatch();

        Cache::put(self::ACTIVE_BATCH_CACHE_KEY, $batch->id, now()->addHours(6));
        $this->activeBatchId = $batch->id;

        session()->flash('conoscenza', "Full scrape avviato su {$targets->count()} testate.");
    }

    public function cancelFullScrape(): void
    {
        if ($this->activeBatchId !== null) {
            Bus::findBatch($this->activeBatchId)?->cancel();
        }

        session()->flash('conoscenza', 'Full scrape annullato.');
    }

    public function render(): View
    {
        return view('livewire.knowledge.targets', [
            'targets' => $this->targets(),
            'batch' => $this->batchProgress(),
        ]);
    }

    /**
     * @return Collection<int, array{target: ScrapeTarget, circuit: array{open: bool, failures: int, opened_until: ?CarbonImmutable}}>
     */
    private function targets(): Collection
    {
        $breaker = app(CircuitBreaker::class);

        return ScrapeTarget::query()
            ->orderBy('name')
            ->get()
            ->map(fn (ScrapeTarget $target) => [
                'target' => $target,
                'circuit' => $breaker->state($target),
            ]);
    }

    /**
     * @return array{active: bool, total: int, processed: int, percent: int, finished: bool, cancelled: bool, failed: int}|null
     */
    private function batchProgress(): ?array
    {
        if ($this->activeBatchId === null) {
            return null;
        }

        $batch = Bus::findBatch($this->activeBatchId);

        if ($batch === null) {
            $this->activeBatchId = null;
            Cache::forget(self::ACTIVE_BATCH_CACHE_KEY);

            return null;
        }

        if ($batch->finished()) {
            Cache::forget(self::ACTIVE_BATCH_CACHE_KEY);
        }

        return [
            'active' => ! $batch->finished(),
            'total' => $batch->totalJobs,
            'processed' => $batch->processedJobs(),
            'percent' => $batch->totalJobs > 0 ? (int) round(($batch->processedJobs() / $batch->totalJobs) * 100) : 0,
            'finished' => $batch->finished(),
            'cancelled' => $batch->cancelled(),
            'failed' => $batch->failedJobs,
        ];
    }
}

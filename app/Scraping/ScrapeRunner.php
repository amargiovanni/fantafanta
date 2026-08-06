<?php

namespace App\Scraping;

use App\Enums\SourceOrigin;
use App\Enums\SourceStatus;
use App\Enums\SourceType;
use App\Jobs\ProcessSource;
use App\Models\ScrapeTarget;
use App\Models\Source;
use App\Scraping\Support\CircuitBreaker;
use App\Scraping\Support\ExtractionGate;
use App\Scraping\Support\TitleDeduper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestratore dello scraping (spec Fase 4, §Architettura).
 *
 * Per ogni testata: sceglie il parser (RSS se c'è, altrimenti — o in
 * fallback — il crawl HTML), scopre gli articoli, li dedup­lica in due
 * passi (URL già visto, poi titolo simile nella finestra recente) e crea le
 * source nuove, mettendole in coda per l'estrazione entro il tetto di spesa
 * del giro. Non estrae mai contenuto: quello resta compito di `ProcessSource`,
 * pipeline già esistente e già testata.
 *
 * Il fallimento di UNA testata non deve mai fermare le altre: ogni chiamata
 * pubblica cattura le proprie eccezioni e restituisce sempre un
 * `ScrapeRunResult`, mai un'eccezione che risalga al chiamante.
 */
class ScrapeRunner
{
    public function __construct(
        private readonly ParserRegistry $parsers,
        private readonly CircuitBreaker $breaker,
        private readonly TitleDeduper $deduper,
        private readonly ExtractionGate $gate,
    ) {}

    /**
     * Giro schedulato (ogni `schedule_interval_minutes`): solo il nuovo dallo
     * scorso passaggio, una sola pagina di lista HTML se serve il fallback.
     */
    public function runScheduled(ScrapeTarget $target, string $runId): ScrapeRunResult
    {
        $since = $target->last_scraped_at !== null
            ? CarbonImmutable::instance($target->last_scraped_at)
            : now()->subMinutes(max(30, (int) config('fanta.scraping.schedule_interval_minutes') * 2))->toImmutable();

        return $this->run($target, SourceOrigin::ScheduledScrape, $runId, htmlPages: 1, since: $since);
    }

    /**
     * Full scrape on demand: finestra e paginazione più ampie (spec Fase 4,
     * §Full scrape), un giro per testata dentro il batch.
     */
    public function runFull(ScrapeTarget $target, string $runId, ?int $windowDays = null, ?int $htmlPages = null): ScrapeRunResult
    {
        $windowDays ??= (int) config('fanta.scraping.full_scrape.window_days');
        $htmlPages ??= (int) config('fanta.scraping.full_scrape.html_pages');

        return $this->run(
            $target,
            SourceOrigin::FullScrape,
            $runId,
            htmlPages: max(1, $htmlPages),
            since: now()->subDays(max(1, $windowDays))->toImmutable(),
        );
    }

    private function run(ScrapeTarget $target, SourceOrigin $origin, string $runId, int $htmlPages, CarbonImmutable $since): ScrapeRunResult
    {
        if ($this->breaker->isOpen($target)) {
            return ScrapeRunResult::circuitOpen($target);
        }

        $refs = $this->discover($target, $htmlPages);

        $refs = array_values(array_filter(
            $refs,
            fn (ArticleRef $ref) => $ref->publishedAt === null || $ref->publishedAt->gte($since),
        ));

        $created = 0;
        $duplicates = 0;
        $queuedOverCap = 0;
        $parser = $this->parsers->primaryFor($target);

        foreach ($refs as $ref) {
            if (Source::query()->where('url', $ref->url)->exists()) {
                continue;
            }

            $content = $this->safeExtract($parser, $target, $ref);

            if ($content === null) {
                continue;
            }

            $title = trim($content->title) !== '' ? $content->title : $ref->title;

            if ($this->deduper->isDuplicate($title)) {
                $duplicates++;

                continue;
            }

            $source = Source::query()->create([
                'type' => SourceType::ScrapedArticle,
                'title' => mb_substr($title, 0, 255),
                'url' => $ref->url,
                'published_at' => $content->publishedAt ?? $ref->publishedAt,
                'origin' => $origin,
                'scrape_target_id' => $target->id,
                'status' => SourceStatus::Queued,
            ]);

            $created++;

            if ($this->gate->tryAcquire($runId)) {
                ProcessSource::dispatch($source->id);
            } else {
                $queuedOverCap++;
                $source->update(['queue_note' => 'In attesa: tetto estrazioni raggiunto.']);
            }
        }

        $target->update([
            'last_scraped_at' => now(),
            'last_run_articles_found' => $created,
        ]);

        return new ScrapeRunResult($target, $created, $duplicates, $queuedOverCap);
    }

    /**
     * @return array<int, ArticleRef>
     */
    private function discover(ScrapeTarget $target, int $htmlPages): array
    {
        $parser = $this->parsers->primaryFor($target);
        $refs = $this->safeDiscover($parser, $target, $htmlPages);

        if ($refs !== []) {
            return $refs;
        }

        $fallback = $this->parsers->fallbackFor($target);

        return $fallback !== null ? $this->safeDiscover($fallback, $target, $htmlPages) : [];
    }

    /**
     * @return array<int, ArticleRef>
     */
    private function safeDiscover(TargetParser $parser, ScrapeTarget $target, int $htmlPages): array
    {
        try {
            return $parser->discover($target, $htmlPages);
        } catch (Throwable $exception) {
            Log::warning("Discovery fallita per la testata #{$target->id} ({$target->name}): {$exception->getMessage()}");

            return [];
        }
    }

    private function safeExtract(TargetParser $parser, ScrapeTarget $target, ArticleRef $ref): ?ArticleContent
    {
        try {
            return $parser->extract($target, $ref);
        } catch (Throwable $exception) {
            Log::warning("Estrazione titolo fallita per {$ref->url} (testata #{$target->id}): {$exception->getMessage()}");

            return null;
        }
    }
}

<?php

namespace App\Jobs;

use App\Enums\SourceStatus;
use App\Enums\SourceType;
use App\Models\Source;
use App\Scraping\Support\Exceptions\CircuitOpenException;
use App\Scraping\Support\Exceptions\RobotsDisallowedException;
use App\Scraping\Support\Exceptions\ScrapingHttpException;
use App\Scraping\Support\ScrapingHttpClient;
use App\Support\Extraction\ArticleExtractor;
use App\Support\Extraction\PdfTextExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Primo passo della pipeline di conoscenza: porta una source dallo stato in
 * cui è entrata (un link, un file, un testo incollato) a testo grezzo pronto
 * per l'analisi, poi mette in coda l'estrazione dei segnali.
 *
 * Qui dentro non c'è nessuna chiamata AI: è tutto lavoro deterministico. La
 * dedup avviene prima di spendere una esecuzione di Claude, non dopo.
 */
class ProcessSource implements ShouldQueue
{
    use Queueable;

    /** Estrazione deterministica: se fallisce, ritentare non cambia l'esito. */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $sourceId)
    {
        $this->onQueue('ai');
    }

    public function handle(ArticleExtractor $articles, PdfTextExtractor $pdfs, ScrapingHttpClient $scrapingHttp): void
    {
        $source = Source::query()->find($this->sourceId);

        if ($source === null) {
            return;
        }

        $source->update(['status' => SourceStatus::Processing, 'error' => null]);

        try {
            $content = $this->extractContent($source, $articles, $pdfs, $scrapingHttp);
        } catch (Throwable $exception) {
            $source->update([
                'status' => SourceStatus::Failed,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $hash = Source::hashContent($content);
        $duplicate = Source::query()
            ->where('content_hash', $hash)
            ->whereKeyNot($source->id)
            ->first();

        if ($duplicate !== null) {
            // Stessa notizia già in archivio: si conserva il testo per
            // ispezione ma non si spende una esecuzione di Claude su di essa.
            $source->update([
                'raw_content' => $content,
                'status' => SourceStatus::Duplicate,
                'processed_at' => now(),
                'error' => sprintf('Contenuto identico alla fonte #%d — "%s".', $duplicate->id, $duplicate->title),
            ]);

            return;
        }

        $source->update([
            'raw_content' => $content,
            'content_hash' => $hash,
        ]);

        Bus::chain([
            new RunClaudeTask(
                task: 'extract-signals',
                promptFile: 'extract-signals.md',
                context: ['source_id' => $source->id],
                variables: [
                    'today' => now()->toDateString(),
                    'source_id' => $source->id,
                    'source_type' => $source->type->label(),
                    'source_title' => $source->title,
                    'source_url' => $source->url ?? '—',
                    'source_content' => $content,
                ],
            ),
            new FinalizeSourceProcessing($source->id),
        ])->dispatch();
    }

    private function extractContent(Source $source, ArticleExtractor $articles, PdfTextExtractor $pdfs, ScrapingHttpClient $scrapingHttp): string
    {
        $content = match ($source->type) {
            SourceType::Pdf => $pdfs->extract($this->absolutePath($source)),
            SourceType::Link => $this->extractFromUrl($source, $articles),
            SourceType::ScrapedArticle => $this->extractFromScrapedUrl($source, $articles, $scrapingHttp),
            SourceType::Doc => $this->extractFromTextFile($source),
            SourceType::Note => (string) $source->raw_content,
        };

        $content = trim($content);

        if ($content === '') {
            throw new RuntimeException('Nessun testo estratto dalla fonte: non c\'è niente da analizzare.');
        }

        return $content;
    }

    private function extractFromUrl(Source $source, ArticleExtractor $articles): string
    {
        if (blank($source->url)) {
            throw new RuntimeException('Fonte di tipo link senza URL.');
        }

        $response = Http::withHeaders([
            // User-agent identificato: lo scraping di questo progetto si
            // presenta per quello che è (briefing §7.4).
            'User-Agent' => 'FantaAstaAI/1.0 (+https://fanta-asta.test; uso personale, single user)',
        ])->timeout(20)->retry(2, 500, throw: false)->get($source->url);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'La pagina ha risposto %d: impossibile leggerne il contenuto.',
                $response->status(),
            ));
        }

        $html = $response->body();
        $text = $articles->extract($html);

        // Se la fonte è arrivata senza un titolo utile, si prende quello della pagina.
        if ($source->title === '' || $source->title === $source->url) {
            if ($title = $articles->title($html)) {
                $source->update(['title' => mb_substr($title, 0, 255)]);
            }
        }

        return $text;
    }

    /**
     * Come `extractFromUrl`, ma per gli articoli scaricati dallo scraping
     * automatico: la richiesta passa da `ScrapingHttpClient` invece che da un
     * fetch "nudo", perché la spec (§Etica e robustezza) tratta esplicitamente
     * "il job dell'articolo" come soggetto a backoff su 429/5xx e a circuito —
     * non solo la fase di scoperta. Un link incollato a mano da Andrea resta
     * sul percorso semplice: è un fetch deliberato e isolato, non crawling.
     */
    private function extractFromScrapedUrl(Source $source, ArticleExtractor $articles, ScrapingHttpClient $scrapingHttp): string
    {
        if (blank($source->url)) {
            throw new RuntimeException('Fonte di tipo link senza URL.');
        }

        $target = $source->scrapeTarget;

        if ($target === null) {
            // Non dovrebbe accadere (ScrapeRunner valorizza sempre scrape_target_id),
            // ma un articolo scaricato senza testata associata non deve bloccarsi
            // silenziosamente: si degrada al fetch semplice.
            return $this->extractFromUrl($source, $articles);
        }

        try {
            $response = $scrapingHttp->get($target, $source->url);
        } catch (CircuitOpenException|RobotsDisallowedException|ScrapingHttpException $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        $html = $response->body();
        $text = $articles->extract($html);

        if ($source->title === '' || $source->title === $source->url) {
            if ($title = $articles->title($html)) {
                $source->update(['title' => mb_substr($title, 0, 255)]);
            }
        }

        return $text;
    }

    private function extractFromTextFile(Source $source): string
    {
        $path = $this->absolutePath($source);
        $content = (string) file_get_contents($path);

        // I formati binari (docx, doc, odt) non sono supportati in questa fase:
        // meglio dirlo che consegnare a Claude una sequenza di byte illeggibile.
        if (str_contains(substr($content, 0, 2048), "\0")) {
            throw new RuntimeException(
                'Formato non supportato: converti il documento in PDF o incolla il testo direttamente.'
            );
        }

        return $content;
    }

    private function absolutePath(Source $source): string
    {
        if (blank($source->file_path)) {
            throw new RuntimeException('Fonte di tipo file senza file allegato.');
        }

        // Il percorso è sempre relativo al disco `local`, la cui radice non è
        // storage/app ma storage/app/private: farsela dire da Storage evita di
        // costruire a mano un percorso che cambierebbe con la configurazione.
        return Storage::disk('local')->path((string) $source->file_path);
    }
}

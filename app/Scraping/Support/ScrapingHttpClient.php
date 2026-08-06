<?php

namespace App\Scraping\Support;

use App\Models\ScrapeTarget;
use App\Scraping\Support\Exceptions\CircuitOpenException;
use App\Scraping\Support\Exceptions\RobotsDisallowedException;
use App\Scraping\Support\Exceptions\ScrapingHttpException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Unico punto da cui lo scraping fa una richiesta HTTP in uscita (spec Fase 4,
 * §Etica e robustezza). Ogni chiamata attraversa, in ordine:
 *
 *  1. circuito della testata — se aperto, niente richiesta;
 *  2. robots.txt — se il path è vietato, niente richiesta;
 *  3. rate limit di dominio — attesa se necessaria;
 *  4. la richiesta vera e propria, con backoff su 429/5xx.
 *
 * Un fallimento (eccezione di trasporto, o risposta non 2xx dopo i
 * ritentativi) conta sempre come un fallimento di circuito, un successo lo
 * azzera. Questo vale per QUALUNQUE chiamata passi da qui — feed RSS, pagine
 * lista, e il fetch del contenuto di un articolo scaricato (vedi
 * `ProcessSource`, che per le source di tipo `scraped_article` passa da qui
 * invece che dal fetch "nudo" usato per i link incollati a mano: la spec
 * tratta esplicitamente "il job dell'articolo" come soggetto a backoff e
 * circuito, non solo la fase di scoperta).
 */
class ScrapingHttpClient
{
    public function __construct(
        private readonly RobotsGuard $robots,
        private readonly DomainRateLimiter $rateLimiter,
        private readonly CircuitBreaker $breaker,
    ) {}

    /**
     * @throws CircuitOpenException|RobotsDisallowedException|ScrapingHttpException
     */
    public function get(ScrapeTarget $target, string $url): Response
    {
        if ($this->breaker->isOpen($target)) {
            throw new CircuitOpenException("Circuito aperto per la testata #{$target->id} ({$target->name}).");
        }

        if (! $this->robots->isAllowed($url)) {
            throw new RobotsDisallowedException("robots.txt vieta l'accesso a {$url}.");
        }

        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $this->rateLimiter->throttle($host);

        $backoff = $this->backoffSchedule();

        try {
            $response = Http::withHeaders(['User-Agent' => (string) config('fanta.scraping.user_agent')])
                ->timeout(20)
                ->retry(
                    count($backoff) + 1,
                    fn (int $attempt) => $backoff[$attempt - 1] ?? end($backoff),
                    fn (Throwable $exception) => $this->isRetryable($exception),
                    throw: false,
                )
                ->get($url);
        } catch (Throwable $exception) {
            $this->breaker->recordFailure($target);

            throw new ScrapingHttpException("Richiesta fallita per {$url}: {$exception->getMessage()}", previous: $exception);
        }

        if (! $response->successful()) {
            $this->breaker->recordFailure($target);

            throw new ScrapingHttpException("Risposta {$response->status()} da {$url}.");
        }

        $this->breaker->recordSuccess($target);

        return $response;
    }

    private function isRetryable(Throwable $exception): bool
    {
        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        return $status === 429 || $status >= 500;
    }

    /**
     * @return array<int, int>
     */
    private function backoffSchedule(): array
    {
        $schedule = (array) config('fanta.scraping.backoff_ms', [30000, 120000]);

        return $schedule === [] ? [30000, 120000] : array_values(array_map(intval(...), $schedule));
    }
}

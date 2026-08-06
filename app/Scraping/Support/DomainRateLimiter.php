<?php

namespace App\Scraping\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * Almeno `rate_limit_seconds` fra due richieste allo stesso dominio (briefing
 * §7.4: "max 1 req/2s"), valido anche nel full scrape dove più job possono
 * bersagliare lo stesso dominio in sequenza ravvicinata.
 *
 * L'attesa è un vero sleep (via `Sleep`, non un `usleep()` nudo) perché è
 * l'unico modo che il framework offre di renderla istantanea nei test
 * (`Sleep::fake()`) restando codice di produzione onesto: il job resta
 * bloccato per il tempo dovuto, non si riaccoda — la spec lo ammette
 * esplicitamente ("sleep/re-dispatch se troppo presto").
 */
class DomainRateLimiter
{
    public function throttle(string $domain): void
    {
        $minSeconds = max(0, (int) config('fanta.scraping.rate_limit_seconds'));

        if ($minSeconds > 0) {
            $last = Cache::get($this->key($domain));

            if ($last !== null) {
                $elapsedMs = (microtime(true) - (float) $last) * 1000;
                $remainingMs = ($minSeconds * 1000) - $elapsedMs;

                if ($remainingMs > 0) {
                    Sleep::usleep((int) round($remainingMs * 1000));
                }
            }
        }

        Cache::put($this->key($domain), microtime(true), now()->addMinutes(5));
    }

    private function key(string $domain): string
    {
        return "scraping:last-request:{$domain}";
    }
}

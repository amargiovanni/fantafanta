<?php

namespace App\Scraping\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Rispetto di robots.txt per lo user-agent `*` (spec Fase 4, §Etica e
 * robustezza): fetch e cache per dominio a 24h, parser prefix-match
 * volutamente semplice — non un parser RFC completo, solo `User-agent: *` /
 * `Disallow: <path>` riga per riga, che è quanto la spec chiede esplicitamente
 * ("parser nativo semplice: prefix match dei path").
 *
 * Un robots.txt irraggiungibile o assente (404, timeout) equivale ad
 * "nessuna restrizione": è la convenzione standard, e bloccare lo scraping
 * per l'assenza di un file che la maggior parte dei siti non pubblica
 * sarebbe più dannoso che permissivo.
 */
class RobotsGuard
{
    public function isAllowed(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null || $host === '') {
            return true;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $path = parse_url($url, PHP_URL_PATH) ?: '/';

        $disallowed = Cache::remember(
            $this->cacheKey($host),
            now()->addHours($this->cacheHours()),
            fn () => $this->fetchDisallowRules($scheme, $host),
        );

        foreach ($disallowed as $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return true;
    }

    private function cacheKey(string $host): string
    {
        return "scraping:robots:{$host}";
    }

    /**
     * @return array<int, string>
     */
    private function fetchDisallowRules(string $scheme, string $host): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => (string) config('fanta.scraping.user_agent')])
                ->timeout(10)
                ->get("{$scheme}://{$host}/robots.txt");
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        return $this->parse($response->body());
    }

    /**
     * @return array<int, string>
     */
    private function parse(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        $disallow = [];
        $appliesToAll = false;

        foreach ($lines as $line) {
            $line = trim(preg_replace('/#.*/', '', $line) ?? '');

            if ($line === '') {
                continue;
            }

            if (preg_match('/^User-agent:\s*(.+)$/i', $line, $matches) === 1) {
                $appliesToAll = trim($matches[1]) === '*';

                continue;
            }

            if ($appliesToAll && preg_match('/^Disallow:\s*(.*)$/i', $line, $matches) === 1) {
                $path = trim($matches[1]);

                if ($path !== '') {
                    $disallow[] = $path;
                }
            }
        }

        return $disallow;
    }

    private function cacheHours(): int
    {
        return max(1, (int) config('fanta.scraping.robots_cache_hours'));
    }
}

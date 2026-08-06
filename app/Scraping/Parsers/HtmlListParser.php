<?php

namespace App\Scraping\Parsers;

use App\Models\ScrapeTarget;
use App\Scraping\ArticleContent;
use App\Scraping\ArticleRef;
use App\Scraping\Support\ScrapingHttpClient;
use App\Scraping\TargetParser;
use App\Support\Extraction\ArticleExtractor;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fallback quando una testata non ha (o non risponde con) un feed RSS: crawl
 * euristico della pagina news via DOMDocument/XPath (deviazione D7).
 *
 * L'euristica (spec Fase 4, §Architettura — "link stesso dominio sotto
 * sezioni news, dedup URL"): si prendono i link `<a>` che puntano allo stesso
 * dominio, con un testo d'ancora abbastanza lungo da essere un titolo e non
 * una voce di menu ("Home", "Accedi"…), deduplicati per URL nella pagina.
 * Non è ispezione semantica del markup: è deliberatamente semplice e
 * ispezionabile, come `ArticleExtractor` da cui riprende lo stile.
 */
class HtmlListParser implements TargetParser
{
    /** Sotto questa lunghezza di testo un link è quasi sempre navigazione, non un titolo. */
    private const MIN_TITLE_LENGTH = 20;

    public function __construct(
        private readonly ScrapingHttpClient $http,
        private readonly ArticleExtractor $articleExtractor,
    ) {}

    public function discover(ScrapeTarget $target, int $htmlPages = 1): array
    {
        $host = parse_url($target->url, PHP_URL_HOST);
        $refs = [];
        $seenUrls = [];

        foreach (range(1, max(1, $htmlPages)) as $page) {
            $pageUrl = $page === 1 ? $target->url : $this->paginatedUrl($target->url, $page);

            try {
                $response = $this->http->get($target, $pageUrl);
            } catch (Throwable $exception) {
                Log::warning("Pagina lista non raggiungibile per la testata #{$target->id} ({$target->name}), pagina {$page}: {$exception->getMessage()}");

                break;
            }

            $links = $this->extractLinks($response->body(), $target->url, $host);
            $newOnThisPage = 0;

            foreach ($links as $link) {
                if (isset($seenUrls[$link['url']])) {
                    continue;
                }

                $seenUrls[$link['url']] = true;
                $refs[] = new ArticleRef($link['url'], $link['title']);
                $newOnThisPage++;
            }

            // Pagina "vuota" di novità (paginazione non supportata dal sito,
            // o archivio finito): proseguire non troverebbe altro.
            if ($newOnThisPage === 0) {
                break;
            }
        }

        return $refs;
    }

    public function extract(ScrapeTarget $target, ArticleRef $ref): ?ArticleContent
    {
        try {
            $response = $this->http->get($target, $ref->url);
        } catch (Throwable $exception) {
            Log::warning("Articolo non raggiungibile per la testata #{$target->id} ({$target->name}): {$exception->getMessage()}");

            return null;
        }

        // Il testo d'ancora della lista è spesso troncato o tutto maiuscolo:
        // il <title> della pagina vera è più affidabile per la dedup.
        $realTitle = $this->articleExtractor->title($response->body());

        return new ArticleContent($realTitle ?? $ref->title);
    }

    /**
     * @return array<int, array{url: string, title: string}>
     */
    private function extractLinks(string $html, string $baseUrl, ?string $host): array
    {
        $html = trim($html);

        if ($html === '') {
            return [];
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $links = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            $text = preg_replace('/\s+/u', ' ', trim($anchor->textContent)) ?? '';

            if ($href === '' || mb_strlen($text) < self::MIN_TITLE_LENGTH) {
                continue;
            }

            if (str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'javascript:')) {
                continue;
            }

            $absolute = $this->resolveUrl($baseUrl, $href);
            $linkHost = parse_url($absolute, PHP_URL_HOST);

            if ($host !== null && $linkHost !== $host) {
                continue;
            }

            $links[] = ['url' => $absolute, 'title' => $text];
        }

        return $links;
    }

    private function resolveUrl(string $baseUrl, string $href): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $baseParts = parse_url($baseUrl);
        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'] ?? '';

        if (str_starts_with($href, '//')) {
            return "{$scheme}:{$href}";
        }

        if (str_starts_with($href, '/')) {
            return "{$scheme}://{$host}{$href}";
        }

        $basePath = rtrim(dirname($baseParts['path'] ?? '/'), '/');

        return "{$scheme}://{$host}{$basePath}/{$href}";
    }

    /**
     * Convenzione generica (parametro `page`); una testata che ne richieda
     * una diversa passa da un parser dedicato via `parser_overrides`.
     */
    private function paginatedUrl(string $url, int $page): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return "{$url}{$separator}page={$page}";
    }
}

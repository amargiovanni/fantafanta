<?php

namespace App\Scraping\Parsers;

use App\Models\ScrapeTarget;
use App\Scraping\ArticleContent;
use App\Scraping\ArticleRef;
use App\Scraping\Support\ScrapingHttpClient;
use App\Scraping\TargetParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Throwable;

/**
 * Parser del feed RSS di una testata, via SimpleXML (deviazione D7 del
 * design: nessun pacchetto composer per il parsing XML). Gestisce solo
 * RSS 2.0 (`<channel><item>`), che è il formato di tutti i feed verificati
 * nel seed (FantaMaster, SOS Fanta, Gazzetta) — un feed Atom non riconosciuto
 * torna semplicemente un array vuoto, e `ScrapeRunner` prova il fallback HTML.
 */
class RssParser implements TargetParser
{
    public function __construct(private readonly ScrapingHttpClient $http) {}

    public function discover(ScrapeTarget $target, int $htmlPages = 1): array
    {
        if (blank($target->rss_url)) {
            return [];
        }

        try {
            $response = $this->http->get($target, $target->rss_url);
        } catch (Throwable $exception) {
            Log::warning("RSS non raggiungibile per la testata #{$target->id} ({$target->name}): {$exception->getMessage()}");

            return [];
        }

        $xml = $this->parse($response->body());

        if ($xml === null || ! isset($xml->channel->item)) {
            return [];
        }

        $refs = [];

        foreach ($xml->channel->item as $item) {
            $link = trim((string) $item->link);
            $title = html_entity_decode(trim((string) $item->title), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($link === '' || $title === '') {
                continue;
            }

            $refs[] = new ArticleRef($link, $title, $this->parseDate((string) $item->pubDate));
        }

        return $refs;
    }

    public function extract(ScrapeTarget $target, ArticleRef $ref): ?ArticleContent
    {
        // Il titolo del feed è già affidabile: non serve un'altra richiesta
        // HTTP solo per confermarlo. Il testo dell'articolo lo scarica
        // `ProcessSource` quando la source viene processata.
        return new ArticleContent($ref->title, $ref->publishedAt);
    }

    private function parse(string $body): ?SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $xml !== false ? $xml : null;
    }

    private function parseDate(string $pubDate): ?CarbonImmutable
    {
        if ($pubDate === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($pubDate);
        } catch (Throwable) {
            return null;
        }
    }
}

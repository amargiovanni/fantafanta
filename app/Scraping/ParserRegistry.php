<?php

namespace App\Scraping;

use App\Models\ScrapeTarget;
use App\Scraping\Parsers\HtmlListParser;
use App\Scraping\Parsers\RssParser;

/**
 * Sceglie il parser primario per una testata (spec Fase 4, §Architettura:
 * "registry per-testata se una testata richiede un parser dedicato in
 * futuro"). Oggi la regola è solo "RSS se c'è, altrimenti HTML"; `parser_overrides`
 * in config permette di agganciare un parser su misura per una singola
 * testata (chiave `scrape_targets.id` → class-string di un `TargetParser`)
 * senza toccare `ScrapeRunner` quando quel giorno arriverà.
 */
class ParserRegistry
{
    public function __construct(
        private readonly RssParser $rss,
        private readonly HtmlListParser $htmlList,
    ) {}

    public function primaryFor(ScrapeTarget $target): TargetParser
    {
        if ($override = $this->override($target)) {
            return $override;
        }

        return blank($target->rss_url) ? $this->htmlList : $this->rss;
    }

    /**
     * Parser da tentare se il primario non trova nulla (es. RSS momentaneamente
     * vuoto o rotto): la spec chiede esplicitamente il fallback al crawl HTML.
     */
    public function fallbackFor(ScrapeTarget $target): ?TargetParser
    {
        $primary = $this->primaryFor($target);

        return $primary === $this->htmlList ? null : $this->htmlList;
    }

    private function override(ScrapeTarget $target): ?TargetParser
    {
        $overrides = (array) config('fanta.scraping.parser_overrides', []);
        $class = $overrides[$target->id] ?? null;

        return is_string($class) && $class !== '' ? app($class) : null;
    }
}

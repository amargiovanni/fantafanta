<?php

namespace App\Scraping;

use Carbon\CarbonImmutable;

/**
 * Il titolo "vero" di un articolo, rifinito dal parser prima che una source
 * venga creata — serve alla dedup per titolo (spec Fase 4, §Dedup 2). Il
 * testo dell'articolo NON viene portato qui: lo estrae `ProcessSource`
 * quando la source viene processata, riusando la pipeline esistente.
 */
final class ArticleContent
{
    public function __construct(
        public readonly string $title,
        public readonly ?CarbonImmutable $publishedAt = null,
    ) {}
}

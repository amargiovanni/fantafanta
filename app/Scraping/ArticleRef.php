<?php

namespace App\Scraping;

use Carbon\CarbonImmutable;

/**
 * Un articolo scoperto in una lista (feed RSS o pagina news) prima ancora di
 * sapere se merita una source: solo URL, titolo così come appare nella lista
 * e, quando disponibile, la data di pubblicazione dichiarata dalla fonte.
 */
final class ArticleRef
{
    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly ?CarbonImmutable $publishedAt = null,
    ) {}
}

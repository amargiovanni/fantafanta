<?php

namespace App\Enums;

/**
 * Natura della fonte entrata nella base di conoscenza.
 */
enum SourceType: string
{
    case Link = 'link';
    case Pdf = 'pdf';
    case Doc = 'doc';
    case Note = 'note';
    case ScrapedArticle = 'scraped_article';

    public function label(): string
    {
        return match ($this) {
            self::Link => 'Link',
            self::Pdf => 'PDF',
            self::Doc => 'Documento',
            self::Note => 'Nota',
            self::ScrapedArticle => 'Articolo scaricato',
        };
    }
}

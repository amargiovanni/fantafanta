<?php

namespace App\Enums;

/**
 * Da dove arriva la source: caricata a mano o prodotta dallo scraping.
 */
enum SourceOrigin: string
{
    case Manual = 'manual';
    case ScheduledScrape = 'scheduled_scrape';
    case FullScrape = 'full_scrape';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manuale',
            self::ScheduledScrape => 'Scraping schedulato',
            self::FullScrape => 'Scraping completo',
        };
    }
}

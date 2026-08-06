<?php

namespace App\Scraping;

use App\Models\ScrapeTarget;

/**
 * Esito di un giro di scraping su una singola testata: quanto trovato, quanto
 * scartato e perché. Usato dai job per il log e dalla UI per i contatori.
 */
final class ScrapeRunResult
{
    public function __construct(
        public readonly ScrapeTarget $target,
        public readonly int $created = 0,
        public readonly int $duplicates = 0,
        public readonly int $queuedOverCap = 0,
        public readonly bool $skipped = false,
        public readonly ?string $note = null,
    ) {}

    public static function circuitOpen(ScrapeTarget $target): self
    {
        return new self($target, skipped: true, note: 'Circuito aperto: testata saltata.');
    }
}

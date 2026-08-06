<?php

namespace App\Scraping\Support;

use App\Models\Source;
use App\Support\NameNormalizer;

/**
 * Secondo livello di dedup (spec Fase 4, §Dedup): prima ancora di creare la
 * source, il titolo normalizzato viene confrontato con quelli delle source
 * degli ultimi N giorni. `NameNormalizer` è pensato per nomi giocatore ma la
 * sua pipeline — minuscolo, ASCII, via i non alfanumerici, spazi collassati —
 * è esattamente quel che serve anche per un titolo, e riusarlo evita di
 * duplicare la stessa logica sotto un altro nome.
 *
 * Questo NON sostituisce la dedup per `content_hash` (livello 1, in
 * `ProcessSource`): coglie i casi in cui il testo non è ancora stato
 * scaricato ma il titolo grida già "stessa notizia" — la stessa breaking news
 * ripresa da due testate con un titolo quasi identico.
 */
class TitleDeduper
{
    public function isDuplicate(string $title): bool
    {
        $normalized = NameNormalizer::normalize($title);

        if ($normalized === '') {
            return false;
        }

        $recentTitles = Source::query()
            ->where('created_at', '>=', now()->subDays($this->windowDays()))
            ->pluck('title');

        foreach ($recentTitles as $existingTitle) {
            $existingNormalized = NameNormalizer::normalize((string) $existingTitle);

            if ($existingNormalized === '') {
                continue;
            }

            similar_text($normalized, $existingNormalized, $percent);

            if ($percent >= $this->threshold()) {
                return true;
            }
        }

        return false;
    }

    private function windowDays(): int
    {
        return max(1, (int) config('fanta.scraping.dedup_window_days'));
    }

    private function threshold(): float
    {
        return (float) config('fanta.scraping.title_similarity_threshold');
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Normalizza i nomi dei giocatori per il matching deterministico.
 *
 * Usato ovunque un nome viene confrontato: import del listone, generazione
 * alias, ricerca fuzzy. La normalizzazione deve essere idempotente e
 * indipendente da maiuscole, accenti e punteggiatura.
 */
class NameNormalizer
{
    public static function normalize(string $name): string
    {
        $normalized = mb_strtolower(trim($name), 'UTF-8');

        // Traslittera in ASCII: rimuove accenti (é -> e, ñ -> n, ...).
        $normalized = Str::ascii($normalized);

        // Rimuove apostrofi e punteggiatura (N'Dicka -> ndicka, "Thuram K." -> thuram k).
        $normalized = preg_replace('/[^a-z0-9\s]/', '', $normalized);

        // Collassa spazi multipli e rimuove quelli residui ai bordi.
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return trim($normalized);
    }
}

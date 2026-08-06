<?php

namespace App\Support;

/**
 * Somiglianza fra due nomi già normalizzati.
 *
 * Serve a decidere se un nome grezzo trovato in un articolo può essere
 * agganciato automaticamente a un giocatore (creando un alias) o se va
 * mandato in revisione manuale. Il rischio n.1 del progetto è il segnale
 * attribuito al giocatore sbagliato (briefing §10): questa classe è la
 * soglia che lo previene, e sbaglia sempre per eccesso di prudenza.
 *
 * Il punteggio è una media dei token pesata sulla loro lunghezza, così
 * "martinez l" somiglia molto a "lautaro martinez" (il cognome pieno pesa
 * otto volte l'iniziale), mentre la sola iniziale "l" non somiglia a nulla.
 */
class NameSimilarity
{
    /**
     * @return float 0.0–1.0
     */
    public static function score(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        $tokensA = array_values(array_filter(explode(' ', $a)));
        $tokensB = array_values(array_filter(explode(' ', $b)));

        if ($tokensA === [] || $tokensB === []) {
            return 0.0;
        }

        // Si confrontano sempre i token del nome più corto contro il più lungo:
        // "lautaro" contro "lautaro martinez" deve valere 1.0, non 0.5.
        [$short, $long] = count($tokensA) <= count($tokensB)
            ? [$tokensA, $tokensB]
            : [$tokensB, $tokensA];

        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($short as $token) {
            $best = 0.0;

            foreach ($long as $candidate) {
                $best = max($best, self::tokenScore($token, $candidate));
            }

            $weight = mb_strlen($token);
            $weightedSum += $best * $weight;
            $weightTotal += $weight;
        }

        return $weightTotal > 0 ? round($weightedSum / $weightTotal, 4) : 0.0;
    }

    /**
     * Somiglianza fra due singoli token.
     */
    private static function tokenScore(string $token, string $candidate): float
    {
        if ($token === $candidate) {
            return 1.0;
        }

        if (str_starts_with($candidate, $token) || str_starts_with($token, $candidate)) {
            // Un prefisso di almeno tre lettere ("thur" per "thuram") è un
            // indizio forte. Una o due lettere sono un'iniziale: accanto a un
            // cognome pieno aiuta ("Martinez L."), da sola non identifica
            // nessuno e non deve mai bastare ad agganciare un giocatore.
            return min(mb_strlen($token), mb_strlen($candidate)) >= 3 ? 0.9 : 0.5;
        }

        $distance = levenshtein($token, $candidate);
        $longest = max(mb_strlen($token), mb_strlen($candidate));

        if ($longest === 0) {
            return 0.0;
        }

        return max(0.0, 1.0 - ($distance / $longest));
    }
}

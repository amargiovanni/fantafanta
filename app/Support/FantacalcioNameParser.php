<?php

namespace App\Support;

/**
 * Interpreta il campo "Nome" del listone fantacalcio.it, che si presenta in
 * due forme deterministiche a seconda della disponibilità del nome nella
 * riga: "COGNOME Nome" (es. "MARTINEZ Lautaro") oppure, quando fantacalcio.it
 * abbrevia per disambiguare un'omonimia, "Cognome N." (es. "THURAM K.").
 *
 * Il cognome è riconosciuto perché scritto interamente in maiuscolo, anche
 * quando composto da più parole (es. "DI LORENZO", "DI GREGORIO"). Un token
 * di una sola lettera seguito da un punto (es. "K.") è sempre trattato come
 * iniziale del nome, mai come cognome, indipendentemente dal casing.
 */
class FantacalcioNameParser
{
    /**
     * @return array{surname: string, given: string, given_initial: ?string, display: string}
     */
    public static function parse(string $raw): array
    {
        $tokens = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $surnameTokens = [];
        $givenTokens = [];

        foreach ($tokens as $token) {
            $isInitial = (bool) preg_match('/^\p{L}\.$/u', $token);
            $letters = preg_replace('/[^\p{L}]/u', '', $token) ?? '';
            $isUppercaseWord = ! $isInitial
                && $letters !== ''
                && mb_strlen($letters, 'UTF-8') > 1
                && mb_strtoupper($letters, 'UTF-8') === $letters;

            if ($isUppercaseWord && $givenTokens === []) {
                $surnameTokens[] = $token;
            } else {
                $givenTokens[] = $token;
            }
        }

        // Formato inatteso (nessun cognome riconosciuto): l'intera stringa
        // diventa il cognome, nessun nome/iniziale.
        if ($surnameTokens === []) {
            $surnameTokens = $tokens;
            $givenTokens = [];
        }

        $surname = implode(' ', $surnameTokens);
        $given = implode(' ', $givenTokens);
        $givenInitial = $given !== ''
            ? mb_strtoupper(mb_substr(rtrim($given, '.'), 0, 1, 'UTF-8'), 'UTF-8')
            : null;

        return [
            'surname' => $surname,
            'given' => $given,
            'given_initial' => $givenInitial,
            'display' => self::toTitleCase(trim($surname.' '.$given)),
        ];
    }

    private static function toTitleCase(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }
}

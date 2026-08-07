<?php

namespace App\Support;

/**
 * Interpreta il campo "Nome" del listone fantacalcio.it, che nella storia del
 * progetto si è presentato in due formati distinti:
 *
 *  - Storico (CSV, tutto maiuscolo): "COGNOME Nome" (es. "MARTINEZ Lautaro")
 *    oppure, per disambiguare un'omonimia, "Cognome N." (es. "THURAM K.").
 *    Il cognome è riconosciuto perché scritto interamente in maiuscolo,
 *    anche quando composto da più parole (es. "DI LORENZO").
 *
 *  - Reale (export XLSX ufficiale, Title Case): non esiste alcun token tutto
 *    maiuscolo da cui distinguere il cognome. La regola è invece: un token
 *    che termina con un punto è sempre un'iniziale del nome (es. "Jo." in
 *    "Martinez Jo.", "L." in "Martinez L."); tutto il resto è cognome, anche
 *    multi-parola (es. "Di Lorenzo"). Se nessun token termina con un punto,
 *    l'intera stringa è il cognome — un solo nome noto (es. "Svilar") o un
 *    nome composto usato come identificativo unico senza abbreviazione
 *    (es. "Di Lorenzo", "Carlos Augusto").
 *
 * Il formato storico ha sempre la precedenza quando lo riconosce (un token
 * tutto maiuscolo di almeno due lettere), per restare compatibile con CSV
 * già in uso; altrimenti si applica la regola del formato reale.
 */
class FantacalcioNameParser
{
    /**
     * @return array{surname: string, given: string, given_initial: ?string, display: string}
     */
    public static function parse(string $raw): array
    {
        $tokens = preg_split('/\s+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        [$surnameTokens, $givenTokens] = self::splitByUppercaseSurname($tokens);

        if ($surnameTokens === []) {
            [$surnameTokens, $givenTokens] = self::splitByTrailingDotInitial($tokens);
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

    /**
     * Formato storico "COGNOME Nome" / "Cognome N.": un token tutto
     * maiuscolo (almeno due lettere) è cognome finché non è ancora comparso
     * un token di nome; un token di una sola lettera seguito da un punto
     * (es. "K.") è sempre un'iniziale, mai un cognome, indipendentemente dal
     * casing. Ritorna `[[], []]` se non viene riconosciuto nessun token tutto
     * maiuscolo: segnala al chiamante di provare il formato reale.
     *
     * @param  array<int, string>  $tokens
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function splitByUppercaseSurname(array $tokens): array
    {
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

        if ($surnameTokens === []) {
            return [[], []];
        }

        return [$surnameTokens, $givenTokens];
    }

    /**
     * Formato reale xlsx "Cognome I.": i token che terminano con un punto
     * sono iniziali del nome; tutti gli altri (anche multi-parola, es. "Di
     * Lorenzo") sono cognome. Se nessun token termina con un punto, l'intera
     * stringa è il cognome, senza nome/iniziale.
     *
     * @param  array<int, string>  $tokens
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private static function splitByTrailingDotInitial(array $tokens): array
    {
        $surnameTokens = [];
        $givenTokens = [];

        foreach ($tokens as $token) {
            if (str_ends_with($token, '.')) {
                $givenTokens[] = $token;
            } else {
                $surnameTokens[] = $token;
            }
        }

        if ($givenTokens === []) {
            return [$tokens, []];
        }

        return [$surnameTokens, $givenTokens];
    }

    private static function toTitleCase(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }
}

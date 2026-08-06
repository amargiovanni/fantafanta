<?php

namespace App\Support\Extraction;

use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Estrae il testo da un PDF testuale con smalot/pdfparser.
 *
 * I PDF scansionati (immagini senza livello di testo) non sono gestiti: il
 * fallimento è esplicito, così la source finisce in errore visibile invece di
 * mandare a Claude un prompt vuoto — che costerebbe una esecuzione vera per
 * non produrre nulla.
 */
class PdfTextExtractor
{
    public function __construct(private readonly Parser $parser = new Parser) {}

    public function extract(string $absolutePath): string
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException("File PDF non trovato: {$absolutePath}");
        }

        try {
            $text = $this->parser->parseFile($absolutePath)->getText();
        } catch (Throwable $exception) {
            throw new RuntimeException('PDF illeggibile: '.$exception->getMessage(), previous: $exception);
        }

        $text = trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);

        if ($text === '') {
            throw new RuntimeException(
                'Dal PDF non è stato estratto alcun testo: probabilmente è una scansione senza livello testuale.'
            );
        }

        return $text;
    }
}

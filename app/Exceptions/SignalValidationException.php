<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Batch di segnali rifiutato dalla validazione server-side.
 *
 * Porta con sé l'elenco puntuale degli errori (uno per segnale, con l'indice)
 * perché il tool MCP possa restituirli a Claude in forma correggibile: il
 * contratto del briefing §6 è che l'AI riceve un errore dettagliato e corregge
 * al turno successivo, non che indovini cosa non andava.
 */
class SignalValidationException extends RuntimeException
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Batch di segnali rifiutato: '.implode(' | ', $errors));
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}

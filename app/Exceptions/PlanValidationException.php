<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Piano rifiutato dalla validazione server-side.
 *
 * Porta l'elenco COMPLETO delle violazioni, non la prima: chi ha proposto il
 * piano — Claude, in un run headless che costa tempo e sottoscrizione — deve
 * poterlo correggere in un turno solo (briefing §6).
 */
class PlanValidationException extends RuntimeException
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Piano rifiutato: '.implode(' | ', $errors));
    }

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}

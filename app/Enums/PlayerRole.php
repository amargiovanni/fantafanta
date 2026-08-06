<?php

namespace App\Enums;

/**
 * Ruolo del giocatore nel regolamento Classic fantacalcio.it.
 */
enum PlayerRole: string
{
    case Portiere = 'P';
    case Difensore = 'D';
    case Centrocampista = 'C';
    case Attaccante = 'A';

    public function label(): string
    {
        return match ($this) {
            self::Portiere => 'Portiere',
            self::Difensore => 'Difensore',
            self::Centrocampista => 'Centrocampista',
            self::Attaccante => 'Attaccante',
        };
    }
}

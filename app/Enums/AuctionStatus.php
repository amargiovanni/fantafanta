<?php

namespace App\Enums;

/**
 * Stato di una sessione d'asta (briefing §4).
 *
 * `setup` è la preparazione (listone importato, squadre registrate, piano
 * generato), `live` è la serata dell'asta, `closed` l'archivio.
 */
enum AuctionStatus: string
{
    case Setup = 'setup';
    case Live = 'live';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Setup => 'In preparazione',
            self::Live => 'In corso',
            self::Closed => 'Chiusa',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

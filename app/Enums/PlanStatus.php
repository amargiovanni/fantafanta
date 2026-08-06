<?php

namespace App\Enums;

/**
 * Stato di una versione di piano (briefing §4).
 *
 * `generating` esiste perché la UI mostri "ricalcolo in corso" tenendo attiva
 * l'ultima versione `ready`: un piano a metà non si mostra mai.
 */
enum PlanStatus: string
{
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Generating => 'In elaborazione',
            self::Ready => 'Pronto',
            self::Failed => 'Fallito',
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

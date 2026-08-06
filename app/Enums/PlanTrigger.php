<?php

namespace App\Enums;

/**
 * Cosa ha provocato la generazione di una versione del piano (briefing §4).
 */
enum PlanTrigger: string
{
    case Initial = 'initial';
    case Acquisition = 'acquisition';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Piano iniziale',
            self::Acquisition => 'Dopo un\'aggiudicazione',
            self::Manual => 'Richiesto a mano',
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

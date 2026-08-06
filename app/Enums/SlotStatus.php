<?php

namespace App\Enums;

/**
 * Stato di uno slot del piano (briefing §4).
 *
 * `lost` non significa "slot vuoto": il titolare è stato preso da un'altra
 * squadra e lo slot mostra la prima alternativa promossa dal
 * App\Services\PlanSlotPromoter, in attesa che il replan lo rifinisca.
 */
enum SlotStatus: string
{
    case Pending = 'pending';
    case Acquired = 'acquired';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Da prendere',
            self::Acquired => 'Preso',
            self::Lost => 'Sfumato',
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

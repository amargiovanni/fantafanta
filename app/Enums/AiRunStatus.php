<?php

namespace App\Enums;

/**
 * Esito di una singola esecuzione di `claude -p` (audit, briefing §3).
 */
enum AiRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'In attesa',
            self::Running => 'In esecuzione',
            self::Succeeded => 'Completata',
            self::Failed => 'Fallita',
        };
    }
}

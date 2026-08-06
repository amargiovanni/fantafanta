<?php

namespace App\Enums;

/**
 * Stato della source nella pipeline di ingestion.
 *
 * queued → processing → processed | failed | needs_review
 * `duplicate` chiude una source il cui content_hash è già presente.
 */
enum SourceStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case NeedsReview = 'needs_review';
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'In coda',
            self::Processing => 'In lavorazione',
            self::Processed => 'Processata',
            self::Failed => 'Errore',
            self::NeedsReview => 'Da rivedere',
            self::Duplicate => 'Duplicata',
        };
    }
}

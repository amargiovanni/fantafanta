<?php

namespace App\Models;

use App\Enums\SourceOrigin;
use App\Enums\SourceStatus;
use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una fonte di conoscenza: link, PDF, documento, nota o articolo scaricato.
 * Il testo estratto vive in `raw_content` e viene dato in pasto alla pipeline
 * di estrazione segnali.
 */
#[Fillable([
    'type',
    'title',
    'url',
    'published_at',
    'file_path',
    'raw_content',
    'content_hash',
    'origin',
    'scrape_target_id',
    'processed_at',
    'status',
    'error',
    'queue_note',
])]
class Source extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => SourceType::class,
            'origin' => SourceOrigin::class,
            'status' => SourceStatus::class,
            'processed_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Signal, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    /**
     * Testata da cui questo articolo è stato scaricato (Fase 4). Assente per
     * le fonti caricate a mano.
     *
     * @return BelongsTo<ScrapeTarget, $this>
     */
    public function scrapeTarget(): BelongsTo
    {
        return $this->belongsTo(ScrapeTarget::class);
    }

    /**
     * Hash canonico del contenuto, usato per la dedup fra fonti diverse che
     * riportano lo stesso testo.
     */
    public static function hashContent(string $content): string
    {
        return hash('sha256', trim(preg_replace('/\s+/u', ' ', $content) ?? $content));
    }
}

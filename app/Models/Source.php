<?php

namespace App\Models;

use App\Enums\SourceOrigin;
use App\Enums\SourceStatus;
use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
    'file_path',
    'raw_content',
    'content_hash',
    'origin',
    'processed_at',
    'status',
    'error',
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
     * Hash canonico del contenuto, usato per la dedup fra fonti diverse che
     * riportano lo stesso testo.
     */
    public static function hashContent(string $content): string
    {
        return hash('sha256', trim(preg_replace('/\s+/u', ' ', $content) ?? $content));
    }
}

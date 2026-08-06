<?php

namespace App\Models;

use App\Enums\SignalType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Segnale fanta-rilevante estratto da una source.
 *
 * Un segnale è "attivo" quando non è stato superato da uno più recente che lo
 * contraddice: solo i segnali attivi pesano sulla valutazione (Fase 2).
 */
#[Fillable([
    'player_id',
    'type',
    'payload',
    'confidence',
    'impact',
    'source_id',
    'event_date',
    'superseded_by',
    'needs_review',
    'raw_name',
])]
class Signal extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => SignalType::class,
            'payload' => 'array',
            'confidence' => 'float',
            'impact' => 'integer',
            'event_date' => 'date',
            'needs_review' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('superseded_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pendingReview(Builder $query): void
    {
        $query->where('needs_review', true);
    }

    public function isActive(): bool
    {
        return $this->superseded_by === null;
    }
}

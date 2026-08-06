<?php

namespace App\Models;

use App\Enums\PlanStatus;
use App\Enums\PlanTrigger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una versione del piano d'acquisto (briefing §4, §7.2).
 *
 * Append-only: non si aggiorna un piano, se ne scrive uno nuovo con
 * version = max + 1. Così la sala d'asta può sempre mostrare l'ultima versione
 * pronta mentre la successiva è ancora in generazione.
 */
#[Fillable([
    'auction_id',
    'version',
    'trigger',
    'status',
    'strategy_notes',
    'budget_summary',
])]
class Plan extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'trigger' => PlanTrigger::class,
            'status' => PlanStatus::class,
            'budget_summary' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Auction, $this>
     */
    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    /**
     * @return HasMany<PlanSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(PlanSlot::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ready(Builder $query): void
    {
        $query->where('status', PlanStatus::Ready);
    }
}

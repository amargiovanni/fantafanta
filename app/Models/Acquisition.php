<?php

namespace App\Models;

use App\Observers\AcquisitionObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Aggiudicazione di un giocatore a una squadra (briefing §4).
 *
 * Soft delete: l'undo dell'asta ripristina crediti, slot e stato del giocatore
 * senza cancellare la storia. Per questo ogni conteggio di crediti spesi e
 * ogni calcolo di inflazione lavora sulle righe non cancellate.
 */
#[ObservedBy(AcquisitionObserver::class)]
#[Fillable(['auction_id', 'player_id', 'team_id', 'price', 'valuation_at_purchase', 'plan_effects'])]
class Acquisition extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'valuation_at_purchase' => 'float',
            'plan_effects' => 'array',
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
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}

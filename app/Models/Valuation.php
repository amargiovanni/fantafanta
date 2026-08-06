<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Valutazione corrente di un giocatore: l'output del ValuationEngine
 * (briefing §5).
 *
 * Nessun calcolo vive in questo modello. La riga è già la risposta: la sala
 * d'asta la legge e basta, perché il percorso sincrono ha un budget di 50 ms
 * (design §3).
 */
#[Fillable([
    'player_id',
    'base_value',
    'adjusted_value',
    'max_bid',
    'tier',
    'scarcity_index',
    'computed_at',
])]
class Valuation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'base_value' => 'float',
            'adjusted_value' => 'float',
            'max_bid' => 'integer',
            'tier' => 'integer',
            'scarcity_index' => 'float',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}

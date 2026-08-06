<?php

namespace App\Models;

use App\Enums\PlayerRole;
use App\Enums\SlotStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uno dei 25 slot di un piano: il titolare designato, il prezzo a cui puntare,
 * il tetto oltre il quale lasciar perdere, e le alternative in ordine di
 * preferenza (briefing §4).
 *
 * Le alternative sono l'unica difesa contro un'asta random: il turno di un
 * giocatore può arrivare quando i crediti sono finiti, o non arrivare affatto.
 */
#[Fillable([
    'plan_id',
    'role',
    'slot_index',
    'player_id',
    'target_price',
    'max_price',
    'alternatives',
    'slot_status',
])]
class PlanSlot extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'role' => PlayerRole::class,
            'slot_index' => 'integer',
            'target_price' => 'integer',
            'max_price' => 'integer',
            'alternatives' => 'array',
            'slot_status' => SlotStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * Id dei giocatori coinvolti in questo slot, titolare incluso: è ciò che
     * il motore usa per sapere chi è "nel piano" e merita il bonus scarsità.
     *
     * @return array<int, int>
     */
    public function involvedPlayerIds(): array
    {
        $ids = $this->player_id !== null ? [(int) $this->player_id] : [];

        foreach ($this->alternatives ?? [] as $alternative) {
            if (isset($alternative['player_id'])) {
                $ids[] = (int) $alternative['player_id'];
            }
        }

        return array_values(array_unique($ids));
    }
}

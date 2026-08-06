<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Squadra della lega, registrata prima dell'asta.
 *
 * Il vincolo "una sola squadra con is_mine=true" è applicativo (non un
 * constraint di database): quando una squadra viene salvata con is_mine=true,
 * il flag viene disattivato su tutte le altre nella stessa transazione.
 */
#[Fillable(['name', 'is_mine', 'credits_total'])]
class Team extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_mine' => 'boolean',
            'credits_total' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Team $team) {
            if (! $team->is_mine) {
                return;
            }

            DB::transaction(function () use ($team) {
                static::query()
                    ->where('is_mine', true)
                    ->when($team->exists, fn ($query) => $query->whereKeyNot($team->getKey()))
                    ->update(['is_mine' => false]);
            });
        });
    }

    /**
     * @return HasMany<Acquisition, $this>
     */
    public function acquisitions(): HasMany
    {
        return $this->hasMany(Acquisition::class);
    }

    /**
     * Crediti spesi finora: la somma dei prezzi pagati, esclusi gli acquisti
     * annullati (soft delete).
     *
     * Quando la relazione è già caricata la usa, altrimenti fa una query
     * esplicita: lo strict mode di Eloquent vieta il lazy loading, e questo
     * accessor viene letto anche dentro cicli sulle squadre.
     */
    protected function creditsSpent(): Attribute
    {
        return Attribute::make(
            get: fn (): int => $this->relationLoaded('acquisitions')
                ? (int) $this->acquisitions->sum('price')
                : (int) Acquisition::query()->where('team_id', $this->getKey())->sum('price'),
        );
    }

    protected function creditsRemaining(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->credits_total - $this->credits_spent,
        );
    }
}

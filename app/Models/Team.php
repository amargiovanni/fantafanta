<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
     * Crediti spesi finora. In Fase 0 non esistono ancora acquisizioni:
     * derivato sempre a 0 fino a quando App\Models\Acquisition arriverà in
     * Fase 2, quando questo accessor sommerà i prezzi pagati.
     */
    protected function creditsSpent(): Attribute
    {
        return Attribute::make(
            get: fn () => 0,
        );
    }

    protected function creditsRemaining(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->credits_total - $this->credits_spent,
        );
    }
}

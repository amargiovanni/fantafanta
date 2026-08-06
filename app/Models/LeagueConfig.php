<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Configurazione della lega. Tabella singleton: esiste sempre e solo la riga
 * id=1, ottenuta/creata tramite LeagueConfig::current().
 */
#[Fillable([
    'slots',
    'total_credits',
    'teams_count',
    'modifier_defense',
    'modifier_fairplay',
    'auction_type',
])]
class LeagueConfig extends Model
{
    protected $table = 'league_config';

    public const ID = 1;

    /**
     * Slot rosa di default per il regolamento Classic (briefing §2).
     *
     * @var array<string, int>
     */
    public const DEFAULT_SLOTS = ['P' => 3, 'D' => 8, 'C' => 8, 'A' => 6];

    protected function casts(): array
    {
        return [
            'slots' => 'array',
            'total_credits' => 'integer',
            'teams_count' => 'integer',
            'modifier_defense' => 'boolean',
            'modifier_fairplay' => 'boolean',
        ];
    }

    /**
     * Restituisce la riga di configurazione singleton, creandola con i
     * default della lega Classic se non esiste ancora.
     */
    public static function current(): self
    {
        return static::find(self::ID) ?? tap(new static([
            'slots' => self::DEFAULT_SLOTS,
            'total_credits' => 500,
            'teams_count' => 8,
            'modifier_defense' => true,
            'modifier_fairplay' => true,
            'auction_type' => 'random',
        ]), function (self $config) {
            // L'id non è mass-assignable: viene forzato a 1 per garantire il singleton.
            $config->id = self::ID;
            $config->save();
        });
    }
}

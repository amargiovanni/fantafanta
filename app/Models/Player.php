<?php

namespace App\Models;

use App\Enums\PlayerRole;
use App\Enums\PlayerStatus;
use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Scout\Searchable;

/**
 * Giocatore del listone. Entità canonica su cui si aggancia tutto il resto
 * del dominio (segnali, valutazioni, acquisizioni).
 */
#[Fillable([
    'name',
    'normalized_name',
    'role',
    'real_team',
    'quotazione',
    'fvm',
    'season_stats',
    'status',
    'is_rigorista',
    'expected_starter',
])]
class Player extends Model
{
    use HasFactory, Searchable;

    protected function casts(): array
    {
        return [
            'role' => PlayerRole::class,
            'status' => PlayerStatus::class,
            'season_stats' => 'array',
            'is_rigorista' => 'boolean',
            'expected_starter' => 'float',
            'quotazione' => 'integer',
            'fvm' => 'integer',
        ];
    }

    /**
     * @return HasMany<PlayerAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(PlayerAlias::class);
    }

    /**
     * Normalizza e imposta il nome, tenendo `normalized_name` in sincronia.
     */
    public function setNameAttribute(string $value): void
    {
        $this->attributes['name'] = $value;
        $this->attributes['normalized_name'] = NameNormalizer::normalize($value);
    }

    /**
     * @return HasMany<Signal, $this>
     */
    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class);
    }

    /**
     * Valutazione corrente prodotta dal ValuationEngine (briefing §5).
     *
     * @return HasOne<Valuation, $this>
     */
    public function valuation(): HasOne
    {
        return $this->hasOne(Valuation::class);
    }

    /**
     * @return HasMany<Acquisition, $this>
     */
    public function acquisitions(): HasMany
    {
        return $this->hasMany(Acquisition::class);
    }

    public function searchableAs(): string
    {
        return 'players';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        // Query esplicita (non lazy) per rispettare lo strict mode di Eloquent
        // anche quando il modello non arriva con la relazione già caricata.
        $aliases = $this->relationLoaded('aliases')
            ? $this->aliases
            : $this->aliases()->get();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'normalized_name' => $this->normalized_name,
            'aliases' => $aliases->pluck('alias')->implode(' '),
        ];
    }

    /**
     * Esclude i giocatori rimossi dal listone dalla ricerca in asta.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->status !== PlayerStatus::Removed;
    }
}

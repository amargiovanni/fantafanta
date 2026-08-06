<?php

namespace App\Models;

use App\Support\NameNormalizer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alias con cui un giocatore può essere cercato: generato automaticamente
 * all'import, dall'AI (Fase 1) o inserito manualmente da backoffice.
 */
#[Fillable(['player_id', 'alias', 'normalized_alias'])]
class PlayerAlias extends Model
{
    /**
     * @return BelongsTo<Player, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function setAliasAttribute(string $value): void
    {
        $this->attributes['alias'] = $value;
        $this->attributes['normalized_alias'] = NameNormalizer::normalize($value);
    }
}

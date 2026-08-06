<?php

namespace App\Services;

use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\PlayerAlias;
use App\Support\NameNormalizer;
use Illuminate\Support\Collection;

/**
 * Ricerca fuzzy dei giocatori: normalizza l'input, prova prima il match
 * esatto (alias o nome normalizzato, score 1.0), altrimenti delega a
 * Meilisearch via Scout (typo-tolerant) restituendo candidati con score.
 *
 * Deve risolvere allo stesso giocatore input come "lautaro", "Martinez L.",
 * "martinez lautaro", "LAUTARO MARTINEZ".
 */
class PlayerSearch
{
    /**
     * @return Collection<int, array{player: Player, score: float}>
     */
    public function search(string $query, int $limit = 10): Collection
    {
        $normalized = NameNormalizer::normalize($query);

        if ($normalized === '') {
            return collect();
        }

        $exact = $this->exactMatch($normalized);
        if ($exact->isNotEmpty()) {
            return $exact;
        }

        return $this->fuzzyMatch($query, $limit);
    }

    /**
     * @return Collection<int, array{player: Player, score: float}>
     */
    private function exactMatch(string $normalized): Collection
    {
        $byName = Player::query()
            ->where('normalized_name', $normalized)
            ->where('status', '!=', PlayerStatus::Removed->value)
            ->pluck('id');

        $byAlias = PlayerAlias::query()
            ->where('normalized_alias', $normalized)
            ->whereHas('player', fn ($q) => $q->where('status', '!=', PlayerStatus::Removed->value))
            ->pluck('player_id');

        $ids = $byName->merge($byAlias)->unique();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Player::query()->whereIn('id', $ids)->get()
            ->map(fn (Player $player) => ['player' => $player, 'score' => 1.0])
            ->values();
    }

    /**
     * @return Collection<int, array{player: Player, score: float}>
     */
    private function fuzzyMatch(string $query, int $limit): Collection
    {
        $results = Player::search($query)->take($limit)->get()->values();
        $total = $results->count();

        if ($total === 0) {
            return collect();
        }

        return $results->map(function (Player $player, int $index) use ($total) {
            // Punteggio decrescente in base al rank restituito dal motore fuzzy;
            // il primo risultato non arriva mai a 1.0 (quello è riservato al match esatto).
            $score = round(0.9 - ($index / max($total, 1)) * 0.4, 2);

            return ['player' => $player, 'score' => max($score, 0.1)];
        });
    }
}

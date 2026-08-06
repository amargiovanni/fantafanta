<?php

namespace App\Services;

use App\Models\Player;
use App\Models\PlayerAlias;
use App\Support\NameNormalizer;
use App\Support\NameSimilarity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Risolve un nome grezzo (come appare in un articolo) nel giocatore canonico.
 *
 * Tre esiti possibili, mai altri:
 *  - `matched`   il nome è attribuibile senza dubbi: viene registrato l'alias
 *                così che la stessa forma non richieda più una ricerca;
 *  - `ambiguous` ci sono candidati ma nessuno abbastanza sicuro: NON si scrive
 *                nulla, decide una persona;
 *  - `not_found` nessun candidato.
 *
 * La regola non negoziabile del briefing §10 è che sotto soglia non si
 * auto-assegna mai: un segnale sul giocatore sbagliato è peggio di un segnale
 * mancante, perché falsa una valutazione senza dare alcun segnale d'allarme.
 */
class PlayerResolver
{
    /** Somiglianza minima per agganciare un nome automaticamente. */
    public const AUTO_MATCH_THRESHOLD = 0.85;

    /** Distacco minimo dal secondo candidato per considerare il primo sicuro. */
    public const AUTO_MATCH_MARGIN = 0.10;

    public function __construct(private readonly PlayerSearch $search) {}

    /**
     * @return array{
     *     status: 'matched'|'ambiguous'|'not_found',
     *     player: ?Player,
     *     similarity: float,
     *     alias_created: bool,
     *     candidates: array<int, array{player_id: int, name: string, role: string, real_team: string|null, similarity: float}>
     * }
     */
    public function resolve(string $rawName, bool $registerAlias = true): array
    {
        $normalized = NameNormalizer::normalize($rawName);

        if ($normalized === '') {
            return $this->outcome('not_found', null, 0.0, false, []);
        }

        $scored = $this->candidates($normalized);

        if ($scored === []) {
            return $this->outcome('not_found', null, 0.0, false, []);
        }

        $best = $scored[0];
        $runnerUp = $scored[1]['similarity'] ?? 0.0;

        $isConfident = $best['similarity'] >= self::AUTO_MATCH_THRESHOLD
            && ($best['similarity'] - $runnerUp) >= self::AUTO_MATCH_MARGIN;

        if (! $isConfident) {
            return $this->outcome('ambiguous', null, $best['similarity'], false, $scored);
        }

        $player = Player::query()->findOrFail($best['player_id']);
        $aliasCreated = $registerAlias && $this->registerAlias($player, $rawName, $normalized);

        return $this->outcome('matched', $player, $best['similarity'], $aliasCreated, $scored);
    }

    /**
     * Candidati ordinati per somiglianza reale al nome normalizzato.
     *
     * PlayerSearch dà il set di candidati (esatti o fuzzy da Meilisearch); qui
     * si ricalcola un punteggio di somiglianza vero sul nome e su tutti i suoi
     * alias, perché il rank di un motore fuzzy non dice quanto due nomi si
     * assomigliano — dice solo quale è arrivato prima.
     *
     * @return array<int, array{player_id: int, name: string, role: string, real_team: string|null, similarity: float}>
     */
    public function candidates(string $normalizedName, int $limit = 10): array
    {
        $ids = $this->search->search($normalizedName, $limit)
            ->map(fn (array $result) => $result['player']->id);

        // Rete di sicurezza indipendente dal motore di ricerca: un nome con i
        // token invertiti ("Lautaro Martinez" contro "MARTINEZ Lautaro") deve
        // essere trovato anche se Meilisearch è giù o se l'indice è freddo.
        // La risoluzione dei nomi non può dipendere da un demone esterno.
        $ids = $ids->merge($this->tokenCandidates($normalizedName, $limit))->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        $players = Player::query()
            ->with('aliases')
            ->whereIn('id', $ids->all())
            ->get();

        return $players
            ->map(function (Player $player) use ($normalizedName) {
                $forms = $player->aliases->pluck('normalized_alias')
                    ->push($player->normalized_name)
                    ->filter()
                    ->unique();

                $similarity = $forms
                    ->map(fn (string $form) => NameSimilarity::score($normalizedName, $form))
                    ->max() ?? 0.0;

                return [
                    'player_id' => $player->id,
                    'name' => $player->name,
                    'role' => $player->role->value,
                    'real_team' => $player->real_team,
                    'similarity' => round((float) $similarity, 4),
                ];
            })
            ->sortByDesc('similarity')
            ->values()
            ->all();
    }

    /**
     * Candidati trovati per singolo token del nome, direttamente su database.
     *
     * Si usano solo i token di almeno tre lettere: un'iniziale come "l"
     * pescherebbe mezzo listone senza dire niente di utile.
     *
     * @return Collection<int, int>
     */
    private function tokenCandidates(string $normalizedName, int $limit): Collection
    {
        $tokens = collect(explode(' ', $normalizedName))
            ->filter(fn (string $token) => mb_strlen($token) >= 3);

        if ($tokens->isEmpty()) {
            return collect();
        }

        $byName = Player::query()
            ->where(function (Builder $query) use ($tokens) {
                foreach ($tokens as $token) {
                    $query->orWhere('normalized_name', 'like', '%'.$token.'%');
                }
            })
            ->limit($limit)
            ->pluck('id');

        $byAlias = PlayerAlias::query()
            ->where(function (Builder $query) use ($tokens) {
                foreach ($tokens as $token) {
                    $query->orWhere('normalized_alias', 'like', '%'.$token.'%');
                }
            })
            ->limit($limit)
            ->pluck('player_id');

        return $byName->merge($byAlias)->unique()->values();
    }

    /**
     * Registra la forma grezza come alias, se non è già nota.
     */
    private function registerAlias(Player $player, string $rawName, string $normalized): bool
    {
        $exists = PlayerAlias::query()
            ->where('player_id', $player->id)
            ->where('normalized_alias', $normalized)
            ->exists();

        if ($exists || $normalized === $player->normalized_name) {
            return false;
        }

        PlayerAlias::query()->create([
            'player_id' => $player->id,
            'alias' => trim($rawName),
        ]);

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    private function outcome(string $status, ?Player $player, float $similarity, bool $aliasCreated, array $candidates): array
    {
        return [
            'status' => $status,
            'player' => $player,
            'similarity' => round($similarity, 4),
            'alias_created' => $aliasCreated,
            'candidates' => $candidates,
        ];
    }
}

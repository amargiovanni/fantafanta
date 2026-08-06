<?php

namespace App\Services;

use App\Models\Acquisition;
use App\Models\Auction;
use App\Models\LeagueConfig;
use App\Models\Team;

/**
 * Fotografia dello stato della lega in un istante: chi ha quanti crediti,
 * quanti slot ancora aperti e in quale ruolo.
 *
 * Esiste per un motivo di prestazioni e uno di correttezza. Di prestazioni:
 * la stessa risposta serve al ValuationEngine, a tre tool MCP e alla
 * dashboard, e va costruita con due query, non con una per squadra. Di
 * correttezza: "slot aperti" e "crediti residui" sono definiti in un punto
 * solo, quindi il tetto di max_bid mostrato in asta e quello validato da
 * save_plan non possono divergere.
 *
 * L'oggetto è immutabile e si ricarica: non tiene lo stato aggiornato da sé.
 */
class LeagueState
{
    /**
     * @param  array<int, array{id: int, name: string, is_mine: bool, credits_total: int, credits_spent: int, credits_remaining: int, acquired_by_role: array<string, int>, acquired_total: int, open_slots_by_role: array<string, int>, open_slots_total: int, credits_per_open_slot: float|null}>  $teams
     */
    private function __construct(
        public readonly LeagueConfig $config,
        public readonly ?Auction $auction,
        public readonly array $teams,
    ) {}

    /**
     * Carica lo stato con due query aggregate, qualunque sia il numero di
     * squadre e di acquisti.
     */
    public static function load(?Auction $auction = null): self
    {
        $config = LeagueConfig::current();
        $auction ??= Auction::current();

        $slots = self::normalizeSlots($config->slots);

        $spent = Acquisition::query()
            ->join('players', 'players.id', '=', 'acquisitions.player_id')
            ->when($auction !== null, fn ($query) => $query->where('acquisitions.auction_id', $auction->id))
            ->groupBy('acquisitions.team_id', 'players.role')
            ->selectRaw('acquisitions.team_id as team_id, players.role as role, count(*) as acquired, sum(acquisitions.price) as spent')
            ->get();

        $teams = [];

        foreach (Team::query()->orderBy('id')->get() as $team) {
            $rows = $spent->where('team_id', $team->id);

            $acquiredByRole = [];
            $openByRole = [];
            $creditsSpent = 0;

            foreach ($slots as $role => $total) {
                $acquired = (int) ($rows->firstWhere('role', $role)->acquired ?? 0);
                $acquiredByRole[$role] = $acquired;
                $openByRole[$role] = max(0, $total - $acquired);
            }

            foreach ($rows as $row) {
                $creditsSpent += (int) $row->spent;
            }

            $openTotal = array_sum($openByRole);
            $creditsRemaining = (int) $team->credits_total - $creditsSpent;

            $teams[$team->id] = [
                'id' => $team->id,
                'name' => $team->name,
                'is_mine' => (bool) $team->is_mine,
                'credits_total' => (int) $team->credits_total,
                'credits_spent' => $creditsSpent,
                'credits_remaining' => $creditsRemaining,
                'acquired_by_role' => $acquiredByRole,
                'acquired_total' => array_sum($acquiredByRole),
                'open_slots_by_role' => $openByRole,
                'open_slots_total' => $openTotal,
                'credits_per_open_slot' => $openTotal > 0 ? round($creditsRemaining / $openTotal, 2) : null,
            ];
        }

        return new self($config, $auction, $teams);
    }

    /**
     * Slot rosa per ruolo, sempre con tutte e quattro le chiavi valorizzate.
     *
     * @return array<string, int>
     */
    public function slots(): array
    {
        return self::normalizeSlots($this->config->slots);
    }

    public function totalSlots(): int
    {
        return array_sum($this->slots());
    }

    /**
     * La mia squadra.
     *
     * Quando non è ancora stata registrata restituisce una squadra virtuale
     * con il budget pieno della lega: prima del setup completo la dashboard
     * deve comunque poter mostrare valutazioni sensate, e un max_bid a zero
     * sarebbe una risposta sbagliata, non prudente.
     *
     * @return array{id: int|null, name: string, is_mine: bool, credits_total: int, credits_spent: int, credits_remaining: int, acquired_by_role: array<string, int>, acquired_total: int, open_slots_by_role: array<string, int>, open_slots_total: int, credits_per_open_slot: float|null}
     */
    public function myTeam(): array
    {
        foreach ($this->teams as $team) {
            if ($team['is_mine']) {
                return $team;
            }
        }

        $slots = $this->slots();

        return [
            'id' => null,
            'name' => 'La mia squadra (non ancora registrata)',
            'is_mine' => true,
            'credits_total' => (int) $this->config->total_credits,
            'credits_spent' => 0,
            'credits_remaining' => (int) $this->config->total_credits,
            'acquired_by_role' => array_map(fn () => 0, $slots),
            'acquired_total' => 0,
            'open_slots_by_role' => $slots,
            'open_slots_total' => array_sum($slots),
            'credits_per_open_slot' => array_sum($slots) > 0
                ? round((int) $this->config->total_credits / array_sum($slots), 2)
                : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function opponents(): array
    {
        return array_values(array_filter($this->teams, fn (array $team) => ! $team['is_mine']));
    }

    /**
     * Domanda avversaria per ruolo: quanti slot ancora aperti hanno gli
     * avversari che possono davvero competere, cioè quelli con crediti medi
     * per slot aperto sopra la mediana.
     *
     * Chi ha già speso quasi tutto continua a partecipare, ma non fa prezzo.
     *
     * @return array<string, int>
     */
    public function opponentDemandByRole(): array
    {
        $solvent = array_values(array_filter(
            $this->opponents(),
            fn (array $team) => $team['open_slots_total'] > 0,
        ));

        $demand = array_map(fn () => 0, $this->slots());

        if ($solvent === []) {
            return $demand;
        }

        $median = self::median(array_map(
            fn (array $team) => (float) $team['credits_per_open_slot'],
            $solvent,
        ));

        foreach ($solvent as $team) {
            if ((float) $team['credits_per_open_slot'] < $median) {
                continue;
            }

            foreach ($team['open_slots_by_role'] as $role => $open) {
                $demand[$role] = ($demand[$role] ?? 0) + $open;
            }
        }

        return $demand;
    }

    /**
     * @param  array<int, float>  $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * @param  array<string, mixed>|null  $slots
     * @return array<string, int>
     */
    private static function normalizeSlots(?array $slots): array
    {
        $normalized = [];

        foreach (LeagueConfig::DEFAULT_SLOTS as $role => $default) {
            $normalized[$role] = (int) ($slots[$role] ?? $default);
        }

        return $normalized;
    }
}

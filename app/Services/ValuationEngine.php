<?php

namespace App\Services;

use App\Enums\PlayerStatus;
use App\Enums\SignalType;
use App\Models\Acquisition;
use App\Models\Auction;
use App\Models\LeagueConfig;
use App\Models\PlanSlot;
use App\Models\Player;
use App\Models\Signal;
use App\Models\Valuation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Il motore di valutazione deterministico (briefing §5).
 *
 * PHP puro: nessuna chiamata AI, nessuna rete, nessuna decisione presa altrove.
 * Dato lo stesso stato del database produce sempre gli stessi numeri, ed è
 * questo che permette di fidarsene durante l'asta, quando non c'è tempo di
 * verificare niente.
 *
 * Ordine del calcolo, che è anche l'ordine dei metodi privati qui sotto:
 *
 *   1. base value      — listone (FVM, quotazione, statistiche) scalato sul
 *                        monte crediti reale della lega;
 *   2. segnali         — somma pesata degli impact attivi, con decadimento
 *                        temporale e alcuni casi speciali che non sono "un po'
 *                        di peso in più" ma interruttori (infortunio lungo,
 *                        cessione all'estero);
 *   3. modificatori    — difesa e fairplay, se la lega li ha attivi;
 *   4. inflazione live — quanto si sta pagando davvero in quel ruolo;
 *   5. scarsità        — domanda avversaria contro offerta residua;
 *   6. vincolo budget  — il tetto aritmetico oltre cui non posso offrire.
 *
 * Il ricalcolo è sempre totale: 600 giocatori con quattro query e nessun N+1
 * costano meno di un ricalcolo incrementale che si può sbagliare.
 */
class ValuationEngine
{
    /**
     * Ricalcola tutto il listone e persiste il risultato in `valuations`.
     *
     * @return int Numero di valutazioni scritte.
     */
    public function recompute(?Auction $auction = null): int
    {
        $rows = $this->compute($auction);

        if ($rows === []) {
            return 0;
        }

        $now = Carbon::now();

        $records = array_map(fn (array $row) => [
            'player_id' => $row['player_id'],
            'base_value' => $row['base_value'],
            'adjusted_value' => $row['adjusted_value'],
            'max_bid' => $row['max_bid'],
            'tier' => $row['tier'],
            'scarcity_index' => $row['scarcity_index'],
            'computed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        // A blocchi: un upsert unico da 600 righe supererebbe il tetto di
        // parametri di SQLite, e la sala d'asta gira su SQLite.
        foreach (array_chunk($records, 200) as $chunk) {
            Valuation::query()->upsert(
                $chunk,
                ['player_id'],
                ['base_value', 'adjusted_value', 'max_bid', 'tier', 'scarcity_index', 'computed_at', 'updated_at'],
            );
        }

        return count($records);
    }

    /**
     * Calcola le valutazioni senza scrivere niente: è la forma testabile del
     * motore, e quella che la dashboard potrebbe usare per un'anteprima.
     *
     * @return array<int, array{player_id: int, base_value: float, adjusted_value: float, max_bid: int, tier: int, scarcity_index: float}>
     */
    public function compute(?Auction $auction = null): array
    {
        $state = LeagueState::load($auction);

        /** @var Collection<int, Player> $players */
        $players = Player::query()
            ->orderBy('id')
            ->get(['id', 'role', 'status', 'quotazione', 'fvm', 'season_stats', 'is_rigorista', 'expected_starter']);

        if ($players->isEmpty()) {
            return [];
        }

        $signals = $this->activeSignalsByPlayer();
        $planned = $this->plannedPlayerIds($state);

        $base = $this->baseValues($players, $state);

        $adjusted = [];
        foreach ($players as $player) {
            $adjusted[$player->id] = $this->adjustedValue(
                $player,
                $base[$player->id],
                $signals[$player->id] ?? [],
                $state->config,
            );
        }

        $tiers = $this->tiers($players, $adjusted);
        $inflation = array_map(
            fn (array $role) => $role['effective'],
            $this->inflationReport($players, $adjusted, $state),
        );
        $scarcity = $this->scarcityIndexes($players, $tiers, $state);

        $rows = [];

        foreach ($players as $player) {
            $role = $player->role->value;

            $rows[] = [
                'player_id' => (int) $player->id,
                'base_value' => $base[$player->id],
                'adjusted_value' => $adjusted[$player->id],
                'max_bid' => $this->maxBid(
                    $player,
                    $adjusted[$player->id],
                    $inflation[$role] ?? 1.0,
                    $scarcity[$player->id],
                    in_array((int) $player->id, $planned, true),
                    $state,
                ),
                'tier' => $tiers[$player->id],
                'scarcity_index' => $scarcity[$player->id],
            ];
        }

        return $rows;
    }

    /**
     * 1. Base value.
     *
     * Il monte crediti della lega si divide fra i reparti secondo le quote di
     * config, e dentro il reparto in proporzione al punteggio grezzo dei soli
     * giocatori "comprabili" — i primi teams_count × slot_ruolo. Chi resta
     * fuori riceve lo stesso valore per credito di punteggio, quindi un valore
     * più basso ma coerente con la sua forza: sono i tappabuchi, e all'asta
     * costano comunque almeno un credito.
     *
     * @param  Collection<int, Player>  $players
     * @return array<int, float>
     */
    private function baseValues(Collection $players, LeagueState $state): array
    {
        $floor = (float) config('valuation.base.floor');
        $weights = config('valuation.base.weights');
        $shares = config($state->config->modifier_defense
            ? 'valuation.pool_share.with_defense_modifier'
            : 'valuation.pool_share.without_defense_modifier');

        $creditPool = (int) $state->config->teams_count * (int) $state->config->total_credits;
        $slots = $state->slots();

        $base = [];

        foreach ($players->groupBy(fn (Player $player) => $player->role->value) as $role => $group) {
            $maxFvm = (float) $group->max('fvm');
            $maxQuotazione = (float) $group->max('quotazione');

            $raw = [];
            foreach ($group as $player) {
                $fvmNorm = $maxFvm > 0 ? (float) $player->fvm / $maxFvm : 0.0;
                $quotazioneNorm = $maxQuotazione > 0 ? (float) $player->quotazione / $maxQuotazione : 0.0;

                $raw[$player->id] = $weights['fvm'] * $fvmNorm
                    + $weights['quotazione'] * $quotazioneNorm
                    + $weights['performance'] * $this->performanceScore($player, $quotazioneNorm);
            }

            arsort($raw);

            $buyable = (int) $state->config->teams_count * (int) ($slots[$role] ?? 0);
            $topSum = array_sum(array_slice($raw, 0, max(1, $buyable), true));
            $rolePool = $creditPool * (float) ($shares[$role] ?? 0);
            $rate = $topSum > 0 ? $rolePool / $topSum : 0.0;

            foreach ($raw as $playerId => $score) {
                $base[$playerId] = max($floor, round($score * $rate, 2));
            }
        }

        return $base;
    }

    /**
     * perf_norm: quanto ha reso davvero, fra 0 e 1, pesato sulle presenze.
     * Senza statistiche non si inventa niente: si usa la quotazione come
     * proxy, che è esattamente il significato della quotazione.
     */
    private function performanceScore(Player $player, float $quotazioneNorm): float
    {
        $fantamedia = $this->stat($player, 'fantamedia');
        $appearances = $this->stat($player, 'appearances');

        if ($fantamedia === null) {
            return $quotazioneNorm;
        }

        $settings = config('valuation.base.performance');

        $quality = ($fantamedia - $settings['fantamedia_floor']) / $settings['fantamedia_span'];
        $quality = max(0.0, min(1.0, $quality));

        $volume = min(($appearances ?? 0) / $settings['appearances_full'], 1.0);

        return $quality * $volume;
    }

    /**
     * 2. e 3. Segnali attivi e modificatori di lega.
     *
     * @param  array<int, Signal>  $signals
     */
    private function adjustedValue(Player $player, float $base, array $signals, LeagueConfig $config): float
    {
        $floor = (float) config('valuation.base.floor');
        $settings = config('valuation.signals');

        // Caso limite che non è un peso ma un fatto: chi lascia la Serie A non
        // vale niente in questa asta, per quanto forte sia.
        foreach ($signals as $signal) {
            if ($signal->type === SignalType::MercatoOut
                && (float) $signal->confidence >= $settings['market_out']['min_confidence']) {
                return (float) $settings['market_out']['value'];
            }
        }

        $multiplier = 1.0;
        $weightSum = 0.0;
        $isPenaltyTaker = (bool) $player->is_rigorista;

        foreach ($signals as $signal) {
            if ($signal->type === SignalType::Rigorista && $signal->impact > 0) {
                $isPenaltyTaker = true;
            }

            if ($signal->type === SignalType::Infortunio) {
                $months = $this->injuryMonths($signal);

                if ($months !== null && $months >= $settings['injury']['long_months']) {
                    $multiplier *= (float) $settings['injury']['long_multiplier'];

                    continue;
                }

                if ($months !== null && $months >= $settings['injury']['medium_months']) {
                    $multiplier *= (float) $settings['injury']['medium_multiplier'];

                    continue;
                }
            }

            $weightSum += $signal->impact / 2 * (float) $signal->confidence * $this->decay($signal);
        }

        if ($isPenaltyTaker && in_array($player->role->value, $settings['penalty_taker']['roles'], true)) {
            $multiplier *= 1 + (float) $settings['penalty_taker']['bonus'];
        }

        $clamped = max((float) $settings['sum_clamp']['min'], min((float) $settings['sum_clamp']['max'], $weightSum));

        $adjusted = $base * $multiplier * (1 + $clamped);

        // Titolarità attesa: i voti che non prende non li porta.
        $starter = config('valuation.expected_starter');
        $adjusted *= $starter['floor'] + $starter['span'] * (float) $player->expected_starter;

        $adjusted = $this->applyLeagueModifiers($player, $adjusted, $config);

        return max($floor, round($adjusted, 2));
    }

    /**
     * Decadimento temporale del peso di un segnale.
     *
     * I segnali pre-asta invecchiano lentamente e non si azzerano mai del
     * tutto: un infortunio di tre mesi fa può essere ancora in corso.
     */
    private function decay(Signal $signal): float
    {
        $settings = config('valuation.signals');

        $reference = $signal->event_date ?? $signal->created_at;

        if ($reference === null) {
            return 1.0;
        }

        $days = max(0, Carbon::now()->startOfDay()->diffInDays($reference->copy()->startOfDay(), absolute: true));

        // Un evento datato nel futuro (un rientro annunciato) vale pieno.
        if ($reference->greaterThan(Carbon::now())) {
            $days = 0;
        }

        return max((float) $settings['decay_floor'], 1 - $days / (float) $settings['decay_days']);
    }

    /**
     * Durata stimata di uno stop, in mesi, letta dal payload del segnale.
     * Restituisce null quando la fonte non l'ha detta: in quel caso lo stop
     * pesa con la formula normale, senza scorciatoie.
     */
    private function injuryMonths(Signal $signal): ?float
    {
        $payload = $signal->payload ?? [];
        $settings = config('valuation.signals.injury');

        foreach ($settings['duration_days_keys'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return (float) $payload[$key] / (float) $settings['days_per_month'];
            }
        }

        foreach ($settings['duration_months_keys'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                return (float) $payload[$key];
            }
        }

        return null;
    }

    private function applyLeagueModifiers(Player $player, float $adjusted, LeagueConfig $config): float
    {
        $modifiers = config('valuation.modifiers');

        if ($config->modifier_defense && in_array($player->role->value, $modifiers['defense']['roles'], true)) {
            $mediaVoto = $this->stat($player, 'media_voto');

            if ($mediaVoto !== null
                && $mediaVoto >= $modifiers['defense']['min_media_voto']
                && (float) $player->expected_starter >= $modifiers['defense']['min_expected_starter']) {
                $bonus = $modifiers['defense']['base_bonus']
                    + $modifiers['defense']['step_bonus'] * ($mediaVoto - $modifiers['defense']['min_media_voto']) / $modifiers['defense']['step_size'];

                $adjusted *= 1 + min((float) $modifiers['defense']['cap'], $bonus);
            }
        }

        if ($config->modifier_fairplay) {
            $appearances = $this->stat($player, 'appearances');
            $bookings = $this->stat($player, 'bookings');

            if ($appearances !== null && $appearances > 0 && $bookings !== null
                && $bookings / $appearances >= $modifiers['fairplay']['bookings_per_appearance']) {
                $adjusted *= (float) $modifiers['fairplay']['multiplier'];
            }
        }

        return $adjusted;
    }

    /**
     * L'inflazione per ruolo così com'è adesso, leggendo le valutazioni già
     * persistite: è la forma che serve al tool MCP get_budget_analysis, che
     * deve raccontare il mercato senza ricalcolare tutto il listone.
     *
     * @return array<string, array{acquisitions: int, paid: int, expected: float, raw: float, effective: float}>
     */
    public function inflationForRoles(?Auction $auction = null): array
    {
        $state = LeagueState::load($auction);

        /** @var Collection<int, Player> $players */
        $players = Player::query()->orderBy('id')->get(['id', 'role']);

        $adjusted = Valuation::query()
            ->pluck('adjusted_value', 'player_id')
            ->map(fn ($value) => (float) $value)
            ->all();

        return $this->inflationReport($players, $adjusted, $state);
    }

    /**
     * 4. Inflazione per ruolo, grezza e ammortizzata.
     *
     * @param  Collection<int, Player>  $players
     * @param  array<int, float>  $adjusted
     * @return array<string, array{acquisitions: int, paid: int, expected: float, raw: float, effective: float}>
     */
    private function inflationReport(Collection $players, array $adjusted, LeagueState $state): array
    {
        $settings = config('valuation.inflation');
        $roles = array_keys($state->slots());

        $roleOf = [];
        foreach ($players as $player) {
            $roleOf[$player->id] = $player->role->value;
        }

        $acquisitions = Acquisition::query()
            ->when($state->auction !== null, fn ($query) => $query->where('auction_id', $state->auction->id))
            ->get(['id', 'player_id', 'price', 'valuation_at_purchase']);

        $paid = array_fill_keys($roles, 0.0);
        $expected = array_fill_keys($roles, 0.0);
        $counts = array_fill_keys($roles, 0);

        foreach ($acquisitions as $acquisition) {
            $role = $roleOf[$acquisition->player_id] ?? null;

            if ($role === null) {
                continue;
            }

            // Valore al momento dell'acquisto quando c'è; altrimenti quello
            // corrente, che è la migliore approssimazione disponibile.
            $reference = $acquisition->valuation_at_purchase ?? ($adjusted[$acquisition->player_id] ?? null);

            if ($reference === null || $reference <= 0) {
                continue;
            }

            $paid[$role] += (int) $acquisition->price;
            $expected[$role] += (float) $reference;
            $counts[$role]++;
        }

        $report = [];

        foreach ($roles as $role) {
            $enough = $counts[$role] >= $settings['min_acquisitions'] && $expected[$role] > 0;

            $ratio = $enough ? $paid[$role] / $expected[$role] : 1.0;
            $ratio = max((float) $settings['clamp']['min'], min((float) $settings['clamp']['max'], $ratio));

            $report[$role] = [
                'acquisitions' => $counts[$role],
                'paid' => (int) $paid[$role],
                'expected' => round($expected[$role], 2),
                'raw' => $enough ? round($ratio, 3) : 1.0,

                // Ammortizzata: inseguire i picchi dei primi acquisti è il modo
                // classico di restare senza crediti a metà asta.
                'effective' => $enough ? round(1 + ($ratio - 1) * (float) $settings['damping'], 3) : 1.0,
            ];
        }

        return $report;
    }

    /**
     * 5a. Tier: quintili di adjusted_value dentro il ruolo, calcolati sui soli
     * giocatori ancora disponibili — è la scala che conta in asta.
     *
     * Chi è già stato assegnato riceve comunque il tier che gli spetterebbe su
     * quella scala, così lo storico resta leggibile.
     *
     * @param  Collection<int, Player>  $players
     * @param  array<int, float>  $adjusted
     * @return array<int, int>
     */
    private function tiers(Collection $players, array $adjusted): array
    {
        $tierCount = (int) config('valuation.scarcity.tiers');
        $tiers = [];

        foreach ($players->groupBy(fn (Player $player) => $player->role->value) as $group) {
            $available = $group->filter(fn (Player $player) => $player->status === PlayerStatus::Available);
            $scale = $available->isNotEmpty() ? $available : $group;

            $values = $scale->map(fn (Player $player) => $adjusted[$player->id])->values()->all();
            rsort($values);

            $bucket = max(1, (int) ceil(count($values) / $tierCount));

            // Valore minimo di ciascun tier: la soglia per entrarci.
            $thresholds = [];
            foreach ($values as $index => $value) {
                $tier = min($tierCount, intdiv($index, $bucket) + 1);
                $thresholds[$tier] = $value;
            }

            foreach ($group as $player) {
                $tiers[$player->id] = $tierCount;

                for ($tier = 1; $tier <= $tierCount; $tier++) {
                    if (isset($thresholds[$tier]) && $adjusted[$player->id] >= $thresholds[$tier]) {
                        $tiers[$player->id] = $tier;

                        break;
                    }
                }
            }
        }

        return $tiers;
    }

    /**
     * 5b. Scarsità: quanti slot aperti hanno gli avversari che possono ancora
     * competere, contro quanti giocatori di pari livello o migliori restano.
     *
     * @param  Collection<int, Player>  $players
     * @param  array<int, int>  $tiers
     * @return array<int, float>
     */
    private function scarcityIndexes(Collection $players, array $tiers, LeagueState $state): array
    {
        $clamp = config('valuation.scarcity.clamp');
        $demand = $state->opponentDemandByRole();

        // Offerta cumulata: quanti disponibili di tier <= t, per ruolo.
        $supply = [];
        foreach ($players as $player) {
            if ($player->status !== PlayerStatus::Available) {
                continue;
            }

            $role = $player->role->value;
            $supply[$role][$tiers[$player->id]] = ($supply[$role][$tiers[$player->id]] ?? 0) + 1;
        }

        $indexes = [];

        foreach ($players as $player) {
            $role = $player->role->value;
            $tier = $tiers[$player->id];

            $offer = 0;
            for ($t = 1; $t <= $tier; $t++) {
                $offer += $supply[$role][$t] ?? 0;
            }

            $index = $offer > 0
                ? ($demand[$role] ?? 0) / $offer
                : (float) $clamp['max'];

            $indexes[$player->id] = round(max((float) $clamp['min'], min((float) $clamp['max'], $index)), 2);
        }

        return $indexes;
    }

    /**
     * 6. max_bid: valore corrente, corretto per inflazione e scarsità, tagliato
     * dal tetto di budget.
     *
     * Il tetto non è una preferenza: se resto con 10 crediti e 6 slot aperti,
     * il massimo che posso offrire è 5, perché gli altri cinque slot costano
     * almeno un credito ciascuno.
     */
    private function maxBid(
        Player $player,
        float $adjusted,
        float $inflation,
        float $scarcity,
        bool $isPlanned,
        LeagueState $state,
    ): int {
        if ($player->status !== PlayerStatus::Available) {
            return 0;
        }

        $me = $state->myTeam();
        $role = $player->role->value;

        if (($me['open_slots_by_role'][$role] ?? 0) < 1) {
            return 0;
        }

        $minSlotCost = (int) config('valuation.max_bid.min_slot_cost');
        $cap = $me['credits_remaining'] - ($me['open_slots_total'] - 1) * $minSlotCost;

        if ($cap < $minSlotCost) {
            return 0;
        }

        $bonusFactor = (float) config('valuation.scarcity.max_bid_bonus_factor');
        $bonus = $isPlanned ? 1 + $bonusFactor * max(0.0, min(1.0, $scarcity - 1)) : 1.0;

        $raw = (int) floor($adjusted * $inflation * $bonus);

        return max($minSlotCost, min($raw, $cap));
    }

    /**
     * Segnali che pesano: attivi (non superati) e già attribuiti a un
     * giocatore. Quelli in attesa di revisione non muovono un solo credito
     * finché una persona non li conferma.
     *
     * @return array<int, array<int, Signal>>
     */
    private function activeSignalsByPlayer(): array
    {
        $grouped = [];

        $signals = Signal::query()
            ->active()
            ->where('needs_review', false)
            ->whereNotNull('player_id')
            ->get(['id', 'player_id', 'type', 'payload', 'confidence', 'impact', 'event_date', 'created_at']);

        foreach ($signals as $signal) {
            $grouped[$signal->player_id][] = $signal;
        }

        return $grouped;
    }

    /**
     * Giocatori che il piano corrente insegue, titolari e alternative: sono i
     * soli a cui si applica il bonus di scarsità.
     *
     * @return array<int, int>
     */
    private function plannedPlayerIds(LeagueState $state): array
    {
        $plan = $state->auction?->latestReadyPlan();

        if ($plan === null) {
            return [];
        }

        $ids = [];

        foreach (PlanSlot::query()->where('plan_id', $plan->id)->get(['id', 'plan_id', 'player_id', 'alternatives']) as $slot) {
            $ids = array_merge($ids, $slot->involvedPlayerIds());
        }

        return array_values(array_unique($ids));
    }

    /**
     * Legge una statistica di stagione dal JSON del listone, provando le
     * chiavi note nell'ordine e ignorando maiuscole/minuscole: il nome della
     * colonna dipende dal CSV importato (briefing §10).
     */
    private function stat(Player $player, string $name): ?float
    {
        $stats = $player->season_stats;

        if (! is_array($stats) || $stats === []) {
            return null;
        }

        $lowered = [];
        foreach ($stats as $key => $value) {
            $lowered[mb_strtolower((string) $key)] = $value;
        }

        foreach (config('valuation.stats_keys.'.$name) as $candidate) {
            $key = mb_strtolower($candidate);

            if (! array_key_exists($key, $lowered)) {
                continue;
            }

            $value = $lowered[$key];

            if ($value === null || $value === '') {
                continue;
            }

            // Il CSV italiano usa la virgola come separatore decimale.
            $value = is_string($value) ? str_replace(',', '.', $value) : $value;

            if (! is_numeric($value)) {
                continue;
            }

            return (float) $value;
        }

        return null;
    }
}

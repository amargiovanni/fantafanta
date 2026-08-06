<?php

namespace App\Services;

use App\Enums\PlanStatus;
use App\Enums\PlanTrigger;
use App\Enums\PlayerRole;
use App\Enums\PlayerStatus;
use App\Enums\SlotStatus;
use App\Exceptions\PlanValidationException;
use App\Jobs\RecomputeValuations;
use App\Models\Acquisition;
use App\Models\Auction;
use App\Models\Plan;
use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Unico punto di scrittura del piano d'acquisto.
 *
 * Il piano è uno stato di cui l'asta si fida senza riverificarlo: se contiene
 * un giocatore già venduto, un ruolo sbagliato o un budget sforato, l'errore
 * si scopre mentre l'asta è in corso e non c'è tempo di rimediare. Per questo
 * la validazione sta qui e non nel prompt: un prompt è un auspicio, un
 * controllo server-side è un vincolo (briefing §6).
 *
 * Le regole applicate, nell'ordine in cui sono elencate nella dottrina:
 *
 *   1. numero di slot totali e per ruolo esattamente uguale alla config;
 *   2. nessun giocatore titolare due volte, e nessuna alternativa che sia
 *      titolare di un altro slot (essere alternativa di più slot invece è
 *      legittimo: quel giocatore serve comunque);
 *   3. il titolare deve essere disponibile — con l'eccezione dei giocatori
 *      già miei, che DEVONO stare nel loro slot al prezzo pagato;
 *   4. ruolo del giocatore uguale a quello dello slot, alternative comprese;
 *   5. almeno due alternative per ogni slot ancora da prendere;
 *   6. somma dei target_price degli slot aperti entro i crediti residui,
 *      target_price ≥ 1 e max_price ≥ target_price;
 *   7. il vincolo "ogni slot deve poter costare almeno 1 credito" non ha una
 *      regola propria: è già implicato dal punto 6, perché ogni slot aperto
 *      porta con sé un target_price di almeno 1 dentro la stessa somma.
 *
 * L'esito è tutto o niente: o il piano entra intero, o non entra e il
 * chiamante riceve l'elenco completo di cosa correggere.
 */
class PlanWriter
{
    /** Massimo di righe ammesse nelle note strategiche (briefing §7.3). */
    private const MAX_STRATEGY_LINES = 3;

    /**
     * Scrive una nuova versione del piano.
     *
     * @param  array<int, array<string, mixed>>  $slots
     *
     * @throws PlanValidationException
     */
    public function save(
        Auction $auction,
        array $slots,
        ?string $strategyNotes = null,
        PlanTrigger $trigger = PlanTrigger::Initial,
    ): Plan {
        $errors = $this->validate($auction, $slots, $strategyNotes);

        if ($errors !== []) {
            throw new PlanValidationException($errors);
        }

        $mine = $this->myAcquisitions($auction);

        $plan = DB::transaction(function () use ($auction, $slots, $strategyNotes, $trigger, $mine) {
            $version = (int) Plan::query()->where('auction_id', $auction->id)->max('version') + 1;

            $plan = Plan::query()->create([
                'auction_id' => $auction->id,
                'version' => $version,
                'trigger' => $trigger,
                'status' => PlanStatus::Ready,
                'strategy_notes' => $strategyNotes !== null ? trim($strategyNotes) : null,
                'budget_summary' => $this->budgetSummary($slots, $mine),
            ]);

            foreach ($slots as $slot) {
                $playerId = (int) $slot['player_id'];

                $plan->slots()->create([
                    'role' => (string) $slot['role'],
                    'slot_index' => (int) $slot['slot_index'],
                    'player_id' => $playerId,
                    'target_price' => (int) $slot['target_price'],
                    'max_price' => (int) $slot['max_price'],
                    'alternatives' => $this->normalizeAlternatives($slot['alternatives'] ?? []),
                    'slot_status' => isset($mine[$playerId]) ? SlotStatus::Acquired : SlotStatus::Pending,
                ]);
            }

            return $plan;
        });

        // I giocatori del piano prendono il bonus di scarsità sul max_bid
        // (briefing §5.5): un piano nuovo cambia le valutazioni.
        RecomputeValuations::dispatch($auction->id)->afterCommit();

        return $plan->load('slots');
    }

    /**
     * Valida senza scrivere nulla, restituendo TUTTE le violazioni trovate.
     *
     * @param  array<int, array<string, mixed>>  $slots
     * @return array<int, string>
     */
    public function validate(Auction $auction, array $slots, ?string $strategyNotes = null): array
    {
        $state = LeagueState::load($auction);
        $configuredSlots = $state->slots();

        $errors = $this->validateShape($slots, $configuredSlots);

        if ($errors !== []) {
            // Senza una forma valida ogni controllo successivo produrrebbe
            // errori a cascata che nascondono quello vero.
            return $errors;
        }

        $players = $this->loadPlayers($slots);
        $mine = $this->myAcquisitions($auction);
        $ownerOf = $this->ownersByPlayer($auction);

        $errors = array_merge(
            $errors,
            $this->validateStrategyNotes($strategyNotes),
            $this->validateSlots($slots, $players, $mine, $ownerOf),
            $this->validateUniqueness($slots),
            $this->validateMyAcquiredArePresent($slots, $mine, $players),
            $this->validateBudget($slots, $mine, $state),
        );

        return array_values(array_unique($errors));
    }

    /**
     * 1. Conteggi: totale e per ruolo, con slot_index 1..N senza buchi.
     *
     * @param  array<int, array<string, mixed>>  $slots
     * @param  array<string, int>  $configured
     * @return array<int, string>
     */
    private function validateShape(array $slots, array $configured): array
    {
        $errors = [];
        $expectedTotal = array_sum($configured);

        if (count($slots) !== $expectedTotal) {
            $errors[] = sprintf(
                'Il piano ha %d slot invece dei %d previsti dalla configurazione della lega (%s).',
                count($slots),
                $expectedTotal,
                implode(', ', array_map(fn ($role, $n) => "{$role}:{$n}", array_keys($configured), $configured)),
            );
        }

        $byRole = [];

        foreach (array_values($slots) as $index => $slot) {
            if (! is_array($slot)) {
                $errors[] = sprintf('slot #%d: struttura non valida, atteso un oggetto.', $index + 1);

                continue;
            }

            $role = is_string($slot['role'] ?? null) ? mb_strtoupper($slot['role']) : null;

            if ($role === null || PlayerRole::tryFrom($role) === null) {
                $errors[] = sprintf(
                    'slot #%d: ruolo "%s" non valido. Ammessi: %s.',
                    $index + 1,
                    is_scalar($slot['role'] ?? null) ? (string) $slot['role'] : 'assente',
                    implode(', ', array_column(PlayerRole::cases(), 'value')),
                );

                continue;
            }

            $byRole[$role][] = (int) ($slot['slot_index'] ?? 0);
        }

        foreach ($configured as $role => $expected) {
            $indexes = $byRole[$role] ?? [];

            if (count($indexes) !== $expected) {
                $errors[] = sprintf(
                    'Ruolo %s: %d slot invece di %d.',
                    $role,
                    count($indexes),
                    $expected,
                );

                continue;
            }

            sort($indexes);

            if ($indexes !== range(1, $expected)) {
                $errors[] = sprintf(
                    'Ruolo %s: gli slot_index devono essere da 1 a %d, una volta ciascuno (trovati: %s).',
                    $role,
                    $expected,
                    implode(', ', $indexes),
                );
            }
        }

        foreach (array_keys($byRole) as $role) {
            if (! array_key_exists($role, $configured)) {
                $errors[] = sprintf('Ruolo %s: non previsto dalla configurazione della lega.', $role);
            }
        }

        return $errors;
    }

    /**
     * @return array<int, string>
     */
    private function validateStrategyNotes(?string $notes): array
    {
        if ($notes === null || trim($notes) === '') {
            return ['strategy_notes: obbligatorie, 2-3 righe sul razionale della versione.'];
        }

        $lines = preg_split('/\R/', trim($notes)) ?: [];
        $lines = array_values(array_filter($lines, fn (string $line) => trim($line) !== ''));

        if (count($lines) > self::MAX_STRATEGY_LINES) {
            return [sprintf(
                'strategy_notes: massimo %d righe, trovate %d. Il piano è la risposta, non il testo.',
                self::MAX_STRATEGY_LINES,
                count($lines),
            )];
        }

        return [];
    }

    /**
     * 3, 4, 5, 6: tutto quello che si giudica slot per slot.
     *
     * @param  array<int, array<string, mixed>>  $slots
     * @param  Collection<int, Player>  $players
     * @param  array<int, int>  $mine
     * @param  array<int, string>  $ownerOf
     * @return array<int, string>
     */
    private function validateSlots(array $slots, Collection $players, array $mine, array $ownerOf): array
    {
        $errors = [];

        foreach ($slots as $slot) {
            $label = $this->label($slot);
            $playerId = (int) ($slot['player_id'] ?? 0);
            $player = $players->get($playerId);

            if ($player === null) {
                $errors[] = sprintf('%s: il giocatore %d non esiste nel listone.', $label, $playerId);

                continue;
            }

            $role = mb_strtoupper((string) $slot['role']);

            if ($player->role->value !== $role) {
                $errors[] = sprintf(
                    '%s: %s è un %s, non può occupare uno slot %s.',
                    $label,
                    $player->name,
                    $player->role->value,
                    $role,
                );
            }

            $isMine = isset($mine[$playerId]);

            if (! $isMine && $player->status !== PlayerStatus::Available) {
                $errors[] = $player->status === PlayerStatus::Acquired
                    ? sprintf(
                        '%s: %s è già stato aggiudicato a %s, non può essere il titolare di uno slot.',
                        $label,
                        $player->name,
                        $ownerOf[$playerId] ?? 'un\'altra squadra',
                    )
                    : sprintf('%s: %s non è più nel listone (status %s).', $label, $player->name, $player->status->value);
            }

            $targetPrice = (int) ($slot['target_price'] ?? 0);
            $maxPrice = (int) ($slot['max_price'] ?? 0);

            if ($targetPrice < 1) {
                $errors[] = sprintf('%s: target_price %d non ammesso, ogni slot costa almeno 1 credito.', $label, $targetPrice);
            }

            if ($maxPrice < $targetPrice) {
                $errors[] = sprintf('%s: max_price %d è inferiore al target_price %d.', $label, $maxPrice, $targetPrice);
            }

            if ($isMine) {
                if ($targetPrice !== $mine[$playerId]) {
                    $errors[] = sprintf(
                        '%s: %s è già mio, pagato %d crediti: target_price deve valere esattamente %d (trovato %d).',
                        $label,
                        $player->name,
                        $mine[$playerId],
                        $mine[$playerId],
                        $targetPrice,
                    );
                }

                // Uno slot già preso non ha bisogno di alternative: il posto è occupato.
                continue;
            }

            $errors = array_merge($errors, $this->validateAlternatives($slot, $label, $role, $players, $ownerOf, $maxPrice));
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $slot
     * @param  Collection<int, Player>  $players
     * @param  array<int, string>  $ownerOf
     * @return array<int, string>
     */
    private function validateAlternatives(
        array $slot,
        string $label,
        string $role,
        Collection $players,
        array $ownerOf,
        int $maxPrice,
    ): array {
        $errors = [];
        $alternatives = is_array($slot['alternatives'] ?? null) ? array_values($slot['alternatives']) : [];

        if (count($alternatives) < 2) {
            $errors[] = sprintf(
                '%s: servono almeno 2 alternative (trovate %d). L\'asta è random: il titolare può sfumare in qualsiasi momento.',
                $label,
                count($alternatives),
            );
        }

        $seen = [];

        foreach ($alternatives as $position => $alternative) {
            $position++;

            if (! is_array($alternative) || ! isset($alternative['player_id'])) {
                $errors[] = sprintf('%s: alternativa #%d malformata, attesi player_id e target_price.', $label, $position);

                continue;
            }

            $alternativeId = (int) $alternative['player_id'];
            $alternativePlayer = $players->get($alternativeId);

            if ($alternativePlayer === null) {
                $errors[] = sprintf('%s: l\'alternativa #%d punta al giocatore %d, che non esiste nel listone.', $label, $position, $alternativeId);

                continue;
            }

            if (isset($seen[$alternativeId])) {
                $errors[] = sprintf('%s: %s compare due volte fra le alternative dello stesso slot.', $label, $alternativePlayer->name);
            }

            $seen[$alternativeId] = true;

            if ($alternativeId === (int) $slot['player_id']) {
                $errors[] = sprintf('%s: %s è già il titolare dello slot, non può esserne anche l\'alternativa.', $label, $alternativePlayer->name);
            }

            if ($alternativePlayer->role->value !== $role) {
                $errors[] = sprintf(
                    '%s: l\'alternativa %s è un %s, lo slot è %s.',
                    $label,
                    $alternativePlayer->name,
                    $alternativePlayer->role->value,
                    $role,
                );
            }

            if ($alternativePlayer->status !== PlayerStatus::Available) {
                $errors[] = sprintf(
                    '%s: l\'alternativa %s non è disponibile (%s).',
                    $label,
                    $alternativePlayer->name,
                    $alternativePlayer->status === PlayerStatus::Acquired
                        ? 'già aggiudicata a '.($ownerOf[$alternativeId] ?? 'un\'altra squadra')
                        : 'fuori listone',
                );
            }

            $alternativePrice = (int) ($alternative['target_price'] ?? 0);

            if ($alternativePrice < 1) {
                $errors[] = sprintf('%s: l\'alternativa %s ha target_price %d, il minimo è 1.', $label, $alternativePlayer->name, $alternativePrice);
            }

            if ($alternativePrice > $maxPrice) {
                $errors[] = sprintf(
                    '%s: l\'alternativa %s costa %d, più del max_price %d dello slot: un ripiego non può costare più del titolare.',
                    $label,
                    $alternativePlayer->name,
                    $alternativePrice,
                    $maxPrice,
                );
            }
        }

        return $errors;
    }

    /**
     * 2. Unicità: titolare una volta sola, e mai titolare qui e alternativa
     * altrove. Essere alternativa di più slot dello stesso ruolo è invece
     * corretto e voluto.
     *
     * @param  array<int, array<string, mixed>>  $slots
     * @return array<int, string>
     */
    private function validateUniqueness(array $slots): array
    {
        $errors = [];
        $starters = [];

        foreach ($slots as $slot) {
            $starters[(int) ($slot['player_id'] ?? 0)][] = $this->label($slot);
        }

        foreach ($starters as $playerId => $labels) {
            if (count($labels) > 1) {
                $errors[] = sprintf(
                    'Il giocatore %d è titolare di più slot (%s): si può prendere una volta sola.',
                    $playerId,
                    implode(', ', $labels),
                );
            }
        }

        foreach ($slots as $slot) {
            $label = $this->label($slot);

            foreach (is_array($slot['alternatives'] ?? null) ? $slot['alternatives'] : [] as $alternative) {
                if (! is_array($alternative) || ! isset($alternative['player_id'])) {
                    continue;
                }

                $alternativeId = (int) $alternative['player_id'];

                if (isset($starters[$alternativeId]) && $starters[$alternativeId] !== [$label]) {
                    $errors[] = sprintf(
                        '%s: il giocatore %d è già titolare dello slot %s, non può essere alternativa di un altro slot.',
                        $label,
                        $alternativeId,
                        implode(', ', $starters[$alternativeId]),
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * I giocatori già miei devono stare tutti nel piano, uno slot ciascuno:
     * un piano che li dimentica proporrebbe di ricomprare una rosa che ho già.
     *
     * @param  array<int, array<string, mixed>>  $slots
     * @param  array<int, int>  $mine
     * @param  Collection<int, Player>  $players
     * @return array<int, string>
     */
    private function validateMyAcquiredArePresent(array $slots, array $mine, Collection $players): array
    {
        if ($mine === []) {
            return [];
        }

        $starters = array_map(fn (array $slot) => (int) ($slot['player_id'] ?? 0), $slots);
        $errors = [];

        foreach ($mine as $playerId => $price) {
            if (in_array($playerId, $starters, true)) {
                continue;
            }

            $errors[] = sprintf(
                'Il giocatore %s è già mio (pagato %d crediti) ma non occupa nessuno slot: i giocatori acquistati devono comparire nel piano con slot_status acquired.',
                $players->get($playerId)?->name ?? "#{$playerId}",
                $price,
            );
        }

        return $errors;
    }

    /**
     * 6. Budget: la somma di ciò che intendo spendere sugli slot ancora aperti
     * non può superare i crediti che ho davvero.
     *
     * @param  array<int, array<string, mixed>>  $slots
     * @param  array<int, int>  $mine
     * @return array<int, string>
     */
    private function validateBudget(array $slots, array $mine, LeagueState $state): array
    {
        $me = $state->myTeam();
        $pending = 0;

        foreach ($slots as $slot) {
            if (isset($mine[(int) ($slot['player_id'] ?? 0)])) {
                continue;
            }

            $pending += max(0, (int) ($slot['target_price'] ?? 0));
        }

        if ($pending <= $me['credits_remaining']) {
            return [];
        }

        return [sprintf(
            'Budget sforato: i target_price degli slot ancora da prendere sommano %d crediti, ma ne restano %d. Rientra di %d.',
            $pending,
            $me['credits_remaining'],
            $pending - $me['credits_remaining'],
        )];
    }

    /**
     * Riepilogo per reparto: quanto il piano alloca e quanto ho già speso.
     * Lo calcola il server dai suoi slot, non lo dichiara chi propone il
     * piano: due numeri che devono coincidere non si scrivono due volte.
     *
     * @param  array<int, array<string, mixed>>  $slots
     * @param  array<int, int>  $mine
     * @return array<string, array{allocated: int, spent: int}>
     */
    private function budgetSummary(array $slots, array $mine): array
    {
        $summary = [];

        foreach ($slots as $slot) {
            $role = mb_strtoupper((string) $slot['role']);
            $summary[$role] ??= ['allocated' => 0, 'spent' => 0];

            $playerId = (int) $slot['player_id'];
            $summary[$role]['allocated'] += (int) $slot['target_price'];

            if (isset($mine[$playerId])) {
                $summary[$role]['spent'] += $mine[$playerId];
            }
        }

        return $summary;
    }

    /**
     * Prezzi pagati per i giocatori già miei in questa asta.
     *
     * @return array<int, int>
     */
    private function myAcquisitions(Auction $auction): array
    {
        return Acquisition::query()
            ->join('teams', 'teams.id', '=', 'acquisitions.team_id')
            ->where('acquisitions.auction_id', $auction->id)
            ->where('teams.is_mine', true)
            ->pluck('acquisitions.price', 'acquisitions.player_id')
            ->map(fn ($price) => (int) $price)
            ->all();
    }

    /**
     * Nome della squadra che ha preso ciascun giocatore: serve solo a
     * scrivere un messaggio d'errore che si capisca.
     *
     * @return array<int, string>
     */
    private function ownersByPlayer(Auction $auction): array
    {
        return Acquisition::query()
            ->join('teams', 'teams.id', '=', 'acquisitions.team_id')
            ->where('acquisitions.auction_id', $auction->id)
            ->pluck('teams.name', 'acquisitions.player_id')
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $slots
     * @return Collection<int, Player>
     */
    private function loadPlayers(array $slots): Collection
    {
        $ids = [];

        foreach ($slots as $slot) {
            $ids[] = (int) ($slot['player_id'] ?? 0);

            foreach (is_array($slot['alternatives'] ?? null) ? $slot['alternatives'] : [] as $alternative) {
                if (is_array($alternative) && isset($alternative['player_id'])) {
                    $ids[] = (int) $alternative['player_id'];
                }
            }
        }

        return Player::query()
            ->whereIn('id', array_unique($ids))
            ->get(['id', 'name', 'role', 'status'])
            ->keyBy('id');
    }

    /**
     * @param  array<int, mixed>  $alternatives
     * @return array<int, array{player_id: int, target_price: int}>
     */
    private function normalizeAlternatives(mixed $alternatives): array
    {
        if (! is_array($alternatives)) {
            return [];
        }

        $normalized = [];

        foreach ($alternatives as $alternative) {
            if (! is_array($alternative) || ! isset($alternative['player_id'])) {
                continue;
            }

            $normalized[] = [
                'player_id' => (int) $alternative['player_id'],
                'target_price' => max(1, (int) ($alternative['target_price'] ?? 1)),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $slot
     */
    private function label(array $slot): string
    {
        return sprintf('slot %s#%d', mb_strtoupper((string) ($slot['role'] ?? '?')), (int) ($slot['slot_index'] ?? 0));
    }
}

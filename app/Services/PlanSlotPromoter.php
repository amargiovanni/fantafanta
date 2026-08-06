<?php

namespace App\Services;

use App\Enums\PlayerStatus;
use App\Enums\SlotStatus;
use App\Models\Acquisition;
use App\Models\PlanSlot;
use App\Models\Player;
use Illuminate\Support\Facades\DB;

/**
 * Promozione deterministica dell'alternativa (briefing §7.3, §9 Fase 3).
 *
 * Quando il titolare di uno slot viene battuto da un'altra squadra, il piano
 * non può restare per un minuto intero con un target che non esiste più: il
 * replan di Claude parte con un debounce di 20 secondi e ne impiega altri
 * trenta, e in quel tempo l'asta è già andata avanti di tre nomi.
 *
 * Questo servizio è la rete di sicurezza: PHP puro, sincrono alla
 * registrazione dell'acquisto, nessuna decisione discrezionale. Marca lo slot
 * come `lost` e promuove titolare la prima alternativa ancora disponibile,
 * con il prezzo che il piano le aveva già assegnato. Il replan poi rifinisce.
 */
class PlanSlotPromoter
{
    /**
     * Applica al piano corrente le conseguenze di un'aggiudicazione.
     *
     * @return array<int, array{slot_id: int, role: string, slot_index: int, outcome: string, player_id: int|null}>
     */
    public function apply(Acquisition $acquisition): array
    {
        $plan = $acquisition->auction?->latestReadyPlan();

        if ($plan === null) {
            return [];
        }

        $isMine = (bool) $acquisition->team?->is_mine;
        $playerId = (int) $acquisition->player_id;

        return DB::transaction(function () use ($plan, $acquisition, $isMine, $playerId) {
            $outcomes = [];
            $journal = [];

            /** @var array<int, PlanSlot> $slots */
            $slots = $plan->slots()->orderBy('role')->orderBy('slot_index')->get()->all();

            $available = $this->availablePlayerIds($slots);

            // Un giocatore appena assegnato non è più un'alternativa valida per
            // nessuno slot: toglierlo subito evita di promuoverlo altrove.
            $available = array_values(array_diff($available, [$playerId]));

            foreach ($slots as $slot) {
                $before = $this->snapshot($slot);

                if ($slot->slot_status !== SlotStatus::Pending || (int) $slot->player_id !== $playerId) {
                    if ($this->pruneAlternatives($slot, $playerId)) {
                        $journal[] = ['slot_id' => (int) $slot->id, 'before' => $before];
                    }

                    continue;
                }

                $outcomes[] = $isMine
                    ? $this->markAcquired($slot, $acquisition)
                    : $this->markLostAndPromote($slot, $available);

                $journal[] = ['slot_id' => (int) $slot->id, 'before' => $before];
            }

            $acquisition->plan_effects = $journal !== [] ? $journal : null;
            $acquisition->saveQuietly();

            return $outcomes;
        });
    }

    /**
     * Rimette il piano com'era prima di quell'aggiudicazione.
     *
     * L'undo della sala d'asta deve essere un `revert`, non una ricostruzione
     * per inferenza: rifare il ragionamento all'indietro ("quale alternativa
     * avrò promosso?") darebbe la risposta giusta solo finché lo stato non si
     * muove sotto, e in asta si muove. Il giornale scritto da `apply()` dice
     * esattamente quali slot sono stati toccati e con quali valori: qui si
     * riscrivono quelli, e nient'altro.
     *
     * @return int quanti slot sono stati ripristinati
     */
    public function revert(Acquisition $acquisition): int
    {
        $journal = $acquisition->plan_effects ?? [];

        if ($journal === []) {
            return 0;
        }

        $reverted = DB::transaction(function () use ($journal) {
            $count = 0;

            foreach ($journal as $entry) {
                $slotId = (int) ($entry['slot_id'] ?? 0);
                $before = $entry['before'] ?? null;

                if ($slotId === 0 || ! is_array($before)) {
                    continue;
                }

                $count += PlanSlot::query()->whereKey($slotId)->update([
                    'player_id' => $before['player_id'] ?? null,
                    'original_player_id' => $before['original_player_id'] ?? null,
                    'target_price' => (int) ($before['target_price'] ?? 1),
                    'max_price' => (int) ($before['max_price'] ?? 1),
                    'alternatives' => json_encode($before['alternatives'] ?? []),
                    'slot_status' => (string) ($before['slot_status'] ?? SlotStatus::Pending->value),
                ]);
            }

            return $count;
        });

        $acquisition->plan_effects = null;
        $acquisition->saveQuietly();

        return $reverted;
    }

    /**
     * I soli campi che `apply()` può toccare. Fotografarli tutti costa nulla e
     * rende il revert indipendente da quale ramo è stato preso.
     *
     * @return array<string, mixed>
     */
    private function snapshot(PlanSlot $slot): array
    {
        return [
            'player_id' => $slot->player_id !== null ? (int) $slot->player_id : null,
            'original_player_id' => $slot->original_player_id !== null ? (int) $slot->original_player_id : null,
            'target_price' => (int) $slot->target_price,
            'max_price' => (int) $slot->max_price,
            'alternatives' => array_values($slot->alternatives ?? []),
            'slot_status' => $slot->slot_status->value,
        ];
    }

    /**
     * @return array{slot_id: int, role: string, slot_index: int, outcome: string, player_id: int|null}
     */
    private function markAcquired(PlanSlot $slot, Acquisition $acquisition): array
    {
        $slot->update([
            'slot_status' => SlotStatus::Acquired,
            'target_price' => (int) $acquisition->price,
            'max_price' => max((int) $slot->max_price, (int) $acquisition->price),
        ]);

        return [
            'slot_id' => (int) $slot->id,
            'role' => $slot->role->value,
            'slot_index' => (int) $slot->slot_index,
            'outcome' => 'acquired',
            'player_id' => (int) $slot->player_id,
        ];
    }

    /**
     * Lo slot resta `lost` anche dopo la promozione: è l'informazione che
     * serve in sala d'asta (briefing §4). Il titolare designato è sfumato, e
     * quello che si vede adesso è il ripiego finché il replan non conferma.
     *
     * @param  array<int, int>  $available
     * @return array{slot_id: int, role: string, slot_index: int, outcome: string, player_id: int|null}
     */
    private function markLostAndPromote(PlanSlot $slot, array $available): array
    {
        $alternatives = array_values($slot->alternatives ?? []);
        $promoted = null;

        foreach ($alternatives as $index => $alternative) {
            $candidate = (int) ($alternative['player_id'] ?? 0);

            if ($candidate > 0 && in_array($candidate, $available, true)) {
                $promoted = $alternative;
                unset($alternatives[$index]);

                break;
            }
        }

        $slot->update([
            'slot_status' => SlotStatus::Lost,

            // Il nome perso: la sala lo mostra barrato con sotto il ripiego,
            // e `player_id` da qui in poi non è più lui.
            'original_player_id' => $slot->original_player_id ?? $slot->player_id,

            'player_id' => $promoted !== null ? (int) $promoted['player_id'] : null,
            'target_price' => $promoted !== null
                ? max(1, (int) ($promoted['target_price'] ?? 1))
                : $slot->target_price,
            'alternatives' => array_values($alternatives),
        ]);

        return [
            'slot_id' => (int) $slot->id,
            'role' => $slot->role->value,
            'slot_index' => (int) $slot->slot_index,
            'outcome' => $promoted !== null ? 'promoted' : 'lost_without_alternative',
            'player_id' => $promoted !== null ? (int) $promoted['player_id'] : null,
        ];
    }

    /**
     * Toglie dalle alternative degli altri slot un giocatore che ormai è
     * assegnato: che sia finito a me o a un avversario, non è più un ripiego
     * disponibile e lasciarlo in lista significherebbe promuoverlo domani.
     *
     * @return bool se lo slot è stato davvero modificato (va nel giornale)
     */
    private function pruneAlternatives(PlanSlot $slot, int $playerId): bool
    {
        $alternatives = array_values(array_filter(
            $slot->alternatives ?? [],
            fn (array $alternative) => (int) ($alternative['player_id'] ?? 0) !== $playerId,
        ));

        if (count($alternatives) === count($slot->alternatives ?? [])) {
            return false;
        }

        $slot->update(['alternatives' => array_values($alternatives)]);

        return true;
    }

    /**
     * Id dei giocatori ancora disponibili fra tutti quelli citati dal piano:
     * una sola query, non una per alternativa.
     *
     * @param  array<int, PlanSlot>  $slots
     * @return array<int, int>
     */
    private function availablePlayerIds(array $slots): array
    {
        $ids = [];

        foreach ($slots as $slot) {
            $ids = array_merge($ids, $slot->involvedPlayerIds());
        }

        if ($ids === []) {
            return [];
        }

        return Player::query()
            ->whereIn('id', array_unique($ids))
            ->where('status', PlayerStatus::Available)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}

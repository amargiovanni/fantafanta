<?php

namespace App\Mcp\Tools;

use App\Enums\PlanStatus;
use App\Mcp\Concerns\ResolvesAuction;
use App\Models\Plan;
use App\Models\PlanSlot;
use App\Models\Player;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[Description(<<<'TXT'
L'ultima versione pronta del piano d'acquisto, con lo stato di ogni slot.

Da chiamare all'inizio di un replan: il piano nuovo non nasce da zero, parte da
questo e cambia ciò che è cambiato.

Stato degli slot:
- `pending` — ancora da prendere;
- `acquired` — il giocatore è già mio, e il suo target_price è il prezzo che ho
  pagato davvero: quello slot è chiuso e va riportato tale e quale;
- `lost` — il titolare designato è stato preso da un'altra squadra. Se il campo
  `player_id` è valorizzato, è la prima alternativa già promossa in automatico
  dal server; è un ripiego, non una scelta: rivedilo.

Se non è mai stato generato un piano il tool risponde `plan: null`, che non è
un errore: è il caso normale della prima esecuzione.
TXT)]
class GetCurrentPlanTool extends Tool
{
    use ResolvesAuction;

    protected string $name = 'get_current_plan';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'auction_id' => $schema->integer()
                ->description('Sessione d\'asta. Omesso: quella aperta.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'auction_id' => ['nullable', 'integer'],
        ]);

        $auction = $this->resolveAuction($validated['auction_id'] ?? null);

        if ($auction === null) {
            return Response::error(
                'Nessuna sessione d\'asta aperta: non c\'è nessun piano da leggere. Apri l\'asta dalla dashboard prima di generare un piano.',
            );
        }

        $plan = $auction->latestReadyPlan();

        if ($plan === null) {
            return Response::structured([
                'auction_id' => $auction->id,
                'plan' => null,
                'message' => 'Nessun piano ancora pronto per questa asta: questa sarà la versione 1.',
            ]);
        }

        $slots = PlanSlot::query()
            ->where('plan_id', $plan->id)
            ->orderByRaw("case role when 'P' then 1 when 'D' then 2 when 'C' then 3 else 4 end")
            ->orderBy('slot_index')
            ->get();

        $names = $this->playerNames($slots);

        $generating = Plan::query()
            ->where('auction_id', $auction->id)
            ->where('status', PlanStatus::Generating)
            ->where('version', '>', $plan->version)
            ->exists();

        return Response::structured([
            'auction_id' => $auction->id,
            'plan' => [
                'id' => $plan->id,
                'version' => $plan->version,
                'trigger' => $plan->trigger->value,
                'status' => $plan->status->value,
                'strategy_notes' => $plan->strategy_notes,
                'budget_summary' => $plan->budget_summary,
                'created_at' => $plan->created_at?->toIso8601String(),
                'newer_version_generating' => $generating,
                'slots' => $slots->map(fn (PlanSlot $slot) => [
                    'role' => $slot->role->value,
                    'slot_index' => $slot->slot_index,
                    'slot_status' => $slot->slot_status->value,
                    'player_id' => $slot->player_id,
                    'player_name' => $names[$slot->player_id] ?? null,
                    'target_price' => $slot->target_price,
                    'max_price' => $slot->max_price,
                    'alternatives' => array_map(fn (array $alternative) => [
                        'player_id' => (int) $alternative['player_id'],
                        'player_name' => $names[(int) $alternative['player_id']] ?? null,
                        'target_price' => (int) ($alternative['target_price'] ?? 1),
                    ], $slot->alternatives ?? []),
                ])->all(),
            ],
        ]);
    }

    /**
     * Nomi di tutti i giocatori citati dal piano, titolari e alternative, con
     * una sola query.
     *
     * @param  Collection<int, PlanSlot>  $slots
     * @return array<int, string>
     */
    private function playerNames($slots): array
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
            ->pluck('name', 'id')
            ->all();
    }
}

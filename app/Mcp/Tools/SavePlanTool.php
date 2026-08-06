<?php

namespace App\Mcp\Tools;

use App\Enums\PlanTrigger;
use App\Enums\PlayerRole;
use App\Enums\SlotStatus;
use App\Exceptions\PlanValidationException;
use App\Mcp\Concerns\ResolvesAuction;
use App\Models\PlanSlot;
use App\Services\PlanWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description(<<<'TXT'
Scrive una nuova versione del piano d'acquisto: tutti e 25 gli slot in una sola
chiamata. È l'output del lavoro di pianificazione — il piano è la risposta, non
il testo che lo accompagna.

Ogni slot vuole: `role`, `slot_index` (da 1 a N dentro il ruolo), `player_id`
del titolare, `target_price` (quanto conti di spendere), `max_price` (oltre cui
lasci perdere) e `alternatives`, almeno due, in ordine di preferenza.

Il server valida tutto e rifiuta il piano intero se qualcosa non torna,
restituendo l'elenco COMPLETO delle violazioni: correggi quelle e richiama il
tool una volta sola, non a tentativi. Le regole:

- 25 slot esatti, con i conteggi per ruolo della configurazione di lega, e gli
  slot_index da 1 a N senza salti né ripetizioni;
- un giocatore può essere titolare di un solo slot; può invece essere
  alternativa di più slot dello stesso ruolo, ma non alternativa di uno slot e
  titolare di un altro;
- il ruolo del giocatore deve coincidere con quello dello slot, alternative
  comprese;
- il titolare deve essere ancora disponibile. Unica eccezione, obbligatoria: i
  giocatori GIÀ MIEI devono comparire ciascuno nel suo slot, con target_price
  uguale al prezzo che ho pagato. Il server marca quegli slot come `acquired` e
  non chiede alternative;
- ogni slot ancora da prendere vuole almeno 2 alternative, e nessuna
  alternativa può costare più del max_price del suo slot;
- target_price ≥ 1 e max_price ≥ target_price;
- la somma dei target_price degli slot da prendere deve stare dentro i crediti
  che mi restano. Il vincolo "ogni slot costa almeno 1 credito" è già dentro
  questa somma.

`strategy_notes` è obbligatorio e vale al massimo 3 righe: il razionale della
versione, non il riassunto di ciò che si legge negli slot. Il riepilogo di
budget per reparto lo calcola il server dagli slot, non serve dichiararlo.
TXT)]
class SavePlanTool extends Tool
{
    use ResolvesAuction;

    protected string $name = 'save_plan';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'strategy_notes' => $schema->string()
                ->description('Massimo 3 righe sul razionale di questa versione: ripartizione scelta e perché.')
                ->required(),
            'slots' => $schema->array()
                ->description('Tutti gli slot della rosa, 25 per la configurazione standard.')
                ->required()
                ->min(1)
                ->items($schema->object([
                    'role' => $schema->string()
                        ->description('Ruolo dello slot.')
                        ->enum(array_column(PlayerRole::cases(), 'value'))
                        ->required(),
                    'slot_index' => $schema->integer()
                        ->description('Progressivo dentro il ruolo, da 1 al numero di slot di quel ruolo.')
                        ->min(1)
                        ->required(),
                    'player_id' => $schema->integer()
                        ->description('Titolare designato dello slot.')
                        ->required(),
                    'target_price' => $schema->integer()
                        ->description('Crediti che conti di spendere. Per un giocatore già tuo: il prezzo pagato.')
                        ->min(1)
                        ->required(),
                    'max_price' => $schema->integer()
                        ->description('Tetto oltre il quale rinunci e passi all\'alternativa.')
                        ->min(1)
                        ->required(),
                    'alternatives' => $schema->array()
                        ->description('Ripieghi in ordine di preferenza, almeno 2 per gli slot da prendere.')
                        ->items($schema->object([
                            'player_id' => $schema->integer()->required(),
                            'target_price' => $schema->integer()->min(1)->required(),
                        ])),
                ])),
            'trigger' => $schema->string()
                ->description('Perché stai scrivendo questa versione. Default initial.')
                ->enum(PlanTrigger::values()),
            'auction_id' => $schema->integer()
                ->description('Sessione d\'asta. Omesso: quella aperta.'),
        ];
    }

    public function handle(Request $request, PlanWriter $writer): Response|ResponseFactory
    {
        $validated = $request->validate([
            'strategy_notes' => ['required', 'string'],
            'slots' => ['required', 'array', 'min:1'],
            'trigger' => ['nullable', 'string', 'in:'.implode(',', PlanTrigger::values())],
            'auction_id' => ['nullable', 'integer'],
        ]);

        $auction = $this->resolveAuction($validated['auction_id'] ?? null);

        if ($auction === null) {
            return Response::error(
                'Nessuna sessione d\'asta aperta: il piano non ha dove essere salvato. Va aperta dalla dashboard prima di generare il piano.',
            );
        }

        try {
            $plan = $writer->save(
                $auction,
                $validated['slots'],
                $validated['strategy_notes'],
                PlanTrigger::from($validated['trigger'] ?? PlanTrigger::Initial->value),
            );
        } catch (PlanValidationException $exception) {
            // Elenco completo, non il primo errore: chi corregge deve poterlo
            // fare in un turno solo (briefing §6).
            return Response::error(
                "Il piano non è stato salvato. Correggi TUTTI questi punti e richiama save_plan una volta sola:\n- "
                .implode("\n- ", $exception->errors()),
            );
        }

        $slots = $plan->slots;

        return Response::structured([
            'plan_id' => $plan->id,
            'version' => $plan->version,
            'status' => $plan->status->value,
            'slots_saved' => $slots->count(),
            'acquired_slots' => $slots->where('slot_status', SlotStatus::Acquired)->count(),
            'pending_slots' => $slots->where('slot_status', SlotStatus::Pending)->count(),
            'budget_summary' => $plan->budget_summary,
            'planned_spend' => $slots->sum(fn (PlanSlot $slot) => $slot->target_price),
        ]);
    }
}

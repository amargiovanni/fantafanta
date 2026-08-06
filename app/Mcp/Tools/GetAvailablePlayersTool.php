<?php

namespace App\Mcp\Tools;

use App\Enums\PlayerRole;
use App\Enums\PlayerStatus;
use App\Models\Player;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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
Elenco dei giocatori ancora acquistabili, con la loro valutazione corrente.

È il tool con cui si costruisce un piano: filtra per ruolo e per tier, oppure
per fascia di valore, e ordina come serve.

Campi della valutazione (prodotti dal motore deterministico, non da te):
- `adjusted_value`: quanto vale davvero adesso, segnali e modificatori di lega
  inclusi. È il numero su cui ragionare;
- `max_bid`: il massimo che si può offrire dati i crediti e gli slot residui.
  Non è un consiglio di spesa, è un tetto: offrire di più è impossibile;
- `tier`: 1 sono i migliori del ruolo, 5 i tappabuchi. I quintili sono
  calcolati fra i soli disponibili, quindi si aggiornano mentre l'asta va;
- `scarcity_index`: sopra 1 significa che gli avversari hanno più bisogno di
  quel profilo di quanti ne restino.

`active_signals_count` segnala quando conviene aprire la scheda con get_player:
un giocatore con segnali attivi ha una storia che il solo numero non racconta.
TXT)]
class GetAvailablePlayersTool extends Tool
{
    protected string $name = 'get_available_players';

    /** Ordinamenti ammessi: colonna esposta => colonna reale. */
    private const SORTABLE = [
        'adjusted_value' => 'valuations.adjusted_value',
        'base_value' => 'valuations.base_value',
        'max_bid' => 'valuations.max_bid',
        'scarcity_index' => 'valuations.scarcity_index',
        'quotazione' => 'players.quotazione',
        'fvm' => 'players.fvm',
        'expected_starter' => 'players.expected_starter',
        'name' => 'players.name',
    ];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'role' => $schema->string()
                ->description('Ruolo: P, D, C o A.')
                ->enum(array_column(PlayerRole::cases(), 'value')),
            'tier' => $schema->integer()
                ->description('Solo i giocatori di questo tier (1 = migliori del ruolo).')
                ->min(1)
                ->max(5),
            'max_tier' => $schema->integer()
                ->description('Solo i giocatori di tier pari o migliore di questo.')
                ->min(1)
                ->max(5),
            'min_value' => $schema->number()
                ->description('adjusted_value minimo.')
                ->min(0),
            'max_value' => $schema->number()
                ->description('adjusted_value massimo: utile per trovare i tappabuchi.')
                ->min(0),
            'real_team' => $schema->string()
                ->description('Squadra di Serie A, per concentrare la difesa o diversificare l\'attacco.'),
            'only_penalty_takers' => $schema->boolean()
                ->description('Solo i rigoristi.'),
            'include_unavailable' => $schema->boolean()
                ->description('true per vedere anche chi è già stato assegnato. Default false.'),
            'sort' => $schema->string()
                ->description('Campo di ordinamento. Default adjusted_value.')
                ->enum(array_keys(self::SORTABLE)),
            'direction' => $schema->string()
                ->description('desc (default) o asc.')
                ->enum(['asc', 'desc']),
            'limit' => $schema->integer()
                ->description('Quanti giocatori restituire, da 1 a 200. Default 30.')
                ->min(1)
                ->max(200),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'role' => ['nullable', 'string', 'in:'.implode(',', array_column(PlayerRole::cases(), 'value'))],
            'tier' => ['nullable', 'integer', 'between:1,5'],
            'max_tier' => ['nullable', 'integer', 'between:1,5'],
            'min_value' => ['nullable', 'numeric', 'min:0'],
            'max_value' => ['nullable', 'numeric', 'min:0'],
            'real_team' => ['nullable', 'string'],
            'only_penalty_takers' => ['nullable', 'boolean'],
            'include_unavailable' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::SORTABLE))],
            'direction' => ['nullable', 'string', 'in:asc,desc'],
            'limit' => ['nullable', 'integer', 'between:1,200'],
        ]);

        $sort = self::SORTABLE[$validated['sort'] ?? 'adjusted_value'];
        $direction = $validated['direction'] ?? 'desc';
        $limit = (int) ($validated['limit'] ?? 30);

        $query = Player::query()
            ->leftJoin('valuations', 'valuations.player_id', '=', 'players.id')
            ->leftJoin('signals', function ($join) {
                $join->on('signals.player_id', '=', 'players.id')
                    ->whereNull('signals.superseded_by')
                    ->where('signals.needs_review', false);
            })
            ->groupBy('players.id')
            ->select([
                'players.id',
                'players.name',
                'players.role',
                'players.real_team',
                'players.quotazione',
                'players.fvm',
                'players.status',
                'players.is_rigorista',
                'players.expected_starter',
                'valuations.base_value',
                'valuations.adjusted_value',
                'valuations.max_bid',
                'valuations.tier',
                'valuations.scarcity_index',
            ])
            ->selectRaw('count(signals.id) as active_signals_count');

        if (! ($validated['include_unavailable'] ?? false)) {
            $query->where('players.status', PlayerStatus::Available);
        }

        $query
            ->when($validated['role'] ?? null, fn ($q, $role) => $q->where('players.role', $role))
            ->when($validated['tier'] ?? null, fn ($q, $tier) => $q->where('valuations.tier', $tier))
            ->when($validated['max_tier'] ?? null, fn ($q, $tier) => $q->where('valuations.tier', '<=', $tier))
            ->when($validated['min_value'] ?? null, fn ($q, $value) => $q->where('valuations.adjusted_value', '>=', $value))
            ->when($validated['max_value'] ?? null, fn ($q, $value) => $q->where('valuations.adjusted_value', '<=', $value))
            ->when($validated['real_team'] ?? null, fn ($q, $team) => $q->where('players.real_team', $team))
            ->when($validated['only_penalty_takers'] ?? false, fn ($q) => $q->where('players.is_rigorista', true));

        $rows = $query
            ->orderBy($sort, $direction)
            ->orderBy('players.id')
            ->limit($limit)
            ->get();

        $missingValuations = $rows->whereNull('adjusted_value')->count();

        return Response::structured([
            'count' => $rows->count(),
            'filters' => array_filter($validated, fn ($value) => $value !== null),
            'players' => $rows->map(fn ($row) => [
                'player_id' => (int) $row->id,
                'name' => $row->name,
                'role' => $row->role->value,
                'real_team' => $row->real_team,
                'quotazione' => (int) $row->quotazione,
                'fvm' => (int) $row->fvm,
                'status' => $row->status->value,
                'is_rigorista' => (bool) $row->is_rigorista,
                'expected_starter' => (float) $row->expected_starter,
                'active_signals_count' => (int) $row->active_signals_count,
                'valuation' => $row->adjusted_value === null ? null : [
                    'base_value' => (float) $row->base_value,
                    'adjusted_value' => (float) $row->adjusted_value,
                    'max_bid' => (int) $row->max_bid,
                    'tier' => (int) $row->tier,
                    'scarcity_index' => (float) $row->scarcity_index,
                ],
            ])->all(),

            // Se il motore non ha ancora girato è meglio saperlo qui che
            // scoprire un piano costruito su valutazioni assenti.
            'without_valuation' => $missingValuations,
        ]);
    }
}

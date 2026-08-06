<?php

namespace App\Mcp\Tools;

use App\Models\Acquisition;
use App\Models\Player;
use App\Models\Signal;
use App\Models\Valuation;
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
Scheda completa di un giocatore: anagrafica, quotazione, statistiche di
stagione, alias noti, valutazione corrente e segnali ATTIVI (quelli non
superati da segnali più recenti), ciascuno con la fonte da cui proviene e la
data dell'evento.

Da consultare prima di scrivere un nuovo segnale, per sapere cosa risulta già
e decidere se il nuovo lo corrobora o lo contraddice; e prima di mettere un
nome nel piano, per vedere perché la sua valutazione è quella che è.

`valuation` è l'output del motore deterministico: `adjusted_value` è il valore
corrente, `max_bid` il tetto di offerta dati crediti e slot residui, `tier` la
fascia nel ruolo. È null solo se il motore non ha ancora girato.

`acquisition` c'è quando il giocatore è già stato aggiudicato: dice a chi e a
quanto, ed è il motivo per cui non può entrare in un piano come titolare.
TXT)]
class GetPlayerTool extends Tool
{
    protected string $name = 'get_player';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'player_id' => $schema->integer()
                ->description('Id del giocatore, come restituito da search_player.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'player_id' => ['required', 'integer'],
        ]);

        $player = Player::query()->with('aliases')->find($validated['player_id']);

        if ($player === null) {
            return Response::error(sprintf(
                'Nessun giocatore con id %d nel listone. Usa search_player per trovare l\'id corretto.',
                $validated['player_id'],
            ));
        }

        $signals = Signal::query()
            ->with('source')
            ->active()
            ->where('player_id', $player->id)
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->get();

        $valuation = Valuation::query()->where('player_id', $player->id)->first();

        $acquisition = Acquisition::query()
            ->join('teams', 'teams.id', '=', 'acquisitions.team_id')
            ->where('acquisitions.player_id', $player->id)
            ->orderByDesc('acquisitions.id')
            ->select(['acquisitions.price', 'acquisitions.created_at', 'teams.name as team_name', 'teams.is_mine as is_mine'])
            ->toBase()
            ->first();

        return Response::structured([
            'player' => [
                'id' => $player->id,
                'name' => $player->name,
                'role' => $player->role->value,
                'role_label' => $player->role->label(),
                'real_team' => $player->real_team,
                'quotazione' => $player->quotazione,
                'fvm' => $player->fvm,
                'status' => $player->status->value,
                'is_rigorista' => $player->is_rigorista,
                'expected_starter' => $player->expected_starter,
                'season_stats' => $player->season_stats,
                'aliases' => $player->aliases->pluck('alias')->all(),
            ],
            'valuation' => $valuation === null ? null : [
                'base_value' => $valuation->base_value,
                'adjusted_value' => $valuation->adjusted_value,
                'max_bid' => $valuation->max_bid,
                'tier' => $valuation->tier,
                'scarcity_index' => $valuation->scarcity_index,
                'computed_at' => $valuation->computed_at?->toIso8601String(),
            ],
            'acquisition' => $acquisition === null ? null : [
                'team_name' => $acquisition->team_name,
                'is_mine' => (bool) $acquisition->is_mine,
                'price' => (int) $acquisition->price,
                'created_at' => (string) $acquisition->created_at,
            ],
            'active_signals' => $signals->map(fn (Signal $signal) => [
                'id' => $signal->id,
                'type' => $signal->type->value,
                'impact' => $signal->impact,
                'confidence' => (float) $signal->confidence,
                'event_date' => $signal->event_date?->toDateString(),
                'payload' => $signal->payload,
                'source' => [
                    'id' => $signal->source->id,
                    'title' => $signal->source->title,
                    'url' => $signal->source->url,
                    'type' => $signal->source->type->value,
                ],
            ])->all(),
            'active_signals_count' => $signals->count(),
        ]);
    }
}

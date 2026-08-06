<?php

namespace App\Mcp\Tools;

use App\Models\Player;
use App\Models\Signal;
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
stagione, alias noti e segnali ATTIVI (quelli non superati da segnali più
recenti), ciascuno con la fonte da cui proviene e la data dell'evento.

Da consultare prima di scrivere un nuovo segnale, per sapere cosa risulta già
e decidere se il nuovo lo corrobora o lo contraddice. Non scrive nulla.
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

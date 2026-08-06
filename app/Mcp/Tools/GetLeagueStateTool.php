<?php

namespace App\Mcp\Tools;

use App\Enums\PlayerStatus;
use App\Mcp\Concerns\ResolvesAuction;
use App\Models\Player;
use App\Services\LeagueState;
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
Stato della lega: regolamento, sessione d'asta in corso, e per OGNI squadra i
crediti residui e gli slot ancora aperti per ruolo — la mia e quelle
avversarie.

È il primo tool da chiamare prima di costruire o rivedere un piano: dice quanto
posso ancora spendere, quanti giocatori mi mancano in ciascun reparto e quanta
capacità di spesa hanno gli altri, che è ciò che determina i prezzi.

I modificatori della lega (difesa, fairplay) cambiano quanto vale un reparto:
sono qui perché la ripartizione del budget parte da loro.
TXT)]
class GetLeagueStateTool extends Tool
{
    use ResolvesAuction;

    protected string $name = 'get_league_state';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'auction_id' => $schema->integer()
                ->description('Sessione d\'asta da leggere. Omesso: quella aperta.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'auction_id' => ['nullable', 'integer'],
        ]);

        $state = LeagueState::load($this->resolveAuction($validated['auction_id'] ?? null));
        $config = $state->config;
        $me = $state->myTeam();

        $playersByStatus = Player::query()
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status');

        return Response::structured([
            'league' => [
                'slots' => $state->slots(),
                'total_slots' => $state->totalSlots(),
                'total_credits' => (int) $config->total_credits,
                'teams_count' => (int) $config->teams_count,
                'modifier_defense' => (bool) $config->modifier_defense,
                'modifier_fairplay' => (bool) $config->modifier_fairplay,
                'auction_type' => $config->auction_type,
                'credit_pool' => (int) $config->teams_count * (int) $config->total_credits,
            ],
            'auction' => $state->auction === null ? null : [
                'id' => $state->auction->id,
                'name' => $state->auction->name,
                'status' => $state->auction->status->value,
                'started_at' => $state->auction->started_at?->toIso8601String(),
            ],
            'my_team' => $me,
            'teams' => array_values($state->teams),
            'opponent_demand_by_role' => $state->opponentDemandByRole(),
            'listone' => [
                'available' => (int) ($playersByStatus[PlayerStatus::Available->value] ?? 0),
                'acquired' => (int) ($playersByStatus[PlayerStatus::Acquired->value] ?? 0),
                'removed' => (int) ($playersByStatus[PlayerStatus::Removed->value] ?? 0),
            ],
        ]);
    }
}

<?php

namespace App\Mcp\Tools;

use App\Enums\PlayerRole;
use App\Mcp\Concerns\ResolvesAuction;
use App\Models\Acquisition;
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
Le aggiudicazioni in ordine cronologico: chi ha preso chi, a che prezzo, e
quanto quel giocatore valeva secondo il motore al momento dell'acquisto.

`delta_percent` è lo scostamento fra prezzo pagato e valutazione: positivo vuol
dire che quella squadra ha pagato sopra il valore. Letto sull'insieme racconta
come sta andando l'asta molto meglio di qualsiasi media; letto per squadra dice
chi si sta svenando e chi tiene i crediti per dopo.

Gli acquisti annullati (undo) non compaiono: non sono mai avvenuti.
TXT)]
class GetAuctionLogTool extends Tool
{
    use ResolvesAuction;

    protected string $name = 'get_auction_log';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'auction_id' => $schema->integer()
                ->description('Sessione d\'asta. Omesso: quella aperta.'),
            'role' => $schema->string()
                ->description('Solo le aggiudicazioni di questo ruolo.')
                ->enum(array_column(PlayerRole::cases(), 'value')),
            'team_id' => $schema->integer()
                ->description('Solo le aggiudicazioni di questa squadra.'),
            'limit' => $schema->integer()
                ->description('Quante righe restituire, da 1 a 500. Default 100.')
                ->min(1)
                ->max(500),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'auction_id' => ['nullable', 'integer'],
            'role' => ['nullable', 'string', 'in:'.implode(',', array_column(PlayerRole::cases(), 'value'))],
            'team_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'between:1,500'],
        ]);

        $auction = $this->resolveAuction($validated['auction_id'] ?? null);

        if ($auction === null) {
            return Response::error(
                'Nessuna sessione d\'asta aperta: non c\'è nessun registro da leggere.',
            );
        }

        $rows = Acquisition::query()
            ->join('players', 'players.id', '=', 'acquisitions.player_id')
            ->join('teams', 'teams.id', '=', 'acquisitions.team_id')
            ->where('acquisitions.auction_id', $auction->id)
            ->when($validated['role'] ?? null, fn ($query, $role) => $query->where('players.role', $role))
            ->when($validated['team_id'] ?? null, fn ($query, $teamId) => $query->where('acquisitions.team_id', $teamId))
            ->orderBy('acquisitions.id')
            ->limit((int) ($validated['limit'] ?? 100))
            ->select([
                'acquisitions.id',
                'acquisitions.price',
                'acquisitions.valuation_at_purchase',
                'acquisitions.created_at',
                'players.id as player_id',
                'players.name as player_name',
                'players.role as role',
                'players.real_team as real_team',
                'teams.id as team_id',
                'teams.name as team_name',
                'teams.is_mine as is_mine',
            ])
            ->toBase()
            ->get();

        $totalPaid = (int) $rows->sum('price');
        $totalValuation = (float) $rows->sum('valuation_at_purchase');

        return Response::structured([
            'auction_id' => $auction->id,
            'count' => $rows->count(),
            'total_paid' => $totalPaid,
            'total_valuation' => round($totalValuation, 2),
            'overall_delta_percent' => $totalValuation > 0
                ? round(($totalPaid - $totalValuation) / $totalValuation * 100, 1)
                : null,
            'acquisitions' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'player_id' => (int) $row->player_id,
                'player_name' => $row->player_name,
                'role' => $row->role,
                'real_team' => $row->real_team,
                'team_id' => (int) $row->team_id,
                'team_name' => $row->team_name,
                'is_mine' => (bool) $row->is_mine,
                'price' => (int) $row->price,
                'valuation_at_purchase' => $row->valuation_at_purchase === null ? null : (float) $row->valuation_at_purchase,
                'delta_percent' => $row->valuation_at_purchase > 0
                    ? round(((int) $row->price - (float) $row->valuation_at_purchase) / (float) $row->valuation_at_purchase * 100, 1)
                    : null,
                'created_at' => (string) $row->created_at,
            ])->all(),
        ]);
    }
}

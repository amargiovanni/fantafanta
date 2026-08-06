<?php

namespace App\Mcp\Tools;

use App\Enums\PlayerStatus;
use App\Mcp\Concerns\ResolvesAuction;
use App\Models\Acquisition;
use App\Models\Auction;
use App\Models\Player;
use App\Services\LeagueState;
use App\Services\ValuationEngine;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
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
Come sta andando il mercato, reparto per reparto: quanto si sta pagando rispetto
alle valutazioni, quanti crediti restano agli avversari, quanti giocatori
restano per tier.

Da leggere prima di fissare i prezzi del piano, e di nuovo a ogni replan.

- `inflation.raw` è il rapporto grezzo fra prezzi pagati e valore atteso nel
  ruolo; `inflation.effective` è quello ammortizzato che il motore applica
  davvero ai max_bid. Serve un minimo di acquisti nel ruolo prima che
  l'indicatore si attivi: sotto quella soglia vale 1.0 e non significa
  "prezzi in linea", significa "non lo sappiamo ancora";
- `scarcity_by_tier` incrocia gli slot ancora aperti degli avversari solvibili
  con i giocatori rimasti di quel livello: sopra 1 vuol dire che quel profilo
  scarseggia e il prezzo salirà;
- `opponents` dice chi ha ancora la potenza di fuoco per rilanciare. Una
  squadra con pochi crediti e molti slot aperti non farà più prezzo.
TXT)]
class GetBudgetAnalysisTool extends Tool
{
    use ResolvesAuction;

    protected string $name = 'get_budget_analysis';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'auction_id' => $schema->integer()
                ->description('Sessione d\'asta da analizzare. Omesso: quella aperta.'),
        ];
    }

    public function handle(Request $request, ValuationEngine $engine): Response|ResponseFactory
    {
        $validated = $request->validate([
            'auction_id' => ['nullable', 'integer'],
        ]);

        $auction = $this->resolveAuction($validated['auction_id'] ?? null);
        $state = LeagueState::load($auction);
        $me = $state->myTeam();

        $inflation = $engine->inflationForRoles($auction);
        $demand = $state->opponentDemandByRole();
        $supply = $this->availableByRoleAndTier();
        $spending = $this->spendingByRole($auction);

        $roles = [];

        foreach ($state->slots() as $role => $slots) {
            $available = array_sum($supply[$role] ?? []);

            $roles[$role] = [
                'inflation' => $inflation[$role] ?? ['acquisitions' => 0, 'paid' => 0, 'expected' => 0.0, 'raw' => 1.0, 'effective' => 1.0],
                'acquired_in_league' => (int) ($spending[$role]['count'] ?? 0),
                'average_price_paid' => (float) ($spending[$role]['average_price'] ?? 0),
                'average_valuation_paid' => (float) ($spending[$role]['average_valuation'] ?? 0),
                'my_open_slots' => (int) ($me['open_slots_by_role'][$role] ?? 0),
                'opponent_open_slots' => (int) ($demand[$role] ?? 0),
                'available_players' => $available,
                'scarcity_by_tier' => $this->scarcityByTier((int) ($demand[$role] ?? 0), $supply[$role] ?? []),
            ];
        }

        return Response::structured([
            'auction_id' => $auction?->id,
            'my_budget' => [
                'credits_total' => $me['credits_total'],
                'credits_spent' => $me['credits_spent'],
                'credits_remaining' => $me['credits_remaining'],
                'open_slots_total' => $me['open_slots_total'],
                'open_slots_by_role' => $me['open_slots_by_role'],
                'credits_per_open_slot' => $me['credits_per_open_slot'],

                // Il tetto aritmetico: ogni altro slot aperto costa almeno 1.
                'max_single_bid' => $me['open_slots_total'] > 0
                    ? max(0, $me['credits_remaining'] - ($me['open_slots_total'] - 1))
                    : 0,
            ],
            'opponents' => array_map(fn (array $team) => [
                'id' => $team['id'],
                'name' => $team['name'],
                'credits_remaining' => $team['credits_remaining'],
                'open_slots_total' => $team['open_slots_total'],
                'open_slots_by_role' => $team['open_slots_by_role'],
                'credits_per_open_slot' => $team['credits_per_open_slot'],
            ], $state->opponents()),
            'roles' => $roles,
        ]);
    }

    /**
     * Offerta residua per ruolo e tier, con una sola query aggregata.
     *
     * @return array<string, array<int, int>>
     */
    private function availableByRoleAndTier(): array
    {
        // toBase(): sono conteggi, non modelli. Evita anche che il cast del
        // ruolo trasformi in enum una colonna che qui serve come chiave.
        $rows = Player::query()
            ->join('valuations', 'valuations.player_id', '=', 'players.id')
            ->where('players.status', PlayerStatus::Available)
            ->groupBy('players.role', 'valuations.tier')
            ->selectRaw('players.role as role, valuations.tier as tier, count(*) as total')
            ->toBase()
            ->get();

        $supply = [];

        foreach ($rows as $row) {
            $supply[$row->role][(int) $row->tier] = (int) $row->total;
        }

        return $supply;
    }

    /**
     * Prezzi pagati nella lega per ruolo, con il valore che quei giocatori
     * avevano al momento dell'acquisto: è il confronto che dice se si sta
     * pagando troppo.
     *
     * @return array<string, array{count: int, average_price: float, average_valuation: float}>
     */
    private function spendingByRole(?Auction $auction): array
    {
        $rows = Acquisition::query()
            ->join('players', 'players.id', '=', 'acquisitions.player_id')
            ->when($auction !== null, fn ($query) => $query->where('acquisitions.auction_id', $auction->id))
            ->groupBy('players.role')
            ->select([
                DB::raw('players.role as role'),
                DB::raw('count(*) as total'),
                DB::raw('avg(acquisitions.price) as average_price'),
                DB::raw('avg(acquisitions.valuation_at_purchase) as average_valuation'),
            ])
            ->toBase()
            ->get();

        $spending = [];

        foreach ($rows as $row) {
            $spending[$row->role] = [
                'count' => (int) $row->total,
                'average_price' => round((float) $row->average_price, 2),
                'average_valuation' => round((float) $row->average_valuation, 2),
            ];
        }

        return $spending;
    }

    /**
     * Domanda avversaria contro offerta cumulata fino a quel tier: quanti
     * concorrenti per ogni giocatore di quel livello o migliore.
     *
     * @param  array<int, int>  $supply
     * @return array<int, array{available_up_to_tier: int, index: float}>
     */
    private function scarcityByTier(int $demand, array $supply): array
    {
        $clamp = config('valuation.scarcity.clamp');
        $result = [];
        $cumulative = 0;

        for ($tier = 1; $tier <= (int) config('valuation.scarcity.tiers'); $tier++) {
            $cumulative += $supply[$tier] ?? 0;

            $index = $cumulative > 0 ? $demand / $cumulative : (float) $clamp['max'];

            $result[$tier] = [
                'available_up_to_tier' => $cumulative,
                'index' => round(max((float) $clamp['min'], min((float) $clamp['max'], $index)), 2),
            ];
        }

        return $result;
    }
}

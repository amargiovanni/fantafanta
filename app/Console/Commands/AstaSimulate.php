<?php

namespace App\Console\Commands;

use App\Enums\AuctionStatus;
use App\Enums\PlayerStatus;
use App\Enums\SlotStatus;
use App\Models\Acquisition;
use App\Models\Auction;
use App\Models\PlanSlot;
use App\Models\Player;
use App\Models\Team;
use App\Models\Valuation;
use App\Services\LeagueState;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Collaudo dell'asta senza aspettarne una vera (spec Fase 5, §5).
 *
 * Estrae giocatori disponibili pesati per fascia (i tier bassi — i big — escono
 * presto più spesso), simula un'offerta avversaria e decide il vincitore con la
 * stessa regola che guida Andrea in sala: la mia squadra prende il giocatore se
 * il prezzo di mercato resta dentro il tetto che il piano le assegna, altrimenti
 * vince l'avversario che se lo può permettere meglio. Ogni evento passa dal
 * FUNNEL VERO — `Acquisition::create()` — quindi osservatore, promozione
 * deterministica e debounce del replan sono esattamente quelli della sala, non
 * una loro imitazione.
 *
 * Di default nessun run reale di `claude` può partire: l'intero comando gira
 * sotto `Queue::fake()`, quindi niente lascia mai il processo PHP di questo
 * comando per finire su Redis. Solo `--replan` disattiva la finzione: da quel
 * momento, se Horizon è davvero in esecuzione, un replan può completare per
 * davvero (è il modo per collaudare il debounce e i tempi di un run reale).
 */
class AstaSimulate extends Command
{
    protected $signature = 'asta:simulate
        {--events=30 : Numero di eventi (aggiudicazioni) da simulare}
        {--interval=0 : Secondi di pausa fra un evento e il successivo, per seguire la sala dal vivo}
        {--replan : Lascia girare i job in coda per davvero (richiede Horizon attivo per un replan reale)}';

    protected $description = 'Simula un\'asta (acquisti random pesati per fascia) per collaudare la sala e il replan senza aspettare quella vera.';

    /** @var array{events: int, mine: int, opponents: int, promotions: int, skipped: int} */
    private array $log = ['events' => 0, 'mine' => 0, 'opponents' => 0, 'promotions' => 0, 'skipped' => 0];

    public function handle(): int
    {
        $eventsWanted = max(1, (int) $this->option('events'));
        $interval = max(0, (int) $this->option('interval'));
        $realReplan = (bool) $this->option('replan');

        $teams = Team::query()->orderBy('id')->get();

        if ($teams->isEmpty()) {
            $this->components->error('Nessuna squadra registrata: configura la lega da /lega prima di simulare.');

            return self::FAILURE;
        }

        if (Player::query()->where('status', PlayerStatus::Available)->count() === 0) {
            $this->components->error('Il listone non ha giocatori disponibili: importalo da /listone/import prima di simulare.');

            return self::FAILURE;
        }

        $auction = $this->resolveAuction();

        if (! $realReplan) {
            Queue::fake();
            $this->components->warn('Modalità finta: i job (ricalcolo, replan, run claude) restano nel processo di questo comando e non toccano mai Redis — nessuna chiamata reale è possibile. Usa --replan per un collaudo con Horizon attivo.');
        } else {
            $this->components->warn('--replan attivo: i job in coda sono REALI. Se Horizon gira, un replan può davvero invocare claude a pagamento.');
        }

        $planVersionsBefore = (int) ($auction->plans()->max('version') ?? 0);

        $this->newLine();

        $attempts = 0;
        $maxAttempts = $eventsWanted * 6;

        while ($this->log['events'] < $eventsWanted && $attempts < $maxAttempts) {
            $attempts++;

            $player = $this->pickWeightedPlayer();

            if ($player === null) {
                $this->components->warn('Nessun giocatore disponibile: fermo la simulazione qui.');

                break;
            }

            if (! $this->simulateEvent($auction, $player, $teams)) {
                continue;
            }

            if ($interval > 0 && $this->log['events'] < $eventsWanted) {
                sleep($interval);
            }
        }

        $planVersionsAfter = (int) ($auction->plans()->max('version') ?? 0);

        $this->newLine();
        $this->components->info('Simulazione conclusa.');
        $this->table(['Metrica', 'Valore'], [
            ['Eventi registrati', $this->log['events']],
            ['  di cui alla mia squadra', $this->log['mine']],
            ['  di cui agli avversari', $this->log['opponents']],
            ['Promozioni di piano scattate', $this->log['promotions']],
            ['Eventi saltati (nessuno slot libero/nessuno può pagare)', $this->log['skipped']],
            ['Versioni di piano all\'avvio', $planVersionsBefore],
            ['Versioni di piano alla fine', $planVersionsAfter],
        ]);

        if (! $realReplan) {
            $this->comment('I job accodati durante la corsa non sono stati eseguiti (modalità finta, vedi sopra). Rilancia con --replan e Horizon attivo per vedere un replan vero.');
        }

        return self::SUCCESS;
    }

    /**
     * L'asta su cui lavorare: quella live, altrimenti l'ultima aperta, altrimenti
     * una nuova sessione avviata subito — il collaudo non deve fermarsi ad
     * aspettare un gesto manuale dalla dashboard.
     */
    private function resolveAuction(): Auction
    {
        $auction = Auction::live() ?? Auction::current();

        if ($auction === null) {
            $auction = Auction::query()->create([
                'name' => 'Asta simulata '.now()->year,
                'status' => AuctionStatus::Setup,
            ]);
            $this->components->info("Aperta una nuova sessione: {$auction->name}");
        }

        if (! $auction->isLive()) {
            $auction->start();
            $this->components->info('Asta avviata.');
        }

        return $auction->fresh();
    }

    /**
     * Un evento: calcola chi vince e a quanto, registra l'acquisizione sul
     * funnel vero, riporta a schermo l'esito ed eventuali promozioni di piano.
     *
     * @return bool false se l'evento non è stato registrato (nessuno poteva
     *              comprare quel giocatore in quel ruolo/con quei crediti) — non
     *              conta come evento consumato, se ne prova un altro.
     */
    private function simulateEvent(Auction $auction, Player $player, Collection $teams): bool
    {
        $role = $player->role->value;
        $valuation = Valuation::query()->where('player_id', $player->id)->first();
        $adjustedValue = (float) ($valuation->adjusted_value ?? max(1, $player->quotazione));

        $state = LeagueState::load($auction);
        $me = $state->myTeam();

        $opponents = collect($state->opponents())->filter(
            fn (array $team) => ($team['open_slots_by_role'][$role] ?? 0) > 0 && $team['credits_remaining'] >= 1,
        );

        $meCanBuy = $me['id'] !== null
            && ($me['open_slots_by_role'][$role] ?? 0) > 0
            && $me['credits_remaining'] >= 1;

        if (! $meCanBuy && $opponents->isEmpty()) {
            $this->log['skipped']++;

            return false;
        }

        $winningOpponent = null;
        $marketPrice = 0;

        foreach ($opponents as $team) {
            $offer = max(1, min((int) round($adjustedValue * $this->randomFactor()), $team['credits_remaining']));

            if ($offer > $marketPrice) {
                $marketPrice = $offer;
                $winningOpponent = $team;
            }
        }

        if ($winningOpponent === null && $meCanBuy) {
            // Nessun avversario ha uno slot aperto in questo ruolo: prezzo
            // morbido, non c'è spinta al rialzo.
            $marketPrice = max(1, min((int) round($adjustedValue * 0.5), $me['credits_remaining']));
        }

        $myMaxBid = $meCanBuy ? $this->myMaxBidFor($auction, $player) : -1;

        $winnerTeamId = null;
        $price = $marketPrice;

        if ($meCanBuy && $marketPrice <= $myMaxBid && $marketPrice <= $me['credits_remaining']) {
            // "Andrea segue il piano": prendo il giocatore se il prezzo di
            // mercato resta dentro il tetto che il piano mi assegna.
            $winnerTeamId = $me['id'];
        } elseif ($winningOpponent !== null) {
            $winnerTeamId = $winningOpponent['id'];
        } elseif ($meCanBuy) {
            $winnerTeamId = $me['id'];
            $price = min($marketPrice, $me['credits_remaining']);
        } else {
            $this->log['skipped']++;

            return false;
        }

        $plan = $auction->latestReadyPlan();
        $wasSlotTarget = $plan !== null
            ? PlanSlot::query()
                ->where('plan_id', $plan->id)
                ->where('player_id', $player->id)
                ->where('slot_status', SlotStatus::Pending)
                ->first(['id', 'role', 'slot_index'])
            : null;

        DB::transaction(fn () => Acquisition::query()->create([
            'auction_id' => $auction->id,
            'player_id' => $player->id,
            'team_id' => $winnerTeamId,
            'price' => $price,
        ]));

        $this->log['events']++;
        $isMine = $winnerTeamId === $me['id'];
        $isMine ? $this->log['mine']++ : $this->log['opponents']++;

        // Testo semplice, niente tag di stile a ridosso di caratteri unicode:
        // il parser dei tag di Symfony Console inghiotte "→" se sta incollato
        // a un tag di stile precedente, scoperto durante il collaudo di
        // questo comando.
        $teamName = $teams->firstWhere('id', $winnerTeamId)?->name ?? '?';
        $this->line(sprintf(
            ' %3d. %-28s (%s) -> %s per %d%s',
            $this->log['events'],
            $player->name,
            $role,
            $teamName,
            $price,
            $isMine ? '  [mia squadra]' : '',
        ));

        if ($wasSlotTarget !== null && ! $isMine) {
            $after = PlanSlot::query()->find($wasSlotTarget->id);

            if ($after !== null && $after->slot_status === SlotStatus::Lost) {
                $this->log['promotions']++;
                $newName = $after->player_id !== null ? Player::query()->find($after->player_id)?->name : null;

                $this->line(sprintf(
                    '      -> piano: slot %s%d sfumato, promosso %s',
                    $after->role->value,
                    $after->slot_index,
                    $newName ?? 'nessuna alternativa',
                ));
            }
        }

        return true;
    }

    /**
     * Un giocatore disponibile, pesato per fascia: tier 1 (i big) pesa 5 volte
     * tanto un tier 5, senza estrazioni ripetute perché lo stato passa ad
     * `acquired` non appena un evento lo assegna.
     */
    private function pickWeightedPlayer(): ?Player
    {
        $rows = Player::query()
            ->leftJoin('valuations', 'valuations.player_id', '=', 'players.id')
            ->where('players.status', PlayerStatus::Available)
            ->select(['players.id', 'valuations.tier'])
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $weights = $rows->map(fn ($row) => max(1, 6 - (int) ($row->tier ?? 5)));
        $total = (int) $weights->sum();
        $roll = random_int(1, max(1, $total));
        $cursor = 0;

        foreach ($rows as $index => $row) {
            $cursor += $weights[$index];

            if ($roll <= $cursor) {
                return Player::query()->find($row->id);
            }
        }

        return Player::query()->find($rows->last()->id);
    }

    private function randomFactor(): float
    {
        return random_int(80, 130) / 100;
    }

    /**
     * Il tetto che il piano assegna a questo giocatore, se ce l'ha (titolare o
     * ex titolare di uno slot); altrimenti il `max_bid` generale del motore.
     */
    private function myMaxBidFor(Auction $auction, Player $player): int
    {
        $plan = $auction->latestReadyPlan();

        if ($plan !== null) {
            $slot = PlanSlot::query()
                ->where('plan_id', $plan->id)
                ->where(fn ($query) => $query->where('player_id', $player->id)->orWhere('original_player_id', $player->id))
                ->first();

            if ($slot !== null) {
                return (int) $slot->max_price;
            }
        }

        $valuation = Valuation::query()->where('player_id', $player->id)->first();

        return (int) ($valuation->max_bid ?? 0);
    }
}

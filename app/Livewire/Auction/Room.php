<?php

namespace App\Livewire\Auction;

use App\Enums\PlanStatus;
use App\Enums\PlanTrigger;
use App\Enums\PlayerStatus;
use App\Models\Acquisition;
use App\Models\Auction;
use App\Models\LeagueConfig;
use App\Models\Plan;
use App\Models\PlanSlot;
use App\Models\Player;
use App\Models\Signal;
use App\Models\Team;
use App\Models\Valuation;
use App\Services\LeagueState;
use App\Services\PlayerSearch;
use App\Services\Replanner;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * La sala d'asta (briefing §8.2): la schermata che decide il successo del
 * progetto.
 *
 * Vincolo che governa ogni riga di questo file: **nessun calcolo pesante e
 * nessuna chiamata AI nel percorso sincrono**. Tutto ciò che serve a decidere
 * — `max_bid`, tier, valore corretto, scarsità — è già scritto in `valuations`
 * dal motore deterministico, e il piano è già scritto in `plan_slots`. Qui si
 * legge e si scrive un'aggiudicazione, punto. Il ricalcolo e il replan partono
 * dopo il commit, in coda (design §3).
 *
 * La registrazione vive in `record()` e non nel client: il browser manda un
 * solo messaggio — giocatore, prezzo, squadra — e tutto il resto è l'observer
 * di `Acquisition`, che è lo stesso percorso dei test e del simulatore.
 */
class Room extends Component
{
    /** Testo digitato nella search, che in questa pagina è sempre a fuoco. */
    public string $search = '';

    /** Giocatore selezionato: è ciò che fa comparire la scheda decisione. */
    public ?int $selectedId = null;

    /** Prezzo battuto, come stringa: arriva da un input e può essere vuoto. */
    public string $price = '';

    /** Ultima aggiudicazione registrata, l'unica annullabile dalla sala. */
    public ?int $lastAcquisitionId = null;

    /** @var array{message: string, tone: string}|null */
    public ?array $toast = null;

    /**
     * Impronta dello stato osservato dal polling. Cambia solo quando cambia
     * qualcosa che si vede: finché resta uguale il componente non si ridisegna.
     */
    public string $stateHash = '';

    /** Colonna visibile sotto lg, dove le tre colonne non ci stanno. */
    public string $tab = 'asta';

    public function mount(): void
    {
        $this->stateHash = $this->currentStateHash();
    }

    /**
     * Il battito leggero della sala (spec: wire:poll.3s).
     *
     * Tre conteggi aggregati, nessuna riga caricata: se l'impronta non è
     * cambiata si salta il render, così una sala aperta e ferma non ridisegna
     * venti volte al minuto un piano da 25 slot.
     */
    public function syncState(): void
    {
        $hash = $this->currentStateHash();

        if ($hash === $this->stateHash) {
            $this->skipRender();

            return;
        }

        $this->stateHash = $hash;
    }

    public function select(int $playerId): void
    {
        $this->selectedId = $playerId;
        $this->price = '';
        $this->toast = null;
    }

    public function clearSelection(): void
    {
        $this->selectedId = null;
        $this->price = '';
    }

    public function resetSearch(): void
    {
        $this->search = '';
        $this->selectedId = null;
        $this->price = '';
    }

    /**
     * Registra l'aggiudicazione. È il gesto centrale della serata.
     *
     * L'ordine degli effetti è quello della specifica, e non è deciso qui:
     * creare la riga `acquisitions` fa scattare AcquisitionObserver, che
     * dentro la stessa transazione porta il giocatore ad `acquired` e applica
     * la promozione deterministica sul piano; solo dopo il commit partono il
     * ricalcolo delle valutazioni e il replan con debounce.
     */
    public function record(int $teamId): void
    {
        $auction = $this->auction();

        if (! $auction instanceof Auction || ! $auction->isLive()) {
            $this->fail('L\'asta non è in corso: nessuna registrazione possibile.');

            return;
        }

        $player = $this->selectedId !== null
            ? Player::query()->find($this->selectedId)
            : null;

        if ($player === null) {
            $this->fail('Nessun giocatore selezionato.');

            return;
        }

        if ($player->status !== PlayerStatus::Available) {
            $this->fail("{$player->name} non è disponibile: è già stato assegnato.");

            return;
        }

        $price = (int) $this->price;

        if ($price < 1) {
            $this->fail('Il prezzo deve essere almeno 1 credito.');

            return;
        }

        $team = Team::query()->find($teamId);

        if ($team === null) {
            $this->fail('Squadra sconosciuta.');

            return;
        }

        $state = LeagueState::load($auction);
        $snapshot = $state->teams[$team->id] ?? null;

        // Due controlli che non sono pignoleria: una squadra non può spendere
        // crediti che non ha né riempire uno slot che non ha, e una riga così
        // falserebbe da lì in avanti inflazione, max_bid e piano.
        if ($snapshot !== null && $price > $snapshot['credits_remaining']) {
            $this->fail("{$team->name} ha {$snapshot['credits_remaining']} crediti: non può pagarne {$price}.");

            return;
        }

        if ($snapshot !== null && ($snapshot['open_slots_by_role'][$player->role->value] ?? 0) < 1) {
            $this->fail("{$team->name} ha già completato il reparto {$player->role->value}.");

            return;
        }

        $acquisition = DB::transaction(fn () => Acquisition::query()->create([
            'auction_id' => $auction->id,
            'player_id' => $player->id,
            'team_id' => $team->id,
            'price' => $price,
        ]));

        $this->lastAcquisitionId = $acquisition->id;
        $this->toast = [
            'message' => "{$player->name} → {$team->name} per {$price}",
            'tone' => $team->is_mine ? 'mine' : 'opponent',
        ];

        $this->resetSearch();
        $this->stateHash = $this->currentStateHash();
    }

    /**
     * Annulla l'ultima aggiudicazione (spec: un tasto, `U`).
     *
     * Solo l'ultima, e solo finché non ne arriva un'altra: un undo profondo
     * durante l'asta è un modo per perdere il filo di cosa è vero. Il soft
     * delete fa il resto tramite l'observer — crediti (che sono derivati),
     * stato del giocatore, e il piano riportato esattamente com'era, promozione
     * inclusa.
     */
    public function undo(): void
    {
        if ($this->lastAcquisitionId === null) {
            $this->fail('Niente da annullare.');

            return;
        }

        $acquisition = Acquisition::query()
            ->with('player:id,name', 'team:id,name')
            ->find($this->lastAcquisitionId);

        if ($acquisition === null) {
            $this->fail('Quell\'aggiudicazione non esiste più.');
            $this->lastAcquisitionId = null;

            return;
        }

        $label = $acquisition->player?->name ?? 'Giocatore';

        DB::transaction(fn () => $acquisition->delete());

        $this->lastAcquisitionId = null;
        $this->toast = ['message' => "Annullato: {$label} torna disponibile.", 'tone' => 'undo'];
        $this->resetSearch();
        $this->stateHash = $this->currentStateHash();
    }

    /**
     * "Ricalcola ora": scavalca il debounce. Resta comunque un dispatch in
     * coda — dalla sala non parte mai un processo `claude` sincrono.
     */
    public function recomputeNow(): void
    {
        $auction = $this->auction();

        if (! $auction instanceof Auction) {
            $this->fail('Nessuna sessione d\'asta.');

            return;
        }

        $plan = app(Replanner::class)->launch($auction, PlanTrigger::Manual);

        $this->toast = $plan !== null
            ? ['message' => 'Ricalcolo del piano avviato.', 'tone' => 'info']
            : ['message' => 'Un ricalcolo è già in corso.', 'tone' => 'info'];

        $this->stateHash = $this->currentStateHash();
    }

    public function startAuction(): void
    {
        $auction = Auction::current();

        if (! $auction instanceof Auction) {
            $this->fail('Apri prima una sessione d\'asta dalla dashboard.');

            return;
        }

        $auction->start();

        $this->toast = ['message' => 'Asta avviata. Buona fortuna.', 'tone' => 'info'];
        $this->stateHash = $this->currentStateHash();
    }

    public function closeAuction(): void
    {
        Auction::live()?->close();

        $this->toast = ['message' => 'Asta chiusa.', 'tone' => 'info'];
        $this->stateHash = $this->currentStateHash();
    }

    public function render(): View
    {
        $auction = $this->auction();
        $plan = $auction?->latestReadyPlan();
        $state = LeagueState::load($auction);

        return view('livewire.auction.room', [
            'auction' => $auction,
            'isLive' => $auction?->isLive() ?? false,
            'plan' => $plan,
            'planGenerating' => $this->planGenerating($auction, $plan),
            'planByRole' => $this->planByRole($plan),
            'state' => $state,
            'me' => $state->myTeam(),
            'teams' => $this->teamsWithHotkeys($state),
            'results' => $this->results(),
            'card' => $this->card($auction, $plan, $state),
            'roles' => array_keys(LeagueConfig::DEFAULT_SLOTS),
        ]);
    }

    /**
     * La sessione su cui lavora la sala: quella in corso, o quella aperta in
     * preparazione — che la sala mostra ma su cui non registra nulla.
     */
    private function auction(): ?Auction
    {
        return Auction::live() ?? Auction::current();
    }

    /**
     * Risultati della ricerca fuzzy, con il valore già accanto: da qui si
     * sceglie con le frecce senza leggere altro.
     *
     * @return array<int, array<string, mixed>>
     */
    private function results(): array
    {
        $query = trim($this->search);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $found = app(PlayerSearch::class)->search($query, 8);

        if ($found->isEmpty()) {
            return [];
        }

        $players = $found->pluck('player');
        $valuations = Valuation::query()
            ->whereIn('player_id', $players->pluck('id'))
            ->get()
            ->keyBy('player_id');

        return $players->map(fn (Player $player) => [
            'id' => $player->id,
            'name' => $player->name,
            'role' => $player->role->value,
            'real_team' => $player->real_team,
            'status' => $player->status->value,
            'max_bid' => (int) ($valuations[$player->id]->max_bid ?? 0),
            'tier' => (int) ($valuations[$player->id]->tier ?? 0),
        ])->values()->all();
    }

    /**
     * La scheda decisione: tutto ciò che serve a decidere in tre secondi, in
     * poche query sulle tabelle già calcolate.
     *
     * @return array<string, mixed>|null
     */
    private function card(?Auction $auction, ?Plan $plan, LeagueState $state): ?array
    {
        if ($this->selectedId === null) {
            return null;
        }

        $player = Player::query()->find($this->selectedId);

        if ($player === null) {
            return null;
        }

        $valuation = Valuation::query()->where('player_id', $player->id)->first();
        $stats = $player->season_stats ?? [];

        $signals = Signal::query()
            ->active()
            ->where('player_id', $player->id)
            ->where('needs_review', false)
            ->orderByDesc('event_date')
            ->limit(8)
            ->get(['id', 'type', 'summary', 'impact']);

        return [
            'player' => $player,
            'valuation' => $valuation,
            'max_bid' => (int) ($valuation->max_bid ?? 0),
            'ceiling' => $this->myCeiling($state),
            'stats' => [
                'fantamedia' => $stats['Fm'] ?? null,
                'media_voto' => $stats['Mv'] ?? null,
                'presenze' => $stats['Pv'] ?? null,
                'ammonizioni' => $stats['Am'] ?? null,
            ],
            'signals' => $signals,
            'plan' => $this->planPositionOf($plan, $player->id),
            'owner' => $this->ownerOf($auction, $player->id),
        ];
    }

    /**
     * Il tetto aritmetico del briefing (§9): crediti residui meno gli slot
     * ancora da riempire, più uno. Il motore lo applica già a `max_bid`; qui
     * si mostra accanto perché in asta si crede a un numero solo se si vede
     * da dove esce.
     *
     * Prende lo `$state` già caricato da `render()` invece di ricaricarlo:
     * `LeagueState::load()` costa due query aggregate, e prima di questo fix
     * girava due volte per richiesta ogni volta che una scheda era aperta
     * (performance pass Fase 5).
     */
    private function myCeiling(LeagueState $state): int
    {
        $me = $state->myTeam();

        return max(0, $me['credits_remaining'] - $me['open_slots_total'] + 1);
    }

    /**
     * Dove sta questo giocatore nel piano, e che succede se sfuma.
     *
     * @return array<string, mixed>|null
     */
    private function planPositionOf(?Plan $plan, int $playerId): ?array
    {
        if ($plan === null) {
            return null;
        }

        $slot = PlanSlot::query()
            ->where('plan_id', $plan->id)
            ->where(fn ($query) => $query->where('player_id', $playerId)->orWhere('original_player_id', $playerId))
            ->first();

        if ($slot === null) {
            // Può comunque essere l'alternativa di qualcuno: è un'informazione
            // che cambia la decisione, quindi va cercata.
            $slot = PlanSlot::query()
                ->where('plan_id', $plan->id)
                ->whereJsonContains('alternatives', ['player_id' => $playerId])
                ->first();

            if ($slot === null) {
                return null;
            }

            return [
                'label' => sprintf('Alternativa dello slot %s%d', $slot->role->value, $slot->slot_index),
                'target_price' => $this->alternativePrice($slot, $playerId),
                'is_starter' => false,
                'successor' => null,
                'slot_status' => $slot->slot_status->value,
            ];
        }

        return [
            'label' => sprintf('Slot %s%d', $slot->role->value, $slot->slot_index),
            'target_price' => (int) $slot->target_price,
            'max_price' => (int) $slot->max_price,
            'is_starter' => true,
            'successor' => $this->firstAlternativeName($slot),
            'slot_status' => $slot->slot_status->value,
        ];
    }

    private function alternativePrice(PlanSlot $slot, int $playerId): int
    {
        foreach ($slot->alternatives ?? [] as $alternative) {
            if ((int) ($alternative['player_id'] ?? 0) === $playerId) {
                return (int) ($alternative['target_price'] ?? 1);
            }
        }

        return 1;
    }

    /**
     * Chi subentra se questo nome sfuma: il primo ripiego ancora disponibile,
     * cioè esattamente quello che promuoverebbe PlanSlotPromoter.
     */
    private function firstAlternativeName(PlanSlot $slot): ?string
    {
        $ids = array_map(fn (array $a) => (int) ($a['player_id'] ?? 0), $slot->alternatives ?? []);

        if ($ids === []) {
            return null;
        }

        $available = Player::query()
            ->whereIn('id', $ids)
            ->where('status', PlayerStatus::Available)
            ->pluck('name', 'id');

        foreach ($ids as $id) {
            if (isset($available[$id])) {
                return $available[$id];
            }
        }

        return null;
    }

    /**
     * @return array{team: string, price: int}|null
     */
    private function ownerOf(?Auction $auction, int $playerId): ?array
    {
        if ($auction === null) {
            return null;
        }

        $row = Acquisition::query()
            ->join('teams', 'teams.id', '=', 'acquisitions.team_id')
            ->where('acquisitions.auction_id', $auction->id)
            ->where('acquisitions.player_id', $playerId)
            ->first(['teams.name as team_name', 'acquisitions.price as price']);

        return $row === null ? null : ['team' => $row->team_name, 'price' => (int) $row->price];
    }

    /**
     * Il piano vivo, per reparto, con i nomi già risolti: la view non fa query.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function planByRole(?Plan $plan): array
    {
        if ($plan === null) {
            return [];
        }

        $slots = PlanSlot::query()
            ->where('plan_id', $plan->id)
            ->orderBy('slot_index')
            ->get();

        $ids = $slots
            ->flatMap(fn (PlanSlot $slot) => array_merge(
                $slot->involvedPlayerIds(),
                $slot->original_player_id !== null ? [(int) $slot->original_player_id] : [],
            ))
            ->unique();

        $names = Player::query()->whereIn('id', $ids)->pluck('name', 'id');
        $byRole = [];

        foreach (array_keys(LeagueConfig::DEFAULT_SLOTS) as $role) {
            foreach ($slots->where('role.value', $role) as $slot) {
                $byRole[$role][] = [
                    'id' => $slot->id,
                    'index' => (int) $slot->slot_index,
                    'status' => $slot->slot_status,
                    'player_id' => $slot->player_id,
                    'name' => $names[$slot->player_id] ?? null,
                    'lost_name' => $slot->original_player_id !== null
                        ? ($names[$slot->original_player_id] ?? null)
                        : null,
                    'target_price' => (int) $slot->target_price,
                    'max_price' => (int) $slot->max_price,
                ];
            }
        }

        return $byRole;
    }

    /**
     * Le squadre con il loro tasto rapido: `0` sono io, `1`-`9` gli avversari
     * in ordine di id — un ordine stabile, perché il tasto imparato stasera
     * deve valere anche fra due ore.
     *
     * @return array<int, array<string, mixed>>
     */
    private function teamsWithHotkeys(LeagueState $state): array
    {
        $teams = [];
        $hotkey = 1;

        foreach ($state->teams as $team) {
            $teams[] = $team + [
                'hotkey' => $team['is_mine'] ? '0' : (string) $hotkey,
                'max_bid' => max(0, $team['credits_remaining'] - $team['open_slots_total'] + 1),
            ];

            if (! $team['is_mine']) {
                $hotkey++;
            }
        }

        // La mia squadra prima di tutte: è quella che si guarda di riflesso.
        usort($teams, fn (array $a, array $b) => ($b['is_mine'] <=> $a['is_mine']) ?: ($a['id'] <=> $b['id']));

        return $teams;
    }

    private function planGenerating(?Auction $auction, ?Plan $plan): bool
    {
        if ($auction === null) {
            return false;
        }

        return $auction->plans()
            ->where('status', PlanStatus::Generating)
            ->where('version', '>', $plan?->version ?? 0)
            ->exists();
    }

    /**
     * Impronta di ciò che il polling deve accorgersi che è cambiato: la
     * versione del piano, lo stato dei suoi slot e il numero di aggiudicazioni.
     * Tre aggregati, nessuna riga letta.
     */
    private function currentStateHash(): string
    {
        $auction = $this->auction();

        if ($auction === null) {
            return 'no-auction';
        }

        $plan = Plan::query()
            ->where('auction_id', $auction->id)
            ->selectRaw('max(version) as v, count(*) as n, max(updated_at) as t')
            ->first();

        $slots = PlanSlot::query()
            ->join('plans', 'plans.id', '=', 'plan_slots.plan_id')
            ->where('plans.auction_id', $auction->id)
            ->selectRaw('count(*) as n, max(plan_slots.updated_at) as t')
            ->first();

        $acquisitions = Acquisition::query()
            ->where('auction_id', $auction->id)
            ->selectRaw('count(*) as n, max(updated_at) as t')
            ->first();

        return md5(implode('|', [
            $auction->status->value,
            $plan?->v, $plan?->n, $plan?->t,
            $slots?->n, $slots?->t,
            $acquisitions?->n, $acquisitions?->t,
        ]));
    }

    private function fail(string $message): void
    {
        $this->toast = ['message' => $message, 'tone' => 'error'];
    }
}

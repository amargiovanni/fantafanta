<?php

use App\Enums\PlanStatus;
use App\Enums\PlanTrigger;
use App\Enums\PlayerStatus;
use App\Enums\SlotStatus;
use App\Jobs\RunClaudeTask;
use App\Jobs\ScheduleReplan;
use App\Livewire\Auction\Room;
use App\Models\Acquisition;
use App\Models\Auction;
use App\Models\Plan;
use App\Models\PlanSlot;
use App\Models\Player;
use App\Services\LeagueState;
use App\Services\PlanWriter;
use App\Services\Replanner;
use App\Services\ValuationEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Gli acceptance criteria della Fase 3 (briefing §9), uno per uno, nella forma
 * in cui il PO li verificherà.
 *
 * Percorrono la sala d'asta dalla parte dell'utente: si cerca un nome, si
 * batte un prezzo, si assegna a una squadra — e si controlla che tutto quello
 * che deve succedere sia successo, nell'ordine giusto e senza che nessuna
 * chiamata all'AI sia finita nel percorso sincrono.
 *
 * Nessun test qui dentro fa girare `claude` per davvero: la coda è finta e i
 * job si ispezionano.
 */
beforeEach(function () {
    Queue::fake();
    Cache::flush();

    configuraLega();
    $this->squadre = registraSquadre();
    $this->io = $this->squadre->firstWhere('is_mine', true);
    $this->avversario = $this->squadre->firstWhere('is_mine', false);

    $this->auction = Auction::factory()->live()->create();
    $this->listone = listonePerPiano();

    app(ValuationEngine::class)->recompute($this->auction);

    $this->piano = app(PlanWriter::class)->save(
        $this->auction,
        pianoValido($this->listone, prezzo: 15),
        'Piano di partenza.',
    );
});

function slotDelPiano(Plan $piano, string $ruolo, int $indice): PlanSlot
{
    return $piano->slots()->where('role', $ruolo)->where('slot_index', $indice)->firstOrFail();
}

it('registra un\'aggiudicazione da tastiera e riporta la sala alla ricerca', function () {
    $giocatore = $this->listone['A'][8];

    $componente = Livewire::test(Room::class)
        ->set('search', $giocatore->name)
        ->call('select', $giocatore->id)
        ->assertSet('selectedId', $giocatore->id)
        ->set('price', '31')
        ->call('record', $this->avversario->id);

    // Il focus torna alla search: lato server lo si vede dal fatto che la sala
    // è di nuovo vuota e pronta per il nome successivo (il fuoco vero e proprio
    // lo rimette Alpine, che osserva selectedId).
    $componente
        ->assertSet('selectedId', null)
        ->assertSet('search', '')
        ->assertSet('price', '')
        ->assertSee("{$giocatore->name} → {$this->avversario->name} per 31");

    expect(Acquisition::query()->count())->toBe(1)
        ->and($giocatore->fresh()->status)->toBe(PlayerStatus::Acquired);
});

it('aggiorna crediti e slot dell\'avversario subito, e programma il replan con debounce', function () {
    $giocatore = $this->listone['C'][9];

    Livewire::test(Room::class)
        ->call('select', $giocatore->id)
        ->set('price', '40')
        ->call('record', $this->avversario->id);

    $stato = LeagueState::load($this->auction);
    $avversario = $stato->teams[$this->avversario->id];

    expect($avversario['credits_remaining'])->toBe(460)
        ->and($avversario['credits_spent'])->toBe(40)
        ->and($avversario['open_slots_by_role']['C'])->toBe(7)
        ->and($avversario['acquired_by_role']['C'])->toBe(1);

    // Il replan è programmato, non eseguito: il percorso sincrono della sala
    // non ha fatto partire nessun processo `claude`.
    Queue::assertPushed(ScheduleReplan::class, 1);
    Queue::assertNotPushed(RunClaudeTask::class);
});

it('assegna lo slot del piano quando il giocatore lo prendo io', function () {
    $slot = slotDelPiano($this->piano, 'D', 1);
    $titolare = Player::query()->findOrFail($slot->player_id);

    Livewire::test(Room::class)
        ->call('select', $titolare->id)
        ->set('price', '22')
        ->call('record', $this->io->id);

    $aggiornato = slotDelPiano($this->piano, 'D', 1);

    expect($aggiornato->slot_status)->toBe(SlotStatus::Acquired)
        ->and($aggiornato->player_id)->toBe($titolare->id)
        ->and($aggiornato->target_price)->toBe(22)
        ->and($aggiornato->original_player_id)->toBeNull();
});

it('promuove l\'alternativa PRIMA del replan quando un mio target lo prende un altro', function () {
    $slot = slotDelPiano($this->piano, 'A', 2);
    $titolare = $slot->player_id;
    $primaAlternativa = $slot->alternatives[0]['player_id'];

    Livewire::test(Room::class)
        ->call('select', $titolare)
        ->set('price', '55')
        ->call('record', $this->avversario->id);

    $aggiornato = slotDelPiano($this->piano, 'A', 2);

    expect($aggiornato->slot_status)->toBe(SlotStatus::Lost)
        ->and($aggiornato->player_id)->toBe($primaAlternativa)
        ->and($aggiornato->original_player_id)->toBe($titolare);

    // Il punto dell'acceptance: la promozione è già avvenuta e nessun replan è
    // ancora partito. La rete di sicurezza non dipende dall'AI.
    Queue::assertNotPushed(RunClaudeTask::class);
});

it('non mostra mai un max_bid superiore ai crediti residui meno gli slot aperti più uno', function () {
    // Situazione stretta ma ancora giocabile: un colpo grosso all'inizio, e da
    // lì in poi ogni slot aperto si porta via un credito del tetto.
    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $this->listone['A'][10]->id,
        'team_id' => $this->io->id,
        'price' => 450,
    ]);

    app(ValuationEngine::class)->recompute($this->auction);

    $me = LeagueState::load($this->auction)->myTeam();
    $tetto = $me['credits_remaining'] - $me['open_slots_total'] + 1;

    expect($tetto)->toBeGreaterThan(0);

    $giocatore = $this->listone['C'][10];

    $vista = Livewire::test(Room::class)
        ->call('select', $giocatore->id)
        ->viewData('card');

    expect($vista['ceiling'])->toBe($tetto)
        ->and($vista['max_bid'])->toBeLessThanOrEqual($tetto)
        ->and($vista['max_bid'])->toBeLessThanOrEqual($me['credits_remaining']);
});

it('l\'undo ripristina crediti, stato del giocatore e lo slot promosso com\'era', function () {
    $slot = slotDelPiano($this->piano, 'D', 3);
    $titolare = $slot->player_id;
    $alternativeIniziali = $slot->alternatives;

    $componente = Livewire::test(Room::class)
        ->call('select', $titolare)
        ->set('price', '48')
        ->call('record', $this->avversario->id);

    expect(slotDelPiano($this->piano, 'D', 3)->slot_status)->toBe(SlotStatus::Lost);

    $componente->call('undo');

    $ripristinato = slotDelPiano($this->piano, 'D', 3);
    $stato = LeagueState::load($this->auction);

    expect($ripristinato->slot_status)->toBe(SlotStatus::Pending)
        ->and($ripristinato->player_id)->toBe($titolare)
        ->and($ripristinato->original_player_id)->toBeNull()
        ->and($ripristinato->target_price)->toBe($slot->target_price)
        ->and($ripristinato->alternatives)->toBe($alternativeIniziali)
        ->and(Player::query()->find($titolare)->status)->toBe(PlayerStatus::Available)
        ->and($stato->teams[$this->avversario->id]['credits_remaining'])->toBe(500)
        ->and($stato->teams[$this->avversario->id]['open_slots_by_role']['D'])->toBe(8)
        ->and(Acquisition::query()->count())->toBe(0)
        ->and(Acquisition::withTrashed()->count())->toBe(1);
});

it('una raffica di tre acquisti fa partire un solo replan', function () {
    Carbon::setTestNow($inizio = Carbon::parse('2026-08-07 21:00:00'));

    $bersagli = [$this->listone['D'][9], $this->listone['C'][11], $this->listone['A'][9]];

    foreach ($bersagli as $indice => $giocatore) {
        Carbon::setTestNow($inizio->copy()->addSeconds($indice * 5));

        Acquisition::factory()->create([
            'auction_id' => $this->auction->id,
            'player_id' => $giocatore->id,
            'team_id' => $this->avversario->id,
            'price' => 10,
        ]);
    }

    // Tre eventi, tre risvegli programmati: uno per acquisto.
    Queue::assertPushed(ScheduleReplan::class, 3);

    /** @var array<int, ScheduleReplan> $risvegli */
    $risvegli = collect(Queue::pushed(ScheduleReplan::class))->all();

    // Venti secondi dopo l'ultimo acquisto i job si svegliano, in ordine.
    Carbon::setTestNow($inizio->copy()->addSeconds(30));

    foreach ($risvegli as $job) {
        $job->handle(app(Replanner::class));
    }

    // Solo il più giovane ha fatto partire il run: gli altri due hanno visto un
    // evento più recente della propria schedulazione e sono usciti.
    Queue::assertPushed(RunClaudeTask::class, 1);

    expect(Plan::query()->where('status', PlanStatus::Generating)->count())->toBe(1);

    Carbon::setTestNow();
});

it('crea la riga del piano in stato generating all\'avvio del replan e la marca failed se il run muore', function () {
    $piano = app(Replanner::class)->launch($this->auction, PlanTrigger::Manual);

    expect($piano)->not->toBeNull()
        ->and($piano->status)->toBe(PlanStatus::Generating)
        ->and($piano->version)->toBe($this->piano->version + 1);

    // Da qui in poi la UI sa che sta girando qualcosa, e lo stesso vale per il
    // tool MCP get_current_plan.
    expect(Livewire::test(Room::class)->viewData('planGenerating'))->toBeTrue();

    Queue::assertPushed(RunClaudeTask::class, function (RunClaudeTask $job) use ($piano) {
        return $job->task === 'replan'
            && $job->promptFile === 'replan.md'
            && $job->context['plan_id'] === $piano->id;
    });

    // Il run muore: la riga non può restare `generating` per sempre.
    (new RunClaudeTask(
        task: 'replan',
        promptFile: 'replan.md',
        context: ['auction_id' => $this->auction->id, 'plan_id' => $piano->id],
    ))->failed(new RuntimeException('claude è uscito con codice 1.'));

    expect($piano->fresh()->status)->toBe(PlanStatus::Failed)
        ->and(Livewire::test(Room::class)->viewData('planGenerating'))->toBeFalse();
});

it('il piano che arriva occupa la riga generating invece di aggiungersene una accanto', function () {
    $prenotato = app(Replanner::class)->launch($this->auction, PlanTrigger::Acquisition);

    $nuovo = app(PlanWriter::class)->save(
        $this->auction,
        pianoValido($this->listone, prezzo: 12),
        'Rientrato nel budget dopo il primo giro.',
        PlanTrigger::Acquisition,
    );

    expect($nuovo->id)->toBe($prenotato->id)
        ->and($nuovo->version)->toBe($prenotato->version)
        ->and($nuovo->status)->toBe(PlanStatus::Ready)
        ->and(Plan::query()->where('auction_id', $this->auction->id)->count())->toBe(2)
        ->and(Livewire::test(Room::class)->viewData('planGenerating'))->toBeFalse();
});

it('la sala non registra nulla se l\'asta non è in corso', function () {
    $this->auction->close();

    $giocatore = $this->listone['P'][5];

    Livewire::test(Room::class)
        ->call('select', $giocatore->id)
        ->set('price', '10')
        ->call('record', $this->avversario->id)
        ->assertSee('L\'asta non è in corso');

    expect(Acquisition::query()->count())->toBe(0);
});

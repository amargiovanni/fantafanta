<?php

use App\Enums\AuctionStatus;
use App\Enums\PlayerRole;
use App\Jobs\RunClaudeTask;
use App\Livewire\Auction\Room;
use App\Models\Acquisition;
use App\Models\Auction;
use App\Models\Player;
use App\Services\PlanWriter;
use App\Services\ValuationEngine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * La sala d'asta vista dai suoi bordi: cosa succede quando l'input è
 * sbagliato, quando l'asta non è aperta, quando il giocatore è già di
 * qualcun altro.
 *
 * Gli acceptance veri e propri stanno in Phase3AcceptanceTest; qui ci sono i
 * guard-rail, che sono quelli che salvano la serata quando alle 23 si sbaglia
 * a digitare.
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

it('renders the auction room route', function () {
    $this->get(route('asta'))->assertOk();
});

it('mostra il piano vivo, la lega e la barra della mia squadra', function () {
    Livewire::test(Room::class)
        ->assertSee('Piano vivo')
        ->assertSee('Lega')
        ->assertSee('Crediti residui')
        ->assertSee('La mia squadra')
        ->assertSee("Piano v{$this->piano->version}");
});

it('cerca i giocatori mentre si digita e ne mostra il max_bid', function () {
    $giocatore = $this->listone['A'][0];

    Livewire::test(Room::class)
        ->set('search', $giocatore->name)
        ->assertSee($giocatore->name)
        ->assertSee((string) valutazione($giocatore)->max_bid);
});

it('non cerca nulla sotto i due caratteri', function () {
    expect(Livewire::test(Room::class)->set('search', 'a')->viewData('results'))->toBe([]);
});

it('mostra nella scheda chi subentra se il titolare sfuma', function () {
    $slot = $this->piano->slots()->where('role', 'C')->where('slot_index', 1)->firstOrFail();
    $alternativa = Player::query()->findOrFail($slot->alternatives[0]['player_id']);

    Livewire::test(Room::class)
        ->call('select', $slot->player_id)
        ->assertSee('Slot C1')
        ->assertSee('se sfuma subentra')
        ->assertSee($alternativa->name);
});

it('segnala un giocatore fuori dal piano invece di inventarsi uno slot', function () {
    $fuoriPiano = giocatore(PlayerRole::Attaccante, nome: 'Nessuno Dellalista');

    app(ValuationEngine::class)->recompute($this->auction);

    Livewire::test(Room::class)
        ->call('select', $fuoriPiano->id)
        ->assertSee('Non nel piano');
});

it('mostra a chi è andato un giocatore già assegnato e non lo fa registrare', function () {
    $giocatore = $this->listone['P'][4];

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $giocatore->id,
        'team_id' => $this->avversario->id,
        'price' => 12,
    ]);

    Livewire::test(Room::class)
        ->call('select', $giocatore->id)
        ->assertSee("Già aggiudicato a {$this->avversario->name} per 12 crediti")
        ->set('price', '20')
        ->call('record', $this->io->id)
        ->assertSee('non è disponibile');

    expect(Acquisition::query()->count())->toBe(1);
});

it('rifiuta un prezzo sotto il credito', function () {
    Livewire::test(Room::class)
        ->call('select', $this->listone['D'][9]->id)
        ->set('price', '0')
        ->call('record', $this->avversario->id)
        ->assertSee('Il prezzo deve essere almeno 1 credito');

    expect(Acquisition::query()->count())->toBe(0);
});

it('rifiuta una squadra che non ha quei crediti', function () {
    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $this->listone['A'][11]->id,
        'team_id' => $this->avversario->id,
        'price' => 470,
    ]);

    Livewire::test(Room::class)
        ->call('select', $this->listone['C'][12]->id)
        ->set('price', '60')
        ->call('record', $this->avversario->id)
        ->assertSee('non può pagarne 60');
});

it('rifiuta un reparto già completo', function () {
    // Tre portieri: il reparto P dell'avversario è chiuso.
    foreach (range(0, 2) as $indice) {
        Acquisition::factory()->create([
            'auction_id' => $this->auction->id,
            'player_id' => $this->listone['P'][$indice]->id,
            'team_id' => $this->avversario->id,
            'price' => 5,
        ]);
    }

    Livewire::test(Room::class)
        ->call('select', $this->listone['P'][3]->id)
        ->set('price', '5')
        ->call('record', $this->avversario->id)
        ->assertSee('ha già completato il reparto P');

    expect(Acquisition::query()->count())->toBe(3);
});

it('annulla solo l\'ultimo evento e dice quando non c\'è niente da annullare', function () {
    Livewire::test(Room::class)
        ->call('undo')
        ->assertSee('Niente da annullare');
});

it('il bottone "Ricalcola ora" scavalca il debounce e non ne avvia due insieme', function () {
    $componente = Livewire::test(Room::class)
        ->call('recomputeNow')
        ->assertSee('Ricalcolo del piano avviato');

    Queue::assertPushed(RunClaudeTask::class, 1);

    $componente->call('recomputeNow')->assertSee('Un ricalcolo è già in corso');

    Queue::assertPushed(RunClaudeTask::class, 1);
});

it('avvia e chiude l\'asta, e non ne lascia mai due in corso', function () {
    $vecchia = Auction::factory()->live()->create(['name' => 'Asta rimasta aperta']);
    $nuova = Auction::factory()->create(['name' => 'Asta di stasera']);

    $nuova->start();

    expect($nuova->fresh()->status)->toBe(AuctionStatus::Live)
        ->and($vecchia->fresh()->status)->toBe(AuctionStatus::Closed)
        ->and(Auction::query()->where('status', AuctionStatus::Live)->count())->toBe(1);

    Livewire::test(Room::class)
        ->call('closeAuction')
        ->assertSee('Asta chiusa');

    expect(Auction::live())->toBeNull();
});

it('il polling non ridisegna la sala se non è cambiato niente', function () {
    $componente = Livewire::test(Room::class);
    $impronta = $componente->get('stateHash');

    $componente->call('syncState')->assertSet('stateHash', $impronta);

    // Un'aggiudicazione cambia lo stato: al giro dopo l'impronta è diversa e
    // il componente si ridisegna.
    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $this->listone['D'][10]->id,
        'team_id' => $this->avversario->id,
        'price' => 7,
    ]);

    $componente->call('syncState');

    expect($componente->get('stateHash'))->not->toBe($impronta);
});

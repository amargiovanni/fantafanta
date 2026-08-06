<?php

use App\Livewire\Auction\Room;
use App\Models\Auction;
use App\Models\Player;
use App\Services\LeagueState;
use App\Services\PlanWriter;
use App\Services\ValuationEngine;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Performance pass della sala d'asta (spec Fase 5, §4): budget dichiarati nel
 * docblock di `Room` — 150ms per il render della pagina, 50ms per l'update di
 * selezione — dimostrati con un listone vicino alla taglia reale (450
 * giocatori, non i 2-3 dei test funzionali) e con conteggi di query, non solo
 * con un cronometro. Un tempo basso con cento query sarebbe comunque un N+1
 * che aspetta il DB dev di Andrea per manifestarsi.
 *
 * Le soglie sono larghe rispetto ai numeri misurati in sviluppo (riportati
 * nei commenti): SQLite su una macchina di CI condivisa può essere più lento
 * di un Mac in locale, e un test fragile per 20ms non protegge nessuno.
 */
beforeEach(function () {
    Queue::fake();

    configuraLega();
    registraSquadre();

    $this->auction = Auction::factory()->live()->create();

    // Un listone abbastanza strutturato da avere un piano vero (slot +
    // alternative per ruolo) più un riempimento fino a ~450 giocatori, la
    // taglia dichiarata dal briefing per il DB di sviluppo.
    $listone = listonePerPiano();
    $seedati = $listone->flatten()->count();

    Player::factory()->count(max(0, 450 - $seedati))->create();

    app(ValuationEngine::class)->recompute($this->auction);

    $this->piano = app(PlanWriter::class)->save(
        $this->auction,
        pianoValido($listone, prezzo: 15),
        'Piano di performance pass.',
    );

    $this->giocatoreDaSelezionare = $listone['A'][0];

    expect(Player::query()->count())->toBe(450);
});

it('renderizza /asta con un numero di query piatto su un listone da 450 giocatori', function () {
    $numeroQuery = 0;
    $durata = 0.0;

    $numeroQuery = contaQuery(function () use (&$durata) {
        $inizio = microtime(true);
        Livewire::test(Room::class)->assertOk();
        $durata = microtime(true) - $inizio;
    });

    // Misurato in sviluppo (3 run): 12 query, 10-23ms — ben sotto il budget
    // di 150ms della spec Fase 5 §4. Soglie larghe rispetto al misurato per
    // non essere fragili su hardware più lento: quello che conta è che il
    // numero di query NON scali con la taglia del listone (12 indipendentemente
    // da 50 o 450 giocatori è la prova che manca un N+1, non il numero in sé).
    expect($numeroQuery)->toBeLessThan(25)
        ->and($durata)->toBeLessThan(0.15);
});

it('seleziona un giocatore (la scheda decisione) in un numero di query piatto', function () {
    $componente = Livewire::test(Room::class);

    $numeroQuery = 0;
    $durata = 0.0;

    $numeroQuery = contaQuery(function () use ($componente, &$durata) {
        $inizio = microtime(true);
        $componente->call('select', $this->giocatoreDaSelezionare->id);
        $durata = microtime(true) - $inizio;
    });

    // Misurato in sviluppo (3 run): 14 query, 5-7ms — ben sotto i 50ms del
    // budget dichiarato per l'update di selezione (spec Fase 5 §4).
    expect($numeroQuery)->toBeLessThan(22)
        ->and($durata)->toBeLessThan(0.05);
});

it('myCeiling() non ricarica lo stato di lega: prende il LeagueState già costruito da render()', function () {
    // Prima del fix Fase 5, myCeiling(?Auction $auction) chiamava una
    // SECONDA LeagueState::load($auction) — indipendente da quella già
    // fatta in render() — ogni volta che una scheda era aperta: due query
    // aggregate sprecate ad ogni selezione. Il fix cambia la firma per
    // accettare direttamente il LeagueState già calcolato, il che rende
    // strutturalmente impossibile ricaricarlo: non può chiamare load() su
    // qualcosa che riceve già costruito. Un test sulla firma, non sul
    // conteggio delle query, perché è la firma a impedire la regressione.
    $metodo = new ReflectionMethod(Room::class, 'myCeiling');
    $parametro = $metodo->getParameters()[0] ?? null;

    expect($parametro)->not->toBeNull()
        ->and($parametro->getType()?->getName())->toBe(LeagueState::class);
});

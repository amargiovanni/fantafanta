<?php

use App\Enums\PlayerRole;
use App\Enums\SignalType;
use App\Models\Acquisition;
use App\Models\Auction;
use App\Models\Player;
use App\Models\Signal;
use App\Models\Source;
use App\Models\Valuation;
use App\Services\ValuationEngine;
use Illuminate\Support\Facades\Queue;

/**
 * I test obbligatori della specifica del motore
 * (docs/superpowers/specs/2026-08-06-valuation-engine.md).
 *
 * Il motore è la parte del progetto di cui ci si fida senza poterla
 * verificare: durante l'asta si guarda un numero e si alza la mano. Per
 * questo ogni componente della formula ha qui un caso che la inchioda.
 */
beforeEach(function () {
    // Gli observer accodano un ricalcolo a ogni segnale: nei test il motore lo
    // si invoca a mano, così si misura esattamente ciò che si vuole misurare.
    Queue::fake();

    $this->engine = app(ValuationEngine::class);
});

function valutazioneDi(Player $player): Valuation
{
    return Valuation::query()->where('player_id', $player->id)->firstOrFail();
}

it('ripartisce il monte crediti fra i reparti secondo le quote di configurazione', function () {
    // Lega ridotta: due squadre, così i "comprabili" per ruolo sono pochi e
    // il listone di fixture può coincidere esattamente con loro.
    configuraLega(teams: 2, credits: 500, slots: ['P' => 1, 'D' => 2, 'C' => 2, 'A' => 2]);
    registraSquadre(2);

    $attesi = ['P' => 2, 'D' => 4, 'C' => 4, 'A' => 4];

    foreach ($attesi as $ruolo => $quanti) {
        foreach (range(1, $quanti) as $i) {
            giocatore(PlayerRole::from($ruolo), quotazione: 10 + $i, fvm: 50 + $i * 10);
        }
    }

    $this->engine->recompute();

    $pool = 2 * 500;
    $quote = config('valuation.pool_share.with_defense_modifier');

    foreach ($attesi as $ruolo => $quanti) {
        $somma = Valuation::query()
            ->join('players', 'players.id', '=', 'valuations.player_id')
            ->where('players.role', $ruolo)
            ->sum('base_value');

        expect($somma)->toEqualWithDelta($pool * $quote[$ruolo], 1.0);
    }
});

it('azzera quasi il valore di un infortunio lungo e lo fa precipitare di tier', function () {
    configuraLega();
    registraSquadre();

    $infortunato = giocatore(PlayerRole::Attaccante, quotazione: 40, fvm: 300, nome: 'Bomber Uno');

    // Serve un ruolo popolato, altrimenti "precipitare di tier" non significa nulla.
    foreach (range(1, 19) as $i) {
        giocatore(PlayerRole::Attaccante, quotazione: 30 - $i, fvm: 250 - $i * 10);
    }

    $this->engine->recompute();

    $prima = valutazioneDi($infortunato);

    expect($prima->tier)->toBe(1);

    Signal::factory()->create([
        'player_id' => $infortunato->id,
        'type' => SignalType::Infortunio,
        'payload' => ['stop_stimato_giorni' => 150, 'parte_lesa' => 'crociato'],
        'confidence' => 0.9,
        'impact' => -2,
        'event_date' => now()->toDateString(),
    ]);

    $this->engine->recompute();

    $dopo = valutazioneDi($infortunato);

    expect($dopo->adjusted_value)->toBeLessThan($dopo->base_value * 0.2)
        ->and($dopo->base_value)->toBe($prima->base_value)
        ->and($dopo->tier)->toBeGreaterThan($prima->tier);
});

it('fa sparire il malus quando un rientro supera l\'infortunio', function () {
    configuraLega();
    registraSquadre();

    $player = giocatore(PlayerRole::Centrocampista, quotazione: 25, fvm: 180);

    $this->engine->recompute();
    $sano = valutazioneDi($player)->adjusted_value;

    $source = Source::factory()->create();

    $infortunio = Signal::factory()->create([
        'player_id' => $player->id,
        'type' => SignalType::Infortunio,
        'payload' => ['stop_stimato_giorni' => 150],
        'confidence' => 0.9,
        'impact' => -2,
        'source_id' => $source->id,
        'event_date' => now()->toDateString(),
    ]);

    $this->engine->recompute();

    expect(valutazioneDi($player)->adjusted_value)->toBeLessThan($sano);

    $rientro = Signal::factory()->create([
        'player_id' => $player->id,
        'type' => SignalType::Rientro,
        'payload' => ['nota' => 'in gruppo da lunedì'],
        'confidence' => 0.9,
        'impact' => 0,
        'source_id' => $source->id,
        'event_date' => now()->toDateString(),
    ]);

    $infortunio->update(['superseded_by' => $rientro->id]);

    $this->engine->recompute();

    // Il rientro ha impact 0: senza il malus il valore torna esattamente quello di prima.
    expect(valutazioneDi($player)->adjusted_value)->toBe($sano);
});

it('premia con il modificatore di difesa il difensore titolare con media voto alta, entro il cap', function () {
    configuraLega(modificatoreDifesa: true);
    registraSquadre();

    // Identici in tutto tranne la media voto: l'unica variabile è il modificatore.
    $normale = giocatore(PlayerRole::Difensore, quotazione: 15, fvm: 120, mediaVoto: 5.8, titolarita: 0.9);
    $solido = giocatore(PlayerRole::Difensore, quotazione: 15, fvm: 120, mediaVoto: 6.5, titolarita: 0.9);
    $fenomeno = giocatore(PlayerRole::Difensore, quotazione: 15, fvm: 120, mediaVoto: 8.0, titolarita: 0.9);

    // Una riserva con la stessa media voto NON prende il bonus: la titolarità
    // attesa è parte del requisito, non un dettaglio.
    $riserva = giocatore(PlayerRole::Difensore, quotazione: 15, fvm: 120, mediaVoto: 6.5, titolarita: 0.5);

    $this->engine->recompute();

    $base = valutazioneDi($normale)->adjusted_value;

    expect(valutazioneDi($solido)->adjusted_value)->toBeGreaterThan($base)
        ->and(valutazioneDi($solido)->adjusted_value / $base)->toEqualWithDelta(1.10, 0.01)
        ->and(valutazioneDi($fenomeno)->adjusted_value / $base)->toEqualWithDelta(1.20, 0.01)
        ->and(valutazioneDi($riserva)->adjusted_value / valutazioneDi($riserva)->base_value)
        ->toBeLessThan(valutazioneDi($solido)->adjusted_value / valutazioneDi($solido)->base_value);
});

it('alza i max_bid del reparto quando si paga sopra valutazione, ammortizzando il picco', function () {
    configuraLega();
    $squadre = registraSquadre();
    $auction = Auction::factory()->create();

    $attaccanti = collect(range(1, 12))->map(fn (int $i) => giocatore(
        PlayerRole::Attaccante,
        quotazione: 30 - $i,
        fvm: 250 - $i * 10,
    ));

    $this->engine->recompute();

    $restante = $attaccanti->last();
    $primaDellInflazione = valutazioneDi($restante)->max_bid;

    // Tre attaccanti pagati il 30% sopra il loro valore, tutti da avversari:
    // i miei crediti e i miei slot restano identici, quindi l'unica variabile
    // che cambia è l'inflazione del reparto.
    $pagato = 0.0;
    $atteso = 0.0;

    foreach ($attaccanti->take(3) as $indice => $attaccante) {
        $valore = valutazioneDi($attaccante)->adjusted_value;
        $prezzo = (int) round($valore * 1.3);

        Acquisition::factory()->create([
            'auction_id' => $auction->id,
            'player_id' => $attaccante->id,
            'team_id' => $squadre[$indice + 1]->id,
            'price' => $prezzo,
        ]);

        $pagato += $prezzo;
        $atteso += $valore;
    }

    $this->engine->recompute($auction);

    $inflazioneEffettiva = 1 + ($pagato / $atteso - 1) * config('valuation.inflation.damping');

    expect($inflazioneEffettiva)->toEqualWithDelta(1.21, 0.01);

    $dopo = valutazioneDi($restante);

    expect($dopo->max_bid)->toBe((int) floor($dopo->adjusted_value * $inflazioneEffettiva))
        ->and($dopo->max_bid)->toBeGreaterThan($primaDellInflazione);
});

it('non consiglia mai un\'offerta che lascerebbe uno slot senza credito', function () {
    configuraLega();
    $squadre = registraSquadre();
    $auction = Auction::factory()->create();
    $mia = $squadre->first();

    foreach (range(1, 12) as $i) {
        giocatore(PlayerRole::Attaccante, quotazione: 30 - $i, fvm: 250 - $i * 10);
    }
    foreach (range(1, 4) as $i) {
        giocatore(PlayerRole::Portiere, quotazione: 10 + $i, fvm: 40 + $i);
    }

    $this->engine->recompute($auction);

    // Ho già speso 470 dei 500 crediti in 7 giocatori (4 attaccanti su 6 slot,
    // 3 portieri su 3): mi restano 30 crediti e 18 slot aperti, quindi
    // l'offerta massima possibile è 30 − 17 = 13.
    $comprati = Player::query()->where('role', PlayerRole::Attaccante)->orderBy('id')->limit(4)->get()
        ->concat(Player::query()->where('role', PlayerRole::Portiere)->orderBy('id')->limit(3)->get());

    foreach ($comprati as $indice => $player) {
        Acquisition::factory()->create([
            'auction_id' => $auction->id,
            'player_id' => $player->id,
            'team_id' => $mia->id,
            'price' => $indice === 0 ? 458 : 2,
        ]);
    }

    $this->engine->recompute($auction);

    $spesi = (int) Acquisition::query()->where('team_id', $mia->id)->sum('price');
    $slotAperti = 25 - $comprati->count();
    $tetto = (500 - $spesi) - ($slotAperti - 1);

    expect($spesi)->toBe(470)
        ->and($tetto)->toBe(13);

    $massimo = Valuation::query()
        ->join('players', 'players.id', '=', 'valuations.player_id')
        ->where('players.status', 'available')
        ->max('max_bid');

    expect($massimo)->toBeLessThanOrEqual($tetto)
        ->and($massimo)->toBeGreaterThan(0);
});

it('sull\'ultimo slot con un credito consiglia esattamente 1, mai 0 e mai 2', function () {
    // Lega minima: quattro slot, così l'ultimo caso limite è costruibile.
    configuraLega(teams: 2, credits: 500, slots: ['P' => 1, 'D' => 1, 'C' => 1, 'A' => 1]);
    $squadre = registraSquadre(2);
    $auction = Auction::factory()->create();
    $mia = $squadre->first();

    $portiere = giocatore(PlayerRole::Portiere, quotazione: 10, fvm: 40);
    $difensore = giocatore(PlayerRole::Difensore, quotazione: 12, fvm: 60);
    $centrocampista = giocatore(PlayerRole::Centrocampista, quotazione: 20, fvm: 150);
    giocatore(PlayerRole::Attaccante, quotazione: 40, fvm: 300);

    foreach ([[$portiere, 200], [$difensore, 200], [$centrocampista, 99]] as [$player, $prezzo]) {
        Acquisition::factory()->create([
            'auction_id' => $auction->id,
            'player_id' => $player->id,
            'team_id' => $mia->id,
            'price' => $prezzo,
        ]);
    }

    $this->engine->recompute($auction);

    // Un credito residuo, un solo slot aperto: il tetto è esattamente 1,
    // e l'attaccante rimasto varrebbe molto di più.
    $attaccante = Player::query()->where('role', PlayerRole::Attaccante)->firstOrFail();

    expect(valutazione($attaccante)->adjusted_value)->toBeGreaterThan(1)
        ->and(valutazione($attaccante)->max_bid)->toBe(1);
});

it('è deterministico: due esecuzioni sugli stessi dati danno gli stessi numeri', function () {
    configuraLega();
    registraSquadre();

    foreach (PlayerRole::cases() as $ruolo) {
        foreach (range(1, 8) as $i) {
            giocatore($ruolo, quotazione: 5 + $i, fvm: 40 + $i * 7, titolarita: $i / 10);
        }
    }

    Signal::factory()->create([
        'player_id' => Player::query()->first()->id,
        'type' => SignalType::Titolarita,
        'confidence' => 0.7,
        'impact' => 1,
    ]);

    $primo = $this->engine->compute();
    $secondo = $this->engine->compute();

    expect($secondo)->toBe($primo)
        ->and($primo)->not->toBeEmpty();
});

it('ricalcola un listone da 600 giocatori in meno di 10 secondi', function () {
    configuraLega();
    registraSquadre();

    // Distribuzione realistica di un listone di Serie A.
    $distribuzione = ['P' => 60, 'D' => 180, 'C' => 200, 'A' => 160];

    foreach ($distribuzione as $ruolo => $quanti) {
        Player::factory()->count($quanti)->create([
            'role' => PlayerRole::from($ruolo),
            'season_stats' => ['Pv' => '25', 'Mv' => '6.1', 'Fm' => '6.4', 'Am' => '4'],
        ]);
    }

    $inizio = microtime(true);
    $scritte = $this->engine->recompute();
    $durata = microtime(true) - $inizio;

    expect($scritte)->toBe(600)
        ->and(Valuation::query()->count())->toBe(600)
        ->and($durata)->toBeLessThan(10.0);
});

it('non consiglia offerte per chi non è più disponibile', function () {
    configuraLega();
    $squadre = registraSquadre();
    $auction = Auction::factory()->create();

    $preso = giocatore(PlayerRole::Attaccante, quotazione: 30, fvm: 250);
    giocatore(PlayerRole::Attaccante, quotazione: 20, fvm: 150);

    Acquisition::factory()->create([
        'auction_id' => $auction->id,
        'player_id' => $preso->id,
        'team_id' => $squadre->last()->id,
        'price' => 60,
    ]);

    $this->engine->recompute($auction);

    expect(valutazioneDi($preso)->max_bid)->toBe(0);
});

it('annulla il valore di chi lascia la Serie A quando la notizia è confermata', function () {
    configuraLega();
    registraSquadre();

    $partente = giocatore(PlayerRole::Attaccante, quotazione: 40, fvm: 300);

    Signal::factory()->create([
        'player_id' => $partente->id,
        'type' => SignalType::MercatoOut,
        'payload' => ['destinazione' => 'Premier League'],
        'confidence' => 0.9,
        'impact' => -2,
    ]);

    $this->engine->recompute();

    expect(valutazioneDi($partente)->adjusted_value)->toBe(1.0);
});

it('paga il rigorista offensivo più di un pari valore che non tira i rigori', function () {
    configuraLega();
    registraSquadre();

    $rigorista = giocatore(PlayerRole::Attaccante, quotazione: 25, fvm: 200);
    $normale = giocatore(PlayerRole::Attaccante, quotazione: 25, fvm: 200);

    $rigorista->update(['is_rigorista' => true]);

    $this->engine->recompute();

    expect(valutazioneDi($rigorista)->adjusted_value / valutazioneDi($normale)->adjusted_value)
        ->toEqualWithDelta(1.12, 0.01);
});

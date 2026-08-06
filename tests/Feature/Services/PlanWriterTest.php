<?php

use App\Enums\PlanStatus;
use App\Enums\PlanTrigger;
use App\Enums\PlayerRole;
use App\Enums\SlotStatus;
use App\Exceptions\PlanValidationException;
use App\Models\Acquisition;
use App\Models\Auction;
use App\Models\Player;
use App\Services\PlanWriter;
use Illuminate\Support\Facades\Queue;

/**
 * La validazione del piano è l'unico punto in cui il progetto dice di no a
 * Claude. Ogni regola della dottrina ha qui il suo caso: se una di queste
 * cade, in asta si scopre di avere in piano un giocatore che non esiste più.
 */
beforeEach(function () {
    Queue::fake();

    configuraLega();
    $this->squadre = registraSquadre();
    $this->auction = Auction::factory()->create();
    $this->listone = listonePerPiano();
    $this->writer = app(PlanWriter::class);
});

it('salva un piano completo di 25 slot e ne calcola il riepilogo di budget', function () {
    $piano = $this->writer->save($this->auction, pianoValido($this->listone), 'Difesa concentrata, un top in attacco.');

    expect($piano->version)->toBe(1)
        ->and($piano->status)->toBe(PlanStatus::Ready)
        ->and($piano->slots)->toHaveCount(25)
        ->and($piano->budget_summary['D']['allocated'])->toBe(8 * 5)
        ->and($piano->budget_summary['A']['spent'])->toBe(0)
        ->and($piano->slots->where('slot_status', SlotStatus::Pending))->toHaveCount(25);
});

it('numera le versioni in progressione senza mai riscrivere la precedente', function () {
    $primo = $this->writer->save($this->auction, pianoValido($this->listone), 'Prima versione.');
    $secondo = $this->writer->save($this->auction, pianoValido($this->listone), 'Seconda versione.', PlanTrigger::Manual);

    expect($secondo->version)->toBe(2)
        ->and($primo->fresh()->version)->toBe(1)
        ->and($secondo->trigger)->toBe(PlanTrigger::Manual)
        ->and($this->auction->latestReadyPlan()->id)->toBe($secondo->id);
});

it('rifiuta un piano con il numero di slot sbagliato', function () {
    $piano = pianoValido($this->listone);
    array_pop($piano);

    expect(fn () => $this->writer->save($this->auction, $piano, 'Note.'))
        ->toThrow(PlanValidationException::class);

    $errori = $this->writer->validate($this->auction, $piano, 'Note.');

    expect(implode("\n", $errori))
        ->toContain('24 slot invece dei 25')
        ->toContain('Ruolo A: 5 slot invece di 6');
});

it('rifiuta lo stesso giocatore titolare di due slot', function () {
    $piano = pianoValido($this->listone);
    $piano[4]['player_id'] = $piano[3]['player_id'];

    $errori = $this->writer->validate($this->auction, $piano, 'Note.');

    expect(implode("\n", $errori))->toContain('è titolare di più slot');
});

it('rifiuta un\'alternativa che è già titolare di un altro slot', function () {
    $piano = pianoValido($this->listone);
    $piano[4]['alternatives'][0]['player_id'] = $piano[3]['player_id'];

    $errori = $this->writer->validate($this->auction, $piano, 'Note.');

    expect(implode("\n", $errori))->toContain('non può essere alternativa di un altro slot');
});

it('accetta lo stesso giocatore come alternativa di più slot dello stesso ruolo', function () {
    $piano = pianoValido($this->listone);

    // Le alternative di D#1 e D#2 coincidono: è legittimo, quel difensore
    // serve comunque e non può essere preso due volte.
    $piano[4]['alternatives'] = $piano[3]['alternatives'];

    expect($this->writer->validate($this->auction, $piano, 'Note.'))->toBe([]);
});

it('rifiuta un giocatore nello slot di un ruolo che non è il suo', function () {
    $piano = pianoValido($this->listone);
    $piano[0]['player_id'] = $this->listone['A'][0]->id;

    $errori = $this->writer->validate($this->auction, $piano, 'Note.');

    expect(implode("\n", $errori))->toContain('è un A, non può occupare uno slot P');
});

it('rifiuta un titolare già aggiudicato a un\'altra squadra, dicendo a chi', function () {
    $preso = $this->listone['C'][0];

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $preso->id,
        'team_id' => $this->squadre[3]->id,
        'price' => 40,
    ]);

    $errori = $this->writer->validate($this->auction, pianoValido($this->listone), 'Note.');

    expect(implode("\n", $errori))
        ->toContain('è già stato aggiudicato a Avversario 4');
});

it('pretende che i giocatori già miei occupino il loro slot al prezzo pagato', function () {
    $mio = $this->listone['A'][0];

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $mio->id,
        'team_id' => $this->squadre[0]->id,
        'price' => 77,
    ]);

    $piano = pianoValido($this->listone);

    // Il piano lo tiene nello slot ma col prezzo sbagliato.
    $errori = $this->writer->validate($this->auction, $piano, 'Note.');

    expect(implode("\n", $errori))->toContain('target_price deve valere esattamente 77');

    // E se lo dimentica del tutto, peggio ancora.
    $senzaDiLui = array_values(array_filter($piano, fn (array $slot) => $slot['player_id'] !== $mio->id));
    $senzaDiLui[] = [
        'role' => 'A',
        'slot_index' => 1,
        'player_id' => $this->listone['A'][7]->id,
        'target_price' => 5,
        'max_price' => 8,
        'alternatives' => [
            ['player_id' => $this->listone['A'][8]->id, 'target_price' => 4],
            ['player_id' => $this->listone['A'][9]->id, 'target_price' => 3],
        ],
    ];

    expect(implode("\n", $this->writer->validate($this->auction, $senzaDiLui, 'Note.')))
        ->toContain('non occupa nessuno slot');
});

it('salva i giocatori già miei con slot_status acquired e senza pretendere alternative', function () {
    $mio = $this->listone['A'][0];

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $mio->id,
        'team_id' => $this->squadre[0]->id,
        'price' => 77,
    ]);

    $piano = collect(pianoValido($this->listone))->map(function (array $slot) use ($mio) {
        if ($slot['player_id'] === $mio->id) {
            $slot['target_price'] = 77;
            $slot['max_price'] = 77;
            $slot['alternatives'] = [];
        }

        return $slot;
    })->all();

    $salvato = $this->writer->save($this->auction, $piano, 'Attacco già coperto.');

    $slot = $salvato->slots->firstWhere('player_id', $mio->id);

    expect($slot->slot_status)->toBe(SlotStatus::Acquired)
        ->and($slot->target_price)->toBe(77)
        ->and($salvato->budget_summary['A']['spent'])->toBe(77);
});

it('pretende almeno due alternative per ogni slot ancora da prendere', function () {
    $piano = pianoValido($this->listone);
    $piano[10]['alternatives'] = [$piano[10]['alternatives'][0]];

    $errori = $this->writer->validate($this->auction, $piano, 'Note.');

    expect(implode("\n", $errori))->toContain('servono almeno 2 alternative (trovate 1)');
});

it('rifiuta un\'alternativa che costa più del max_price del suo slot', function () {
    $piano = pianoValido($this->listone);
    $piano[10]['alternatives'][0]['target_price'] = $piano[10]['max_price'] + 10;

    $errori = $this->writer->validate($this->auction, $piano, 'Note.');

    expect(implode("\n", $errori))->toContain('un ripiego non può costare più del titolare');
});

it('rifiuta prezzi impossibili', function () {
    $piano = pianoValido($this->listone);
    $piano[2]['target_price'] = 0;
    $piano[3]['max_price'] = 1;
    $piano[3]['target_price'] = 20;

    $errori = implode("\n", $this->writer->validate($this->auction, $piano, 'Note.'));

    expect($errori)->toContain('ogni slot costa almeno 1 credito')
        ->toContain('è inferiore al target_price');
});

it('rifiuta un piano che sfora i crediti residui, dicendo di quanto', function () {
    // 25 slot da 30 crediti fanno 750: centro il budget di 500 e sforo di 250.
    $errori = $this->writer->validate($this->auction, pianoValido($this->listone, prezzo: 30), 'Note.');

    expect(implode("\n", $errori))
        ->toContain('Budget sforato')
        ->toContain('Rientra di 250');
});

it('conta il budget residuo al netto di quanto ho già speso', function () {
    $mio = $this->listone['A'][0];

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $mio->id,
        'team_id' => $this->squadre[0]->id,
        'price' => 400,
    ]);

    $piano = collect(pianoValido($this->listone, prezzo: 5))->map(function (array $slot) use ($mio) {
        if ($slot['player_id'] === $mio->id) {
            $slot['target_price'] = 400;
            $slot['max_price'] = 400;
            $slot['alternatives'] = [];
        }

        return $slot;
    })->all();

    // 24 slot aperti da 5 crediti sono 120, e me ne restano 100: sforo di 20.
    expect(implode("\n", $this->writer->validate($this->auction, $piano, 'Note.')))
        ->toContain('Rientra di 20');
});

it('pretende note strategiche e non più di tre righe', function () {
    expect(implode("\n", $this->writer->validate($this->auction, pianoValido($this->listone), null)))
        ->toContain('strategy_notes: obbligatorie');

    $troppoLunghe = "Riga uno.\nRiga due.\nRiga tre.\nRiga quattro.";

    expect(implode("\n", $this->writer->validate($this->auction, pianoValido($this->listone), $troppoLunghe)))
        ->toContain('massimo 3 righe, trovate 4');
});

it('elenca tutte le violazioni insieme, non solo la prima', function () {
    $piano = pianoValido($this->listone);
    $piano[0]['player_id'] = $this->listone['A'][0]->id;   // ruolo sbagliato
    $piano[4]['alternatives'] = [];                        // alternative mancanti
    $piano[6]['target_price'] = 0;                         // prezzo impossibile

    $errori = $this->writer->validate($this->auction, $piano, 'Note.');

    expect($errori)->toHaveCount(4)
        ->and(implode("\n", $errori))
        ->toContain('non può occupare uno slot P')
        ->toContain('servono almeno 2 alternative')
        ->toContain('ogni slot costa almeno 1 credito');
});

it('rifiuta un giocatore che non esiste', function () {
    $piano = pianoValido($this->listone);
    $piano[0]['player_id'] = 999999;

    expect(implode("\n", $this->writer->validate($this->auction, $piano, 'Note.')))
        ->toContain('il giocatore 999999 non esiste nel listone');
});

it('rifiuta slot_index ripetuti o fuori scala dentro un ruolo', function () {
    $piano = pianoValido($this->listone);
    $piano[1]['slot_index'] = 1;

    expect(implode("\n", $this->writer->validate($this->auction, $piano, 'Note.')))
        ->toContain('Ruolo P: gli slot_index devono essere da 1 a 3, una volta ciascuno (trovati: 1, 1, 3)');
});

it('non lascia scritto niente quando il piano è rifiutato', function () {
    $piano = pianoValido($this->listone);
    $piano[0]['player_id'] = 999999;

    try {
        $this->writer->save($this->auction, $piano, 'Note.');
    } catch (PlanValidationException) {
        // atteso
    }

    expect($this->auction->plans()->count())->toBe(0);
});

it('rifiuta un\'alternativa non più disponibile', function () {
    $riserva = $this->listone['D'][8];

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $riserva->id,
        'team_id' => $this->squadre[2]->id,
        'price' => 12,
    ]);

    expect(implode("\n", $this->writer->validate($this->auction, pianoValido($this->listone), 'Note.')))
        ->toContain('non è disponibile');
});

it('rifiuta un piano che contiene un giocatore uscito dal listone', function () {
    Player::query()->whereKey($this->listone['P'][0]->id)->update(['status' => 'removed']);

    expect(implode("\n", $this->writer->validate($this->auction, pianoValido($this->listone), 'Note.')))
        ->toContain('non è più nel listone');
});

it('accetta una configurazione di lega diversa da quella standard', function () {
    configuraLega(slots: ['P' => 2, 'D' => 4, 'C' => 4, 'A' => 3]);

    $listone = collect(['P' => 2, 'D' => 4, 'C' => 4, 'A' => 3])
        ->mapWithKeys(fn (int $quanti, string $ruolo) => [
            $ruolo => collect(range(1, $quanti + 6))->map(fn () => giocatore(PlayerRole::from($ruolo))),
        ]);

    $piano = $this->writer->save($this->auction, pianoValido($listone), 'Lega ridotta.');

    expect($piano->slots)->toHaveCount(13);
});

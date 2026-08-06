<?php

use App\Enums\PlayerRole;
use App\Enums\SlotStatus;
use App\Models\Acquisition;
use App\Models\Auction;
use App\Models\Plan;
use App\Models\PlanSlot;
use App\Services\PlanSlotPromoter;
use App\Services\PlanWriter;
use Illuminate\Support\Facades\Queue;

/**
 * La rete di sicurezza fra un'aggiudicazione e il replan (briefing §7.3).
 *
 * Il replan di Claude parte con venti secondi di debounce e ne impiega altri
 * trenta: in quel minuto l'asta va avanti. Se il piano continua a indicare un
 * giocatore che è appena andato a un altro, quel minuto si spende a guardare
 * un dato falso.
 */
beforeEach(function () {
    Queue::fake();

    configuraLega();
    $this->squadre = registraSquadre();
    $this->auction = Auction::factory()->create();
    $this->listone = listonePerPiano();

    $this->piano = app(PlanWriter::class)->save(
        $this->auction,
        pianoValido($this->listone),
        'Piano di partenza.',
    );

    $this->promoter = app(PlanSlotPromoter::class);
});

function slotDi(Plan $piano, string $ruolo, int $indice): PlanSlot
{
    return $piano->slots()->where('role', $ruolo)->where('slot_index', $indice)->firstOrFail();
}

it('promuove la prima alternativa disponibile quando il titolare va a un avversario', function () {
    $slot = slotDi($this->piano, 'D', 1);
    $titolare = $slot->player_id;
    $primaAlternativa = $slot->alternatives[0]['player_id'];

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $titolare,
        'team_id' => $this->squadre[3]->id,
        'price' => 30,
    ]);

    $aggiornato = slotDi($this->piano, 'D', 1);

    expect($aggiornato->slot_status)->toBe(SlotStatus::Lost)
        ->and($aggiornato->player_id)->toBe($primaAlternativa)
        ->and($aggiornato->target_price)->toBe($slot->alternatives[0]['target_price'])
        ->and(collect($aggiornato->alternatives)->pluck('player_id'))->not->toContain($primaAlternativa);
});

it('salta le alternative che nel frattempo sono già state prese', function () {
    $slot = slotDi($this->piano, 'C', 2);
    $primaAlternativa = $slot->alternatives[0]['player_id'];
    $secondaAlternativa = $slot->alternatives[1]['player_id'];

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $primaAlternativa,
        'team_id' => $this->squadre[2]->id,
        'price' => 10,
    ]);

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $slot->player_id,
        'team_id' => $this->squadre[4]->id,
        'price' => 25,
    ]);

    expect(slotDi($this->piano, 'C', 2)->player_id)->toBe($secondaAlternativa);
});

it('marca lo slot acquired quando il giocatore lo prendo io, al prezzo pagato', function () {
    $slot = slotDi($this->piano, 'A', 1);

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $slot->player_id,
        'team_id' => $this->squadre[0]->id,
        'price' => 63,
    ]);

    $aggiornato = slotDi($this->piano, 'A', 1);

    expect($aggiornato->slot_status)->toBe(SlotStatus::Acquired)
        ->and($aggiornato->target_price)->toBe(63)
        ->and($aggiornato->max_price)->toBe(63)
        ->and($aggiornato->player_id)->toBe($slot->player_id);
});

it('lascia lo slot vuoto e marcato lost se non resta nessuna alternativa', function () {
    $slot = slotDi($this->piano, 'P', 1);

    foreach ($slot->alternatives as $alternativa) {
        Acquisition::factory()->create([
            'auction_id' => $this->auction->id,
            'player_id' => $alternativa['player_id'],
            'team_id' => $this->squadre[5]->id,
            'price' => 5,
        ]);
    }

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $slot->player_id,
        'team_id' => $this->squadre[6]->id,
        'price' => 20,
    ]);

    $aggiornato = slotDi($this->piano, 'P', 1);

    expect($aggiornato->slot_status)->toBe(SlotStatus::Lost)
        ->and($aggiornato->player_id)->toBeNull();
});

it('toglie dalle alternative di tutti gli slot un giocatore che è stato assegnato', function () {
    // La stessa riserva è alternativa di più slot dello stesso ruolo.
    $slot = slotDi($this->piano, 'D', 3);
    $riserva = $slot->alternatives[0]['player_id'];

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $riserva,
        'team_id' => $this->squadre[7]->id,
        'price' => 8,
    ]);

    $alternativeRimaste = $this->piano->slots()->where('role', 'D')->get()
        ->flatMap(fn (PlanSlot $slot) => collect($slot->alternatives)->pluck('player_id'));

    expect($alternativeRimaste)->not->toContain($riserva);
});

it('non tocca niente se non esiste ancora un piano pronto', function () {
    $altraAsta = Auction::factory()->create();
    $player = giocatore(PlayerRole::Attaccante);

    $acquisto = Acquisition::factory()->create([
        'auction_id' => $altraAsta->id,
        'player_id' => $player->id,
        'team_id' => $this->squadre[1]->id,
        'price' => 15,
    ]);

    expect($this->promoter->apply($acquisto))->toBe([]);
});

it('non tocca gli slot che riguardano altri giocatori', function () {
    $slot = slotDi($this->piano, 'C', 1);
    $altro = slotDi($this->piano, 'C', 5);

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $slot->player_id,
        'team_id' => $this->squadre[1]->id,
        'price' => 22,
    ]);

    $dopo = slotDi($this->piano, 'C', 5);

    expect($dopo->slot_status)->toBe(SlotStatus::Pending)
        ->and($dopo->player_id)->toBe($altro->player_id);
});

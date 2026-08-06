<?php

use App\Enums\SignalType;
use App\Livewire\Knowledge\Signals;
use App\Models\Player;
use App\Models\Signal;
use Livewire\Livewire;

it('raggruppa i segnali per giocatore', function () {
    $lautaro = Player::factory()->create(['name' => 'MARTINEZ Lautaro']);
    $dumfries = Player::factory()->create(['name' => 'DUMFRIES Denzel']);

    Signal::factory()->create(['player_id' => $lautaro->id, 'type' => SignalType::Infortunio]);
    Signal::factory()->create(['player_id' => $dumfries->id, 'type' => SignalType::Rigorista, 'impact' => 2]);

    Livewire::test(Signals::class)
        ->assertSee('MARTINEZ Lautaro')
        ->assertSee('DUMFRIES Denzel')
        ->assertViewHas('grouped', fn ($g) => $g->count() === 2);
});

it('filtra per giocatore e per tipo', function () {
    $lautaro = Player::factory()->create(['name' => 'MARTINEZ Lautaro']);
    $dumfries = Player::factory()->create(['name' => 'DUMFRIES Denzel']);

    Signal::factory()->create(['player_id' => $lautaro->id, 'type' => SignalType::Infortunio]);
    Signal::factory()->create(['player_id' => $dumfries->id, 'type' => SignalType::Rigorista]);

    Livewire::test(Signals::class)
        ->set('search', 'lautaro')
        ->assertSee('MARTINEZ Lautaro')
        ->assertDontSee('DUMFRIES Denzel');

    Livewire::test(Signals::class)
        ->set('typeFilter', 'rigorista')
        ->assertSee('DUMFRIES Denzel')
        ->assertDontSee('MARTINEZ Lautaro');
});

it('nasconde i segnali superati se non richiesti', function () {
    $player = Player::factory()->create(['name' => 'MARTINEZ Lautaro']);
    $rientro = Signal::factory()->create(['player_id' => $player->id, 'type' => SignalType::Rientro]);
    Signal::factory()->create([
        'player_id' => $player->id,
        'type' => SignalType::Infortunio,
        'superseded_by' => $rientro->id,
    ]);

    Livewire::test(Signals::class)
        ->assertViewHas('grouped', fn ($g) => $g->first()['signals']->count() === 1)
        ->set('onlyActive', false)
        ->assertViewHas('grouped', fn ($g) => $g->first()['signals']->count() === 2);
});

it('corregge tipo, impatto, confidenza e data di un segnale', function () {
    $signal = Signal::factory()->create(['type' => SignalType::Infortunio, 'impact' => -2, 'confidence' => 0.4]);

    Livewire::test(Signals::class)
        ->call('edit', $signal->id)
        ->set('form.type', 'squalifica')
        ->set('form.impact', -1)
        ->set('form.confidence', 0.95)
        ->set('form.event_date', '2026-08-01')
        ->call('save')
        ->assertHasNoErrors();

    expect($signal->fresh())
        ->type->toBe(SignalType::Squalifica)
        ->impact->toBe(-1)
        ->confidence->toBe(0.95)
        ->and($signal->fresh()->event_date->toDateString())->toBe('2026-08-01');
});

it('rifiuta una correzione fuori scala', function () {
    $signal = Signal::factory()->create();

    Livewire::test(Signals::class)
        ->call('edit', $signal->id)
        ->set('form.impact', 7)
        ->call('save')
        ->assertHasErrors('form.impact');

    Livewire::test(Signals::class)
        ->call('edit', $signal->id)
        ->set('form.confidence', 3)
        ->call('save')
        ->assertHasErrors('form.confidence');
});

it('elimina un segnale sbagliato', function () {
    $signal = Signal::factory()->create();

    Livewire::test(Signals::class)->call('delete', $signal->id);

    expect(Signal::query()->count())->toBe(0);
});

it('riattiva un segnale marcato superato per errore', function () {
    $player = Player::factory()->create();
    $nuovo = Signal::factory()->create(['player_id' => $player->id, 'type' => SignalType::Rientro]);
    $vecchio = Signal::factory()->create([
        'player_id' => $player->id,
        'type' => SignalType::Infortunio,
        'superseded_by' => $nuovo->id,
    ]);

    Livewire::test(Signals::class)->call('reactivate', $vecchio->id);

    expect($vecchio->fresh()->isActive())->toBeTrue();
});

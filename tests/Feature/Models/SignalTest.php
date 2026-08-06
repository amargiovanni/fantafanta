<?php

use App\Enums\SignalType;
use App\Models\Player;
use App\Models\Signal;
use App\Models\Source;

it('collega segnale, giocatore e fonte', function () {
    $player = Player::factory()->create(['name' => 'LAUTARO Martinez']);
    $source = Source::factory()->create(['title' => 'Infortunio Lautaro']);

    $signal = Signal::factory()->create([
        'player_id' => $player->id,
        'source_id' => $source->id,
        'type' => SignalType::Infortunio,
    ]);

    expect($signal->player->name)->toBe('LAUTARO Martinez')
        ->and($signal->source->title)->toBe('Infortunio Lautaro')
        ->and($signal->type)->toBe(SignalType::Infortunio)
        ->and($signal->isActive())->toBeTrue();
});

it('esclude dai segnali attivi quelli superati', function () {
    $player = Player::factory()->create();

    $infortunio = Signal::factory()->create([
        'player_id' => $player->id,
        'type' => SignalType::Infortunio,
    ]);

    $rientro = Signal::factory()->create([
        'player_id' => $player->id,
        'type' => SignalType::Rientro,
        'impact' => 1,
    ]);

    $infortunio->update(['superseded_by' => $rientro->id]);

    expect(Signal::query()->active()->pluck('id')->all())->toBe([$rientro->id])
        ->and($infortunio->fresh()->isActive())->toBeFalse();
});

it('tiene i segnali non risolti nella coda di revisione', function () {
    Signal::factory()->needsReview('Nome Sconosciuto')->create();
    Signal::factory()->create();

    $pending = Signal::query()->pendingReview()->get();

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->raw_name)->toBe('Nome Sconosciuto')
        ->and($pending->first()->player_id)->toBeNull();
});

it('produce lo stesso hash per contenuti che differiscono solo negli spazi', function () {
    expect(Source::hashContent("Lautaro   si è\n\ninfortunato"))
        ->toBe(Source::hashContent('Lautaro si è infortunato'));
});

it('sa quali tipi rende obsoleti un rientro', function () {
    expect(SignalType::Rientro->supersedes())
        ->toBe([SignalType::Infortunio, SignalType::Squalifica])
        ->and(SignalType::Forma->supersedes())->toBe([]);
});

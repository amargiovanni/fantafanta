<?php

use App\Enums\SourceStatus;
use App\Livewire\Knowledge\Review;
use App\Models\Player;
use App\Models\PlayerAlias;
use App\Models\Signal;
use App\Models\Source;
use Livewire\Livewire;

it('propone i candidati per un nome non risolto', function () {
    Player::factory()->create(['name' => 'MARTINEZ Lautaro', 'real_team' => 'Inter']);
    $signal = Signal::factory()->needsReview('Lautaro Martinez')->create();

    Livewire::test(Review::class)
        ->assertSee('Lautaro Martinez')
        ->assertViewHas('suggestions', fn ($s) => $s[$signal->id][0]['name'] === 'MARTINEZ Lautaro');
});

it('assegna il giocatore, crea l\'alias e chiude la revisione', function () {
    $player = Player::factory()->create(['name' => 'MARTINEZ Lautaro']);
    $source = Source::factory()->create(['status' => SourceStatus::NeedsReview]);
    $signal = Signal::factory()->needsReview('il Toro')->create(['source_id' => $source->id]);

    Livewire::test(Review::class)->call('assign', $signal->id, $player->id);

    expect($signal->fresh())
        ->player_id->toBe($player->id)
        ->needs_review->toBeFalse();

    // L'errore non si deve ripetere: "il Toro" ora è un alias noto.
    expect(PlayerAlias::query()->where('player_id', $player->id)->pluck('alias')->all())
        ->toContain('il Toro');

    // Chiusa l'ultima revisione, la fonte torna processata.
    expect($source->fresh()->status)->toBe(SourceStatus::Processed);
});

it('lascia la fonte in revisione se restano altri nomi da assegnare', function () {
    $player = Player::factory()->create();
    $source = Source::factory()->create(['status' => SourceStatus::NeedsReview]);

    $primo = Signal::factory()->needsReview('il Toro')->create(['source_id' => $source->id]);
    Signal::factory()->needsReview('il Tucu')->create(['source_id' => $source->id]);

    Livewire::test(Review::class)->call('assign', $primo->id, $player->id);

    expect($source->fresh()->status)->toBe(SourceStatus::NeedsReview);
});

it('elimina un segnale non recuperabile', function () {
    $source = Source::factory()->create(['status' => SourceStatus::NeedsReview]);
    $signal = Signal::factory()->needsReview('Cristiano Ronaldo')->create(['source_id' => $source->id]);

    Livewire::test(Review::class)->call('discard', $signal->id);

    expect(Signal::query()->count())->toBe(0)
        ->and($source->fresh()->status)->toBe(SourceStatus::Processed);
});

it('non duplica un alias già esistente', function () {
    $player = Player::factory()->create(['name' => 'MARTINEZ Lautaro']);
    PlayerAlias::query()->create(['player_id' => $player->id, 'alias' => 'il Toro']);
    $signal = Signal::factory()->needsReview('il Toro')->create();

    Livewire::test(Review::class)->call('assign', $signal->id, $player->id);

    expect(PlayerAlias::query()->where('player_id', $player->id)->count())->toBe(1);
});

it('mostra la coda vuota quando non c\'è niente da rivedere', function () {
    Livewire::test(Review::class)->assertSee('Nessun segnale in attesa di revisione');
});

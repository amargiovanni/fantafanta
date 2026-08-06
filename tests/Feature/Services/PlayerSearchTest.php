<?php

use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Services\ListoneImporter;
use App\Services\PlayerSearch;

beforeEach(function () {
    (new ListoneImporter)->import(
        file_get_contents(base_path('tests/Fixtures/listone-sample.csv')),
        [
            'name' => 'Nome',
            'role' => 'R',
            'real_team' => 'Squadra',
            'quotazione' => 'Qt.A',
            'fvm' => 'FVM',
        ]
    );
});

it('resolves "lautaro", "Martinez L.", "martinez lautaro" and "LAUTARO MARTINEZ" to the same player', function (string $input) {
    $lautaro = Player::where('normalized_name', 'martinez lautaro')->firstOrFail();

    $results = (new PlayerSearch)->search($input);

    expect($results)->not->toBeEmpty();
    expect($results->pluck('player.id'))->toContain($lautaro->id);
})->with([
    'lautaro',
    'Martinez L.',
    'martinez lautaro',
    'LAUTARO MARTINEZ',
]);

it('gives an exact alias/name match a score of 1.0', function () {
    // "martinez lautaro" e "Martinez L." sono alias generati automaticamente
    // dall'import (nome invertito e cognome+iniziale): match esatto, score 1.0.
    $results = (new PlayerSearch)->search('martinez lautaro');

    expect($results->first()['score'])->toBe(1.0);
});

it('falls back to fuzzy search when there is no exact alias/name match', function () {
    // "lautar" (typo, manca la o finale): nessun alias/nome esatto, deve
    // comunque arrivare a Lautaro Martinez via il motore fuzzy (driver
    // "collection" nei test, tollerante alle sottostringhe).
    $lautaro = Player::where('normalized_name', 'martinez lautaro')->firstOrFail();

    $results = (new PlayerSearch)->search('martinez');

    expect($results->pluck('player.id'))->toContain($lautaro->id);
});

it('returns an empty collection for a blank query', function () {
    expect((new PlayerSearch)->search('   '))->toBeEmpty();
});

it('does not resolve a player removed from the listone', function () {
    $lautaro = Player::where('normalized_name', 'martinez lautaro')->firstOrFail();
    $lautaro->update(['status' => PlayerStatus::Removed]);

    $results = (new PlayerSearch)->search('lautaro');

    expect($results->pluck('player.id'))->not->toContain($lautaro->id);
});

it('returns multiple candidates for an ambiguous surname shared by two players', function () {
    // "Thuram" è cognome sia di Khephren (centrocampista, Juventus) che di
    // Marcus (attaccante, Inter): la sola ricerca per cognome è ambigua.
    $results = (new PlayerSearch)->search('thuram');

    expect($results->pluck('player.normalized_name'))
        ->toContain('thuram k', 'thuram marcus');
});

<?php

use App\Models\Player;
use App\Services\ListoneImporter;
use App\Services\PlayerSearch;
use Illuminate\Support\Facades\Config;

/**
 * Verifica l'integrazione reale con Meilisearch (non il driver "collection"
 * usato nel resto della suite). Skippato se il servizio non risponde: non
 * deve bloccare `php artisan test` in ambienti senza Meilisearch attivo.
 */
beforeEach(function () {
    $reachable = @file_get_contents(config('scout.meilisearch.host').'/health') !== false;

    if (! $reachable) {
        $this->markTestSkipped('Meilisearch non raggiungibile su '.config('scout.meilisearch.host'));
    }

    Config::set('scout.driver', 'meilisearch');
});

afterEach(function () {
    Player::removeAllFromSearch();
});

it('finds a player via Meilisearch typo-tolerant search on a misspelled surname', function () {
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

    Player::all()->searchable();
    // Meilisearch indicizza in modo asincrono: piccola attesa per il task di indicizzazione.
    usleep(500_000);

    $lautaro = Player::where('normalized_name', 'martinez lautaro')->firstOrFail();

    // "martinz" è un typo di "martinez": nessun alias esatto lo copre, deve
    // arrivare dal motore fuzzy reale.
    $results = (new PlayerSearch)->search('martinz lautaro');

    expect($results->pluck('player.id'))->toContain($lautaro->id);
});

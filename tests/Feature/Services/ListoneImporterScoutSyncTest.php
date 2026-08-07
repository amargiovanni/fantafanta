<?php

use App\Models\Player;
use App\Services\ListoneImporter;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\NullEngine;

/**
 * ListoneImporter::import() marca i giocatori assenti dal listone con un
 * update via query builder (`Player::whereNotIn(...)->update([...])`), che
 * NON fa scattare l'evento Eloquent `saved` da cui dipende Scout per
 * risincronizzare l'indice di ricerca. Riprodotto e confermato in un
 * collaudo reale su Meilisearch: un giocatore marcato "removed" con questo
 * pattern resta pienamente trovabile nell'indice finché non arriva un
 * resync esplicito.
 *
 * Il driver "collection" usato nel resto della suite non avrebbe rilevato
 * questa regressione: interroga il database dal vivo a ogni ricerca e
 * rivaluta shouldBeSearchable() in tempo reale, quindi risulterebbe sempre
 * corretto indipendentemente dal fix (verificato leggendo
 * Laravel\Scout\Engines\CollectionEngine::searchModels()). Per una verifica
 * onesta si intercetta invece la vera chiamata al motore di ricerca con un
 * motore-spia che registra le chiamate a update()/delete(), esattamente
 * come farebbe l'engine reale di Meilisearch in produzione.
 */
class SpyScoutEngineForRemovalTest extends NullEngine
{
    /** @var array<int, int> */
    public array $updatedIds = [];

    /** @var array<int, int> */
    public array $deletedIds = [];

    public function update($models)
    {
        $this->updatedIds = array_values(array_unique([...$this->updatedIds, ...$models->pluck('id')->all()]));
    }

    public function delete($models)
    {
        $this->deletedIds = array_values(array_unique([...$this->deletedIds, ...$models->pluck('id')->all()]));
    }
}

function scoutSyncFixture(): string
{
    return file_get_contents(base_path('tests/Fixtures/listone-sample.csv'));
}

function scoutSyncMapping(): array
{
    return [
        'name' => 'Nome',
        'role' => 'R',
        'real_team' => 'Squadra',
        'quotazione' => 'Qt.A',
        'fvm' => 'FVM',
    ];
}

it('desyncs a bulk-removed player from the search engine instead of leaving a stale document', function () {
    $engine = new SpyScoutEngineForRemovalTest;
    app(EngineManager::class)->extend('scout-spy-test', fn () => $engine);
    config(['scout.driver' => 'scout-spy-test']);

    $importer = new ListoneImporter;
    $importer->import(scoutSyncFixture(), scoutSyncMapping());

    $lautaro = Player::where('normalized_name', 'martinez lautaro')->firstOrFail();

    // Sanity: la creazione ha davvero raggiunto il motore di ricerca.
    expect($engine->updatedIds)->toContain($lautaro->id)
        ->and($engine->deletedIds)->not->toContain($lautaro->id);

    $lines = explode("\n", trim(scoutSyncFixture()));
    $withoutLautaro = implode("\n", array_values(array_filter(
        $lines,
        fn ($line) => ! str_contains($line, 'MARTINEZ Lautaro')
    )));

    $summary = $importer->import($withoutLautaro, scoutSyncMapping());

    expect($summary['removed'])->toBe(1)
        ->and($engine->deletedIds)->toContain($lautaro->id);
});

it('does not call delete() on the search engine when nothing was removed', function () {
    $engine = new SpyScoutEngineForRemovalTest;
    app(EngineManager::class)->extend('scout-spy-test-2', fn () => $engine);
    config(['scout.driver' => 'scout-spy-test-2']);

    (new ListoneImporter)->import(scoutSyncFixture(), scoutSyncMapping());
    (new ListoneImporter)->import(scoutSyncFixture(), scoutSyncMapping());

    expect($engine->deletedIds)->toBe([]);
});

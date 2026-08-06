<?php

use App\Enums\PlayerRole;
use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\PlayerAlias;
use App\Services\ListoneImporter;

function listoneFixture(): string
{
    return file_get_contents(base_path('tests/Fixtures/listone-sample.csv'));
}

function listoneMapping(): array
{
    return [
        'name' => 'Nome',
        'role' => 'R',
        'real_team' => 'Squadra',
        'quotazione' => 'Qt.A',
        'fvm' => 'FVM',
        'stats' => ['Pv', 'Mv', 'Fm', 'Gf', 'Ass', 'Amm', 'Esp', 'Rig'],
    ];
}

it('previews headers, first rows and a suggested mapping without importing anything', function () {
    $preview = (new ListoneImporter)->preview(listoneFixture());

    expect($preview['headers'])->toContain('Nome', 'R', 'Squadra', 'Qt.A', 'FVM')
        ->and($preview['rows'])->toHaveCount(5)
        ->and($preview['suggested_mapping'])->toBe([
            'name' => 'Nome',
            'role' => 'R',
            'real_team' => 'Squadra',
            'quotazione' => 'Qt.A',
            'fvm' => 'FVM',
        ])
        ->and(Player::count())->toBe(0);
});

it('imports every player from the sample listone with correct roles and zero duplicates', function () {
    $summary = (new ListoneImporter)->import(listoneFixture(), listoneMapping());

    expect($summary['created'])->toBe(30)
        ->and($summary['skipped'])->toBe(0)
        ->and(Player::count())->toBe(30)
        ->and(Player::where('role', PlayerRole::Portiere)->count())->toBe(4)
        ->and(Player::where('role', PlayerRole::Difensore)->count())->toBe(8)
        ->and(Player::where('role', PlayerRole::Centrocampista)->count())->toBe(8)
        ->and(Player::where('role', PlayerRole::Attaccante)->count())->toBe(10)
        ->and(Player::distinct('normalized_name')->count('normalized_name'))->toBe(30);
});

it('captures the mapped stat columns into season_stats', function () {
    (new ListoneImporter)->import(listoneFixture(), listoneMapping());

    $lautaro = Player::where('normalized_name', 'martinez lautaro')->firstOrFail();

    expect($lautaro->season_stats)->toMatchArray(['Pv' => '35', 'Gf' => '22']);
});

it('generates deterministic aliases for tricky names: composite surname, apostrophe, abbreviated initial', function () {
    (new ListoneImporter)->import(listoneFixture(), listoneMapping());

    $diLorenzo = Player::where('normalized_name', 'di lorenzo giovanni')->firstOrFail();
    expect($diLorenzo->aliases()->pluck('normalized_alias'))->toContain('di lorenzo', 'giovanni di lorenzo');

    $ndicka = Player::where('normalized_name', 'ndicka evan')->firstOrFail();
    expect($ndicka->aliases()->pluck('normalized_alias'))->toContain('ndicka', 'evan ndicka');

    // "THURAM K." nel CSV: cognome Thuram, iniziale K, nessun nome completo da invertire.
    $khephren = Player::where('normalized_name', 'thuram k')->firstOrFail();
    expect($khephren->aliases()->pluck('normalized_alias'))->toContain('thuram');
});

it('re-import updates quotazione/fvm without touching existing aliases or creating duplicates', function () {
    $importer = new ListoneImporter;
    $importer->import(listoneFixture(), listoneMapping());

    $lautaroBefore = Player::where('normalized_name', 'martinez lautaro')->firstOrFail();
    $aliasIdsBefore = $lautaroBefore->aliases()->pluck('id')->sort()->values();

    $updatedCsv = str_replace(
        '21,A,Pc,MARTINEZ Lautaro,Inter,38,35,3,38,35,3,180,180,35,6.50,7.60,22,6,2,0,6',
        '21,A,Pc,MARTINEZ Lautaro,Inter,40,35,5,40,35,5,190,190,35,6.50,7.60,22,6,2,0,6',
        listoneFixture()
    );

    $summary = $importer->import($updatedCsv, listoneMapping());

    $lautaroAfter = Player::where('normalized_name', 'martinez lautaro')->firstOrFail();
    $aliasIdsAfter = $lautaroAfter->aliases()->pluck('id')->sort()->values();

    expect($summary['created'])->toBe(0)
        ->and($summary['updated'])->toBe(30)
        ->and($lautaroAfter->quotazione)->toBe(40)
        ->and($lautaroAfter->fvm)->toBe(190)
        ->and($aliasIdsAfter)->toEqual($aliasIdsBefore)
        ->and(Player::count())->toBe(30)
        ->and(PlayerAlias::count())->toBeGreaterThan(0);
});

it('marks players missing from a subsequent import as removed, without deleting them', function () {
    $importer = new ListoneImporter;
    $importer->import(listoneFixture(), listoneMapping());

    $lines = explode("\n", trim(listoneFixture()));
    // Rimuove la riga di Lautaro Martinez (mantenendo titolo + header + le altre 29 righe).
    $withoutLautaro = implode("\n", array_values(array_filter(
        $lines,
        fn ($line) => ! str_contains($line, 'MARTINEZ Lautaro')
    )));

    $summary = $importer->import($withoutLautaro, listoneMapping());

    $lautaro = Player::where('normalized_name', 'martinez lautaro')->firstOrFail();

    expect($summary['removed'])->toBe(1)
        ->and($lautaro->status)->toBe(PlayerStatus::Removed)
        ->and(Player::count())->toBe(30);
});

it('skips rows with an unrecognized role instead of failing the whole import', function () {
    $csv = listoneFixture()."\n".'999,X,Zz,ROSSI Mario,TestFC,10,10,0,10,10,0,40,40,10,6.00,6.00,0,0,0,0,0';

    $summary = (new ListoneImporter)->import($csv, listoneMapping());

    expect($summary['skipped'])->toBe(1)
        ->and(Player::where('normalized_name', 'rossi mario')->exists())->toBeFalse();
});

it('rejects an incomplete column mapping', function () {
    expect(fn () => (new ListoneImporter)->import(listoneFixture(), ['name' => 'Nome']))
        ->toThrow(InvalidArgumentException::class);
});

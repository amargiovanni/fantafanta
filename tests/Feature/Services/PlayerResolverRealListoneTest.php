<?php

use App\Services\ListoneImporter;
use App\Services\PlayerResolver;

/**
 * PlayerResolver sui due omonimi reali "Martinez Jo." (portiere) e
 * "Martinez L." (Lautaro Martinez, attaccante) dopo l'import del vero
 * listone XLSX, quando Claude passa un nome completo trovato in una fonte.
 *
 * Prima del fix (regola di prefisso sull'iniziale puntata), i due candidati
 * pareggiavano sempre a similarity=1.0 tramite l'alias-cognome condiviso
 * "martinez", restando permanentemente ambigui anche quando la query
 * conteneva l'informazione per distinguerli (l'iniziale del nome proprio).
 */
function realListoneMappingForResolver(): array
{
    return [
        'name' => 'Nome',
        'role' => 'R',
        'real_team' => 'Squadra',
        'quotazione' => 'Qt.A',
        'fvm' => 'FVM',
    ];
}

beforeEach(function () {
    (new ListoneImporter)->import(base_path('tests/Fixtures/listone.xlsx'), realListoneMappingForResolver(), 'xlsx');
});

it('matches "Lautaro Martinez" to Martinez L. and registers the alias, because "l" prefixes "lautaro"', function () {
    $outcome = app(PlayerResolver::class)->resolve('Lautaro Martinez');

    expect($outcome['status'])->toBe('matched')
        ->and($outcome['player']->name)->toBe('Martinez L.')
        ->and($outcome['alias_created'])->toBeTrue();

    expect($outcome['player']->aliases()->pluck('normalized_alias'))->toContain('lautaro martinez');
});

it('matches "Joaquin Martinez" to Martinez Jo., because "jo" prefixes "joaquin"', function () {
    $outcome = app(PlayerResolver::class)->resolve('Joaquin Martinez');

    expect($outcome['status'])->toBe('matched')
        ->and($outcome['player']->name)->toBe('Martinez Jo.');
});

it('stays ambiguous on a surname-only query: no token to confirm or contradict either candidate', function () {
    $outcome = app(PlayerResolver::class)->resolve('Martinez');

    expect($outcome['status'])->toBe('ambiguous')
        ->and($outcome['alias_created'])->toBeFalse()
        ->and($outcome['player'])->toBeNull()
        ->and(collect($outcome['candidates'])->pluck('name'))->toContain('Martinez Jo.', 'Martinez L.');
});

it('still matches a unique real surname with no dotted initial, unaffected by the new rule', function () {
    $outcome = app(PlayerResolver::class)->resolve('Di Lorenzo');

    expect($outcome['status'])->toBe('matched')
        ->and($outcome['player']->name)->toBe('Di Lorenzo');
});

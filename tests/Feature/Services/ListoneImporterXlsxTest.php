<?php

use App\Enums\PlayerRole;
use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Services\ListoneImporter;
use App\Services\PlayerSearch;

function realListoneXlsxFixturePath(): string
{
    return base_path('tests/Fixtures/listone.xlsx');
}

function realListoneMapping(): array
{
    return [
        'name' => 'Nome',
        'role' => 'R',
        'real_team' => 'Squadra',
        'quotazione' => 'Qt.A',
        'fvm' => 'FVM',
        'stats' => ['RM', 'Qt.I', 'Diff.'],
    ];
}

it('previews the real xlsx listone with the correct headers and suggested mapping', function () {
    $preview = (new ListoneImporter)->preview(realListoneXlsxFixturePath(), format: 'xlsx');

    expect($preview['headers'])->toBe([
        'Id', 'R', 'RM', 'Nome', 'Squadra', 'Qt.A', 'Qt.I', 'Diff.', 'Qt.A M', 'Qt.I M', 'Diff.M', 'FVM', 'FVM M',
    ])
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

it('imports every real player from the official xlsx listone with correct role counts and zero duplicates', function () {
    $summary = (new ListoneImporter)->import(realListoneXlsxFixturePath(), realListoneMapping(), format: 'xlsx');

    // 495 righe totali - 1 titolo - 1 header = 493 giocatori reali.
    expect($summary['created'])->toBe(493)
        ->and($summary['updated'])->toBe(0)
        ->and($summary['skipped'])->toBe(0)
        ->and($summary['removed'])->toBe(0)
        ->and(Player::count())->toBe(493)
        ->and(Player::distinct('normalized_name')->count('normalized_name'))->toBe(493)
        ->and(Player::where('role', PlayerRole::Portiere)->count())->toBe(60)
        ->and(Player::where('role', PlayerRole::Difensore)->count())->toBe(175)
        ->and(Player::where('role', PlayerRole::Centrocampista)->count())->toBe(173)
        ->and(Player::where('role', PlayerRole::Attaccante)->count())->toBe(85);
});

it('generates "surname" and "surname+initial" aliases for every real name abbreviated with a dot', function () {
    // Solo 80 dei 493 nomi reali portano un'iniziale puntata ("Martinez Jo.",
    // "Martinez L."...): gli altri 413 sono nomi singoli o composti senza
    // punto ("Svilar", "Di Lorenzo") e non generano alias, perché non c'è
    // nulla da disambiguare. Il totale (171) è calcolato deterministicamente
    // dal file reale, non stimato: verificato separatamente estraendo a mano
    // i 493 valori "Nome" dal foglio e rieseguendo la stessa logica di
    // generateAliases() al di fuori del framework.
    $summary = (new ListoneImporter)->import(realListoneXlsxFixturePath(), realListoneMapping(), format: 'xlsx');

    expect($summary['aliases_created'])->toBe(171);

    $martinezJo = Player::where('normalized_name', 'martinez jo')->firstOrFail();
    $martinezL = Player::where('normalized_name', 'martinez l')->firstOrFail();

    // "Martinez Jo.": l'iniziale abbreviata è multi-lettera ("Jo"), quindi
    // "cognome+iniziale" ("martinez j") resta distinto dal nome proprio
    // ("martinez jo") e sopravvive al filtro anti-duplicato: 3 alias.
    expect($martinezJo->aliases()->pluck('normalized_alias')->sort()->values()->all())
        ->toBe(['jo martinez', 'martinez', 'martinez j']);

    // "Martinez L.": l'iniziale abbreviata è già una sola lettera, quindi
    // "cognome+iniziale" ("martinez l") COINCIDE col nome proprio e viene
    // scartato dal filtro anti-duplicato (stessa regola già in uso per il
    // formato storico, es. "Thuram K." -> solo alias "thuram"): 2 alias.
    expect($martinezL->aliases()->pluck('normalized_alias')->sort()->values()->all())
        ->toBe(['l martinez', 'martinez']);
});

it('resolves "martinez" to both real homonyms and "di lorenzo"/"svilar" directly by name', function () {
    (new ListoneImporter)->import(realListoneXlsxFixturePath(), realListoneMapping(), format: 'xlsx');

    $search = app(PlayerSearch::class);

    $martinez = $search->search('martinez', 10);
    expect($martinez->pluck('player.normalized_name'))->toContain('martinez jo', 'martinez l');

    $diLorenzo = $search->search('di lorenzo', 10);
    expect($diLorenzo->pluck('player.normalized_name'))->toContain('di lorenzo');

    $svilar = $search->search('svilar', 10);
    expect($svilar->pluck('player.normalized_name'))->toContain('svilar');
});

it('reads the fantacalcio.it id into the mapped season_stats when mapped', function () {
    (new ListoneImporter)->import(realListoneXlsxFixturePath(), [
        'name' => 'Nome',
        'role' => 'R',
        'real_team' => 'Squadra',
        'quotazione' => 'Qt.A',
        'fvm' => 'FVM',
        'stats' => ['Id'],
    ], format: 'xlsx');

    $svilar = Player::where('normalized_name', 'svilar')->firstOrFail();

    expect($svilar->season_stats)->toMatchArray(['Id' => '5841'])
        ->and($svilar->real_team)->toBe('Roma')
        ->and($svilar->quotazione)->toBe(18)
        ->and($svilar->fvm)->toBe(65);
});

it('correctly imports the abbreviated real name "Martinez Jo." as a distinct, searchable player', function () {
    (new ListoneImporter)->import(realListoneXlsxFixturePath(), realListoneMapping(), format: 'xlsx');

    $martinezJo = Player::where('normalized_name', 'martinez jo')->first();

    expect($martinezJo)->not->toBeNull()
        ->and($martinezJo->role)->toBe(PlayerRole::Portiere)
        ->and($martinezJo->real_team)->toBe('Inter')
        ->and($martinezJo->quotazione)->toBe(17)
        ->and($martinezJo->fvm)->toBe(63);
});

it('re-imports the real xlsx listone idempotently: same file twice updates instead of duplicating', function () {
    $importer = new ListoneImporter;
    $importer->import(realListoneXlsxFixturePath(), realListoneMapping(), format: 'xlsx');

    $countAfterFirst = Player::count();
    $svilarBefore = Player::where('normalized_name', 'svilar')->firstOrFail();
    $aliasIdsBefore = $svilarBefore->aliases()->pluck('id')->sort()->values();

    $summary = $importer->import(realListoneXlsxFixturePath(), realListoneMapping(), format: 'xlsx');

    $svilarAfter = Player::where('normalized_name', 'svilar')->firstOrFail();

    expect($summary['created'])->toBe(0)
        ->and($summary['updated'])->toBe(493)
        ->and($summary['removed'])->toBe(0)
        ->and(Player::count())->toBe($countAfterFirst)
        ->and($svilarAfter->aliases()->pluck('id')->sort()->values())->toEqual($aliasIdsBefore);
});

it('marks players absent from the real xlsx as removed on re-import, without deleting them', function () {
    (new ListoneImporter)->import(realListoneXlsxFixturePath(), realListoneMapping(), format: 'xlsx');

    $csvSummary = (new ListoneImporter)->import(
        "titolo generico\nId,R,RM,Nome,Squadra,Qt.A,Qt.I,Diff.,Qt.A M,Qt.I M,Diff.M,FVM,FVM M\n"
        .'999,A,A,Giocatore Fittizio,TestFC,10,10,0,10,10,0,40,40',
        realListoneMapping()
    );

    // Il nuovo import CSV (una sola riga) non "tocca" nessuno dei 493 giocatori xlsx: risultano tutti rimossi.
    expect($csvSummary['created'])->toBe(1)
        ->and($csvSummary['removed'])->toBe(493)
        ->and(Player::where('normalized_name', 'svilar')->firstOrFail()->status)->toBe(PlayerStatus::Removed)
        ->and(Player::count())->toBe(494);
});

it('throws a clear error when the xlsx file is corrupted', function () {
    $path = tempnam(sys_get_temp_dir(), 'listone-corrupted-').'.xlsx';
    file_put_contents($path, 'questo non e un file xlsx valido');

    expect(fn () => (new ListoneImporter)->preview($path, format: 'xlsx'))
        ->toThrow(RuntimeException::class);

    @unlink($path);
});

<?php

use App\Services\ListoneImporter;
use App\Services\PlayerResolver;

/**
 * Verifica onesta (richiesta esplicitamente in revisione, non assunta) del
 * comportamento di PlayerResolver::resolve() sui due omonimi reali "Martinez
 * Jo." e "Martinez L." dopo l'import del vero listone XLSX, quando Claude
 * passa un nome completo trovato in una fonte ("Lautaro Martinez").
 */
it('does NOT auto-match a full name shared surname between two real homonyms: it stays ambiguous by design', function () {
    (new ListoneImporter)->import(base_path('tests/Fixtures/listone.xlsx'), [
        'name' => 'Nome',
        'role' => 'R',
        'real_team' => 'Squadra',
        'quotazione' => 'Qt.A',
        'fvm' => 'FVM',
    ], 'xlsx');

    $outcome = app(PlayerResolver::class)->resolve('Lautaro Martinez');

    // "Martinez Jo." (portiere) e "Martinez L." (attaccante, il vero Lautaro
    // Martinez) condividono l'alias "martinez" generato per entrambi dal
    // fix di FantacalcioNameParser. NameSimilarity assegna 1.0 a un alias di
    // un solo token che compare per intero nella query più lunga, quindi
    // ENTRAMBI i candidati arrivano a similarity=1.0: nessuno supera l'altro
    // del margine richiesto (0.10), quindi PlayerResolver NON assegna in
    // automatico né registra l'alias "lautaro martinez" — resta "ambiguous",
    // esattamente come da design (§10: mai indovinare sotto soglia).
    expect($outcome['status'])->toBe('ambiguous')
        ->and($outcome['alias_created'])->toBeFalse()
        ->and($outcome['player'])->toBeNull()
        ->and(collect($outcome['candidates'])->pluck('name'))->toContain('Martinez Jo.', 'Martinez L.');
});

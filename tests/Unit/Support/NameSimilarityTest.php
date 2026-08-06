<?php

use App\Support\NameNormalizer;
use App\Support\NameSimilarity;

function similarity(string $a, string $b): float
{
    return NameSimilarity::score(NameNormalizer::normalize($a), NameNormalizer::normalize($b));
}

it('riconosce le forme abbreviate dello stesso giocatore', function (string $forma) {
    expect(similarity($forma, 'MARTINEZ Lautaro'))->toBeGreaterThanOrEqual(0.85);
})->with(['lautaro', 'Martinez L.', 'martinez lautaro', 'LAUTARO MARTINEZ', 'Martínez']);

it('non confonde giocatori diversi', function (string $a, string $b) {
    expect(similarity($a, $b))->toBeLessThan(0.85);
})->with([
    ['Dumfries', 'MARTINEZ Lautaro'],
    ['Thuram Marcus', 'THURAM Khephren'],
    ['Vlahovic', 'Vlasic'],
]);

it('non attribuisce un nome a partire da una sola iniziale', function () {
    expect(similarity('L.', 'MARTINEZ Lautaro'))->toBeLessThan(0.85);
});

it('vale 1.0 per due nomi identici e 0.0 se uno è vuoto', function () {
    expect(similarity('Lautaro', 'lautaro'))->toBe(1.0)
        ->and(similarity('', 'Lautaro'))->toBe(0.0);
});

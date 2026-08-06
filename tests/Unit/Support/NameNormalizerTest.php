<?php

use App\Support\NameNormalizer;

it('lowercases the name', function () {
    expect(NameNormalizer::normalize('LAUTARO MARTINEZ'))->toBe('lautaro martinez');
});

it('removes accents', function () {
    expect(NameNormalizer::normalize('Martínez'))->toBe('martinez');
});

it('removes apostrophes without inserting a space', function () {
    expect(NameNormalizer::normalize("N'Dicka"))->toBe('ndicka');
});

it('removes punctuation such as trailing periods on initials', function () {
    expect(NameNormalizer::normalize('Thuram K.'))->toBe('thuram k');
});

it('collapses multiple internal spaces', function () {
    expect(NameNormalizer::normalize('Di   Lorenzo   Giovanni'))->toBe('di lorenzo giovanni');
});

it('trims leading and trailing whitespace', function () {
    expect(NameNormalizer::normalize('  Kaio Jorge  '))->toBe('kaio jorge');
});

it('is idempotent', function () {
    $once = NameNormalizer::normalize("N'Dicka Evan");
    expect(NameNormalizer::normalize($once))->toBe($once);
});

it('treats equivalent inputs identically regardless of case, accents and spacing', function () {
    $variants = ['lautaro martinez', 'LAUTARO MARTINEZ', 'Lautaro   Martínez'];

    $normalized = array_map(fn ($v) => NameNormalizer::normalize($v), $variants);

    expect($normalized)->each->toBe('lautaro martinez');
});

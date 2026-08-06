<?php

use App\Support\FantacalcioNameParser;

it('parses the "COGNOME Nome" format', function () {
    $parsed = FantacalcioNameParser::parse('MARTINEZ Lautaro');

    expect($parsed['surname'])->toBe('MARTINEZ')
        ->and($parsed['given'])->toBe('Lautaro')
        ->and($parsed['given_initial'])->toBe('L')
        ->and($parsed['display'])->toBe('Martinez Lautaro');
});

it('parses the abbreviated "Cognome N." format', function () {
    $parsed = FantacalcioNameParser::parse('THURAM K.');

    expect($parsed['surname'])->toBe('THURAM')
        ->and($parsed['given'])->toBe('K.')
        ->and($parsed['given_initial'])->toBe('K')
        ->and($parsed['display'])->toBe('Thuram K.');
});

it('recognizes composite uppercase surnames', function () {
    $parsed = FantacalcioNameParser::parse('DI LORENZO Giovanni');

    expect($parsed['surname'])->toBe('DI LORENZO')
        ->and($parsed['given'])->toBe('Giovanni')
        ->and($parsed['display'])->toBe('Di Lorenzo Giovanni');
});

it('keeps apostrophes in the surname token', function () {
    $parsed = FantacalcioNameParser::parse("N'DICKA Evan");

    expect($parsed['surname'])->toBe("N'DICKA")
        ->and($parsed['given'])->toBe('Evan');
});

it('falls back to treating the whole string as surname when no given name is present', function () {
    $parsed = FantacalcioNameParser::parse('NERES');

    expect($parsed['surname'])->toBe('NERES')
        ->and($parsed['given'])->toBe('')
        ->and($parsed['given_initial'])->toBeNull();
});

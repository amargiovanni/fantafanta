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

// Formato reale xlsx fantacalcio.it: Title Case, non MAIUSCOLO. Il cognome
// non si riconosce più dal casing (non è più tutto maiuscolo) ma dal fatto
// che l'iniziale del nome, quando presente, termina sempre con un punto.

it('parses the real xlsx "Cognome I." abbreviated format', function () {
    $parsed = FantacalcioNameParser::parse('Martinez Jo.');

    expect($parsed['surname'])->toBe('Martinez')
        ->and($parsed['given'])->toBe('Jo.')
        ->and($parsed['given_initial'])->toBe('J')
        ->and($parsed['display'])->toBe('Martinez Jo.');
});

it('parses another real xlsx "Cognome I." abbreviation for a different homonym', function () {
    $parsed = FantacalcioNameParser::parse('Martinez L.');

    expect($parsed['surname'])->toBe('Martinez')
        ->and($parsed['given'])->toBe('L.')
        ->and($parsed['given_initial'])->toBe('L')
        ->and($parsed['display'])->toBe('Martinez L.');
});

it('treats a single real-format token with no dot as a surname-only player', function () {
    $parsed = FantacalcioNameParser::parse('Svilar');

    expect($parsed['surname'])->toBe('Svilar')
        ->and($parsed['given'])->toBe('')
        ->and($parsed['given_initial'])->toBeNull();
});

it('treats a multi-word real-format name with no dot as a composite surname, not cognome+nome', function () {
    $parsed = FantacalcioNameParser::parse('Di Lorenzo');

    expect($parsed['surname'])->toBe('Di Lorenzo')
        ->and($parsed['given'])->toBe('')
        ->and($parsed['given_initial'])->toBeNull();
});

it('keeps the historic ALL-CAPS format working even though the new dot-suffix rule exists', function () {
    // Nessun token termina con ".", ma "MARTINEZ" è tutto maiuscolo: il
    // formato storico ha sempre la precedenza quando lo riconosce.
    $parsed = FantacalcioNameParser::parse('MARTINEZ Lautaro');

    expect($parsed['surname'])->toBe('MARTINEZ')
        ->and($parsed['given'])->toBe('Lautaro');
});

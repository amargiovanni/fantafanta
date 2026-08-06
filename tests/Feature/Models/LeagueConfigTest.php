<?php

use App\Models\LeagueConfig;

it('creates the singleton row with Classic defaults on first access', function () {
    $config = LeagueConfig::current();

    expect($config->id)->toBe(1)
        ->and($config->slots)->toBe(['P' => 3, 'D' => 8, 'C' => 8, 'A' => 6])
        ->and($config->modifier_defense)->toBeTrue()
        ->and($config->modifier_fairplay)->toBeTrue()
        ->and($config->auction_type)->toBe('random');
});

it('returns the same row on subsequent calls', function () {
    $first = LeagueConfig::current();
    $first->update(['total_credits' => 750]);

    $second = LeagueConfig::current();

    expect($second->id)->toBe($first->id)
        ->and($second->total_credits)->toBe(750)
        ->and(LeagueConfig::count())->toBe(1);
});

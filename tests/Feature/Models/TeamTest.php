<?php

use App\Models\Team;

it('enforces a single team with is_mine true, unsetting the previous one', function () {
    $first = Team::factory()->create(['is_mine' => true]);
    $second = Team::factory()->create(['is_mine' => true]);

    expect($first->fresh()->is_mine)->toBeFalse()
        ->and($second->fresh()->is_mine)->toBeTrue();
});

it('keeps is_mine true when re-saving the same team', function () {
    $team = Team::factory()->create(['is_mine' => true]);

    $team->credits_total = 480;
    $team->save();

    expect($team->fresh()->is_mine)->toBeTrue();
});

it('derives credits_spent as zero in phase 0 (no acquisitions yet)', function () {
    $team = Team::factory()->create(['credits_total' => 500]);

    expect($team->credits_spent)->toBe(0)
        ->and($team->credits_remaining)->toBe(500);
});

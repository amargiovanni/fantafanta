<?php

use App\Livewire\Listone\Import;
use App\Models\Player;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

it('renders the import route', function () {
    $this->get(route('listone.import'))->assertOk();
});

it('previews headers and a suggested mapping after uploading a CSV', function () {
    $csv = file_get_contents(base_path('tests/Fixtures/listone-sample.csv'));
    $upload = UploadedFile::fake()->createWithContent('listone.csv', $csv);

    Livewire::test(Import::class)
        ->set('file', $upload)
        ->assertSet('mapping.name', 'Nome')
        ->assertSet('mapping.role', 'R')
        ->assertSet('mapping.real_team', 'Squadra')
        ->assertSet('mapping.quotazione', 'Qt.A')
        ->assertSet('mapping.fvm', 'FVM')
        ->assertSee('BASTONI Alessandro');

    expect(Player::count())->toBe(0);
});

it('imports the listone once the mapping is confirmed', function () {
    $csv = file_get_contents(base_path('tests/Fixtures/listone-sample.csv'));
    $upload = UploadedFile::fake()->createWithContent('listone.csv', $csv);

    Livewire::test(Import::class)
        ->set('file', $upload)
        ->set('statsColumns', ['Pv', 'Gf'])
        ->call('confirmImport')
        ->assertSet('summary.created', 30);

    expect(Player::count())->toBe(30)
        ->and(Player::where('normalized_name', 'martinez lautaro')->first()->season_stats)
        ->toMatchArray(['Pv' => '35', 'Gf' => '22']);
});

it('requires the full column mapping before confirming', function () {
    $csv = file_get_contents(base_path('tests/Fixtures/listone-sample.csv'));
    $upload = UploadedFile::fake()->createWithContent('listone.csv', $csv);

    Livewire::test(Import::class)
        ->set('file', $upload)
        ->set('mapping.fvm', '')
        ->call('confirmImport')
        ->assertHasErrors(['mapping.fvm' => 'required']);

    expect(Player::count())->toBe(0);
});

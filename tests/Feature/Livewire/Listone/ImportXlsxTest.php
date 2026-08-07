<?php

use App\Livewire\Listone\Import;
use App\Models\Player;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('previews headers and a suggested mapping after uploading the real xlsx listone', function () {
    Storage::fake('local');

    $xlsx = file_get_contents(base_path('tests/Fixtures/listone.xlsx'));
    $upload = UploadedFile::fake()->createWithContent('listone.xlsx', $xlsx);

    $component = Livewire::test(Import::class)
        ->set('file', $upload)
        ->assertSet('format', 'xlsx')
        ->assertSet('mapping.name', 'Nome')
        ->assertSet('mapping.role', 'R')
        ->assertSet('mapping.real_team', 'Squadra')
        ->assertSet('mapping.quotazione', 'Qt.A')
        ->assertSet('mapping.fvm', 'FVM')
        ->assertSee('Svilar');

    expect(Player::count())->toBe(0);

    // Il binario non è tenuto in una proprietà stringa: è salvato su disco, il path è nel componente.
    $storedPath = $component->get('xlsxStoragePath');
    expect($storedPath)->not->toBeNull();
    Storage::disk('local')->assertExists($storedPath);
    expect($component->get('csvContent'))->toBe('');
});

it('imports the full real xlsx listone once the mapping is confirmed', function () {
    Storage::fake('local');

    $xlsx = file_get_contents(base_path('tests/Fixtures/listone.xlsx'));
    $upload = UploadedFile::fake()->createWithContent('listone.xlsx', $xlsx);

    $component = Livewire::test(Import::class)
        ->set('file', $upload)
        ->set('statsColumns', ['Id'])
        ->call('confirmImport')
        ->assertSet('summary.created', 493)
        ->assertSet('summary.updated', 0)
        ->assertSet('summary.skipped', 0);

    expect(Player::count())->toBe(493)
        ->and(Player::where('normalized_name', 'svilar')->first()->season_stats)
        ->toMatchArray(['Id' => '5841']);

    // Il file temporaneo va ripulito a import concluso.
    expect($component->get('xlsxStoragePath'))->toBeNull();
});

it('cleans up the stored xlsx file when the import is cancelled', function () {
    Storage::fake('local');

    $xlsx = file_get_contents(base_path('tests/Fixtures/listone.xlsx'));
    $upload = UploadedFile::fake()->createWithContent('listone.xlsx', $xlsx);

    $component = Livewire::test(Import::class)->set('file', $upload);

    $storedPath = $component->get('xlsxStoragePath');
    Storage::disk('local')->assertExists($storedPath);

    $component->call('cancelImport');

    Storage::disk('local')->assertMissing($storedPath);
    expect($component->get('xlsxStoragePath'))->toBeNull()
        ->and($component->get('headers'))->toBe([])
        ->and(Player::count())->toBe(0);
});

it('cleans up the previous stored xlsx file when a new file is uploaded in its place', function () {
    Storage::fake('local');

    $xlsx = file_get_contents(base_path('tests/Fixtures/listone.xlsx'));
    $firstUpload = UploadedFile::fake()->createWithContent('listone.xlsx', $xlsx);
    $secondUpload = UploadedFile::fake()->createWithContent('listone-2.xlsx', $xlsx);

    $component = Livewire::test(Import::class)->set('file', $firstUpload);
    $firstPath = $component->get('xlsxStoragePath');
    Storage::disk('local')->assertExists($firstPath);

    $component->set('file', $secondUpload);
    $secondPath = $component->get('xlsxStoragePath');

    expect($secondPath)->not->toBe($firstPath);
    Storage::disk('local')->assertMissing($firstPath);
    Storage::disk('local')->assertExists($secondPath);
});

it('still accepts a plain CSV listone alongside xlsx', function () {
    Storage::fake('local');

    $csv = file_get_contents(base_path('tests/Fixtures/listone-sample.csv'));
    $upload = UploadedFile::fake()->createWithContent('listone.csv', $csv);

    Livewire::test(Import::class)
        ->set('file', $upload)
        ->assertSet('format', 'csv')
        ->assertSet('xlsxStoragePath', null)
        ->call('confirmImport')
        ->assertSet('summary.created', 30);

    expect(Player::count())->toBe(30);
});

it('rejects a file extension that is neither xlsx, csv nor txt', function () {
    Storage::fake('local');

    $upload = UploadedFile::fake()->create('listone.pdf', 10);

    Livewire::test(Import::class)
        ->set('file', $upload)
        ->assertHasErrors(['file']);
});

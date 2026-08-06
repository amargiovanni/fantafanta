<?php

use App\Livewire\Dashboard;
use App\Livewire\League\Manage as LeagueManage;
use App\Livewire\Listone\Import as ListoneImport;
use App\Livewire\Listone\Index as ListoneIndex;
use Illuminate\Support\Facades\Route;

Route::livewire('/', Dashboard::class)->name('dashboard');
Route::livewire('/listone', ListoneIndex::class)->name('listone.index');
Route::livewire('/listone/import', ListoneImport::class)->name('listone.import');
Route::livewire('/lega', LeagueManage::class)->name('lega.manage');

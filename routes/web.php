<?php

use App\Livewire\Dashboard;
use App\Livewire\Knowledge\Index as KnowledgeIndex;
use App\Livewire\Knowledge\Review as KnowledgeReview;
use App\Livewire\Knowledge\Signals as KnowledgeSignals;
use App\Livewire\League\Manage as LeagueManage;
use App\Livewire\Listone\Import as ListoneImport;
use App\Livewire\Listone\Index as ListoneIndex;
use Illuminate\Support\Facades\Route;

Route::livewire('/', Dashboard::class)->name('dashboard');
Route::livewire('/listone', ListoneIndex::class)->name('listone.index');
Route::livewire('/listone/import', ListoneImport::class)->name('listone.import');
Route::livewire('/lega', LeagueManage::class)->name('lega.manage');

Route::livewire('/conoscenza', KnowledgeIndex::class)->name('conoscenza.index');
Route::livewire('/conoscenza/revisione', KnowledgeReview::class)->name('conoscenza.revisione');
Route::livewire('/conoscenza/segnali', KnowledgeSignals::class)->name('conoscenza.segnali');

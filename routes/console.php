<?php

use App\Jobs\RunScheduledScrape;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scraping schedulato (briefing §7.4): ogni `schedule_interval_minutes`
// (default 30) su tutte le testate abilitate. L'intervallo è configurabile
// quindi non un metodo fluente fisso come `everyThirtyMinutes()`, ma
// un'espressione cron costruita sul valore di config. `withoutOverlapping`
// evita che un giro lento si sovrapponga al successivo.
$scrapeIntervalMinutes = max(1, (int) config('fanta.scraping.schedule_interval_minutes', 30));

Schedule::job(new RunScheduledScrape)
    ->cron("*/{$scrapeIntervalMinutes} * * * *")
    ->name('scraping:scheduled')
    ->onOneServer()
    ->withoutOverlapping();

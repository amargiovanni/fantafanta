<?php

use App\Models\ScrapeTarget;
use Database\Seeders\ScrapeTargetSeeder;

it('seeds the newly verified outlets with their rss feeds', function () {
    (new ScrapeTargetSeeder)->run();

    $expected = [
        'ANSA — Calcio' => 'https://www.ansa.it/sito/notizie/sport/calcio/calcio_rss.xml',
        'Tuttosport — Serie A' => 'https://www.tuttosport.com/rss/calcio/serie-a',
        'Alfredo Pedullà' => 'https://www.alfredopedulla.com/feed/',
        'StadioSport' => 'https://www.stadiosport.it/feed/',
    ];

    foreach ($expected as $name => $rssUrl) {
        $target = ScrapeTarget::where('name', $name)->firstOrFail();

        expect($target->rss_url)->toBe($rssUrl)
            ->and($target->enabled)->toBeTrue();
    }
});

it('fills in the previously-missing rss feed for Corriere dello Sport — Fantacalcio', function () {
    (new ScrapeTargetSeeder)->run();

    $target = ScrapeTarget::where('url', 'https://www.corrieredellosport.it/fantacalcio')->firstOrFail();

    expect($target->rss_url)->toBe('https://www.corrieredellosport.it/rss/fantacalcio');
});

it('is idempotent: running the seeder twice does not duplicate any target', function () {
    (new ScrapeTargetSeeder)->run();
    (new ScrapeTargetSeeder)->run();

    expect(ScrapeTarget::where('url', 'https://www.ansa.it/sito/notizie/sport/calcio/')->count())->toBe(1);
});

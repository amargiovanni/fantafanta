<?php

namespace Database\Seeders;

use App\Models\ScrapeTarget;
use Illuminate\Database\Seeder;

/**
 * Testate fantacalcistiche italiane monitorate dalla pipeline di conoscenza.
 *
 * `rss_url` è valorizzato SOLO dove il feed è stato verificato realmente
 * (risposta 200 con content-type XML il 2026-08-06). Dove il feed non esiste
 * o è protetto da anti-bot il campo resta null: la Fase 4 farà crawl della
 * sezione news, come previsto dal briefing §7.4 ("preferire RSS" non
 * significa inventarne uno che non risponde).
 */
class ScrapeTargetSeeder extends Seeder
{
    public function run(): void
    {
        $targets = [
            [
                'name' => 'Fantacalcio.it — News',
                'url' => 'https://www.fantacalcio.it/news',
                // Nessun feed pubblico funzionante: /rss/news e /rss.xml
                // rispondono HTML. Crawl della sezione news in Fase 4.
                'rss_url' => null,
            ],
            [
                'name' => 'FantaMaster',
                'url' => 'https://www.fantamaster.it',
                'rss_url' => 'https://www.fantamaster.it/feed/',
            ],
            [
                'name' => 'SOS Fanta',
                'url' => 'https://www.sosfanta.com',
                'rss_url' => 'https://www.sosfanta.com/feed/',
            ],
            [
                'name' => 'Gazzetta dello Sport — Serie A',
                'url' => 'https://www.gazzetta.it/Calcio/Serie-A/',
                'rss_url' => 'https://www.gazzetta.it/rss/serie-a.xml',
            ],
            [
                'name' => 'Magic Gazzetta — Fantacalcio',
                'url' => 'https://magic.gazzetta.it',
                'rss_url' => null,
            ],
            [
                'name' => 'TuttoMercatoWeb — Serie A',
                'url' => 'https://www.tuttomercatoweb.com',
                // Feed dietro protezione anti-bot (403): crawl con user-agent
                // identificato in Fase 4.
                'rss_url' => null,
            ],
            [
                'name' => 'Calciomercato.com — Serie A',
                'url' => 'https://www.calciomercato.com/serie-a',
                'rss_url' => null,
            ],
            [
                'name' => 'Corriere dello Sport — Fantacalcio',
                'url' => 'https://www.corrieredellosport.it/fantacalcio',
                'rss_url' => null,
            ],
        ];

        foreach ($targets as $target) {
            ScrapeTarget::query()->updateOrCreate(
                ['url' => $target['url']],
                [
                    'name' => $target['name'],
                    'rss_url' => $target['rss_url'],
                    'enabled' => true,
                ],
            );
        }
    }
}

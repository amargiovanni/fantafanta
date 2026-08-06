<?php

namespace Database\Seeders;

use App\Models\ScrapeTarget;
use Illuminate\Database\Seeder;

/**
 * Testate fantacalcistiche italiane monitorate dalla pipeline di conoscenza.
 *
 * `rss_url` è valorizzato SOLO dove il feed è stato verificato realmente
 * (risposta 200, content-type XML, contenuto aggiornato). Dove il feed non
 * esiste, è protetto da anti-bot o è morto/stantio, il campo resta null:
 * `ParserRegistry` instrada su `HtmlListParser` (crawl della sezione news),
 * come previsto dal briefing §7.4 ("preferire RSS" non significa tenerne
 * uno che non risponde più).
 *
 * Riverifica 2026-08-07 (Fase 5): FantaMaster e SOS Fanta rispondono 200
 * con articoli datati nelle ultime ore — confermati. Il feed Gazzetta
 * (verificato "vivo" il 2026-08-06) risponde 200 ma il contenuto è fermo al
 * 14 novembre 2023: un feed morto che mente sullo status code. Azzerato:
 * il fallback HtmlListParser copre la sezione Serie A.
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
                // /feed/ risponde 301 -> /feed (senza slash finale): usiamo
                // direttamente l'URL canonico per risparmiare un redirect.
                'rss_url' => 'https://www.sosfanta.com/feed',
            ],
            [
                'name' => 'Gazzetta dello Sport — Serie A',
                'url' => 'https://www.gazzetta.it/Calcio/Serie-A/',
                // Feed morto: risponde 200 ma il contenuto è fermo al
                // 14/11/2023 (verificato 2026-08-07). HtmlListParser copre.
                'rss_url' => null,
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

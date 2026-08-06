<?php

namespace App\Console\Commands;

use App\Services\SystemHealth;
use Illuminate\Console\Command;

/**
 * Da lanciare prima dell'asta, e ogni volta che qualcosa "non parte".
 */
class AiHealthcheck extends Command
{
    protected $signature = 'ai:healthcheck';

    protected $description = 'Verifica i servizi da cui dipende la pipeline AI: claude CLI, Redis, Horizon, Meilisearch, server MCP.';

    public function handle(SystemHealth $health): int
    {
        $results = $health->check();

        $this->newLine();

        foreach ($results as $result) {
            // Il padding va applicato al testo nudo: i tag di colore non
            // occupano colonne a schermo ma conterebbero in sprintf.
            $this->line(sprintf(
                ' %s  %s  <fg=gray>%s</>',
                $result['ok'] ? '<fg=green>OK</>' : '<fg=red>KO</>',
                str_pad($result['name'], 28),
                $result['detail'],
            ));
        }

        $this->newLine();

        if ($health->allHealthy($results)) {
            $this->info('Tutti i servizi rispondono: la pipeline AI può girare.');

            return self::SUCCESS;
        }

        $this->error('Almeno un servizio non risponde: la pipeline AI non è affidabile finché non è risolto.');

        return self::FAILURE;
    }
}

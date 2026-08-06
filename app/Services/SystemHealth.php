<?php

namespace App\Services;

use App\Mcp\Servers\FantaAstaServer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Stato dei servizi da cui dipende la pipeline AI.
 *
 * Il rischio operativo numero uno non è un bug: è che la sera dell'asta uno
 * fra `claude`, Redis o Meilisearch sia semplicemente giù (design §5). Questo
 * servizio dà la risposta in un colpo solo, sia da riga di comando sia in
 * dashboard.
 */
class SystemHealth
{
    /**
     * @return array<int, array{key: string, name: string, ok: bool, detail: string}>
     */
    public function check(): array
    {
        return [
            $this->claude(),
            $this->redis(),
            $this->meilisearch(),
            $this->mcp(),
        ];
    }

    /**
     * @param  array<int, array{ok: bool}>  $results
     */
    public function allHealthy(array $results): bool
    {
        return collect($results)->every(fn (array $result) => $result['ok']);
    }

    /**
     * @return array{key: string, name: string, ok: bool, detail: string}
     */
    private function claude(): array
    {
        $binary = (string) config('fanta.claude.binary');

        try {
            $result = Process::timeout(20)->run([$binary, '--version']);

            return $result->successful()
                ? $this->ok('claude', 'Claude Code CLI', trim($result->output()))
                : $this->ko('claude', 'Claude Code CLI', trim($result->errorOutput()) ?: "uscito con codice {$result->exitCode()}");
        } catch (Throwable $exception) {
            return $this->ko('claude', 'Claude Code CLI', $exception->getMessage()." (binario atteso: {$binary})");
        }
    }

    /**
     * @return array{key: string, name: string, ok: bool, detail: string}
     */
    private function redis(): array
    {
        try {
            Redis::connection()->ping();

            return $this->ok('redis', 'Redis (code Horizon)', 'ping riuscito');
        } catch (Throwable $exception) {
            return $this->ko('redis', 'Redis (code Horizon)', $exception->getMessage().' — prova: brew services start redis');
        }
    }

    /**
     * @return array{key: string, name: string, ok: bool, detail: string}
     */
    private function meilisearch(): array
    {
        $url = (string) config('fanta.meilisearch_health_url');

        try {
            $response = Http::timeout(5)->get($url);
            $status = $response->json('status');

            return $response->successful() && $status === 'available'
                ? $this->ok('meilisearch', 'Meilisearch (ricerca fuzzy)', 'stato: '.$status)
                : $this->ko('meilisearch', 'Meilisearch (ricerca fuzzy)', 'risposta inattesa da '.$url);
        } catch (Throwable $exception) {
            return $this->ko('meilisearch', 'Meilisearch (ricerca fuzzy)', $exception->getMessage().' — prova: brew services start meilisearch');
        }
    }

    /**
     * Il server MCP visto da fuori: è così che lo raggiunge `claude`, quindi è
     * l'unico modo onesto di verificarlo.
     *
     * @return array{key: string, name: string, ok: bool, detail: string}
     */
    private function mcp(): array
    {
        $url = (string) config('fanta.mcp_url');

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/json, text/event-stream'])
                ->post($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

            $tools = $response->json('result.tools');

            if (! is_array($tools) || $tools === []) {
                return $this->ko('mcp', 'Server MCP fanta-asta', 'nessun tool nella risposta di '.$url);
            }

            $declared = FantaAstaServer::declaredToolCount();

            // Un tool che non arriva all'altro capo non lo scopre nessuno
            // finché un run headless non fallisce a metà: meglio adesso.
            return count($tools) === $declared
                ? $this->ok('mcp', 'Server MCP fanta-asta', count($tools).' tool esposti')
                : $this->ko('mcp', 'Server MCP fanta-asta', sprintf(
                    '%d tool esposti ma %d dichiarati dal server: qualche registrazione non è arrivata (%s)',
                    count($tools),
                    $declared,
                    $url,
                ));
        } catch (Throwable $exception) {
            return $this->ko('mcp', 'Server MCP fanta-asta', $exception->getMessage()." (url: {$url})");
        }
    }

    /**
     * @return array{key: string, name: string, ok: bool, detail: string}
     */
    private function ok(string $key, string $name, string $detail): array
    {
        return ['key' => $key, 'name' => $name, 'ok' => true, 'detail' => $detail];
    }

    /**
     * @return array{key: string, name: string, ok: bool, detail: string}
     */
    private function ko(string $key, string $name, string $detail): array
    {
        return ['key' => $key, 'name' => $name, 'ok' => false, 'detail' => $detail];
    }
}

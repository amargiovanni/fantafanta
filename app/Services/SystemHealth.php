<?php

namespace App\Services;

use App\Mcp\Servers\FantaAstaServer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Stato dei servizi da cui dipende la pipeline AI.
 *
 * Il rischio operativo numero uno non è un bug: è che la sera dell'asta uno
 * fra `claude`, Redis, Horizon o Meilisearch sia semplicemente giù (design
 * §5). Questo servizio dà la risposta in un colpo solo, sia da riga di
 * comando sia in dashboard.
 *
 * `redis()` e `horizon()` sono deliberatamente due controlli distinti (Fase
 * 5, debito dichiarato): Redis raggiungibile non vuol dire che un worker
 * stia consumando le code. Con Redis su e Horizon giù, generare un piano o
 * registrare un acquisto scrive comunque la riga — il job resta in coda e
 * invecchia senza che nessuno se ne accorga finché non si guarda `/horizon`
 * a mano. `redis()` resta per compatibilità col messaggio "avvia Redis";
 * `horizon()` è il controllo che dice se qualcuno sta davvero lavorando le code.
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
            $this->horizon(),
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
     * Redis raggiungibile non basta: serve un master supervisor Horizon
     * davvero attivo a consumare le code, altrimenti un piano generato o un
     * acquisto registrato restano semplicemente in coda senza che nessuno se
     * ne accorga (spec Fase 5, §3 "Horizon giù").
     *
     * @return array{key: string, name: string, ok: bool, detail: string}
     */
    private function horizon(): array
    {
        try {
            $masters = app(MasterSupervisorRepository::class)->all();

            if ($masters === [] || $masters === null) {
                return $this->ko('horizon', 'Horizon (worker delle code)', 'nessun master supervisor attivo — prova: php artisan horizon');
            }

            $paused = collect($masters)->contains(fn ($master) => $master->status === 'paused');

            if ($paused) {
                return $this->ko('horizon', 'Horizon (worker delle code)', 'in pausa — riprendi con: php artisan horizon:continue');
            }

            return $this->ok('horizon', 'Horizon (worker delle code)', count($masters).' master supervisor attivo/i');
        } catch (Throwable $exception) {
            return $this->ko('horizon', 'Horizon (worker delle code)', $exception->getMessage());
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

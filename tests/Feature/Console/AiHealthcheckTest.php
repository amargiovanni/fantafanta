<?php

use App\Mcp\Servers\FantaAstaServer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * Il server MCP sano espone esattamente i tool che dichiara: l'healthcheck
 * confronta i due numeri, quindi la finta deve dichiararne altrettanti.
 *
 * @return array<int, array{name: string}>
 */
function toolEsposti(): array
{
    return array_fill(0, FantaAstaServer::declaredToolCount(), ['name' => 'un_tool']);
}

/**
 * Horizon non ha una faccia in `Illuminate\Support\Facades`: il modo pulito
 * di simularlo nei test è sostituire il repository che i master supervisor
 * usano per registrarsi, con l'esito che serve al caso.
 *
 * @param  array<int, object>  $masters
 */
function fakeHorizon(array $masters): void
{
    app()->instance(MasterSupervisorRepository::class, new class($masters) implements MasterSupervisorRepository
    {
        public function __construct(private array $masters) {}

        public function names()
        {
            return array_map(fn ($m) => $m->name, $this->masters);
        }

        public function all()
        {
            return $this->masters;
        }

        public function find($name)
        {
            return null;
        }

        public function get(array $names)
        {
            return $this->masters;
        }

        public function update($master): void {}

        public function forget($name): void {}

        public function flushExpired(): void {}
    });
}

function fakeServiziSani(): void
{
    Process::fake(['*claude*' => Process::result(output: '2.1.220 (Claude Code)')]);

    Http::fake([
        '*/health' => Http::response(['status' => 'available']),
        '*/mcp' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => toolEsposti()]]),
    ]);

    fakeHorizon([(object) ['name' => 'horizon-test', 'status' => 'running']]);
}

it('riporta OK e esce con codice 0 quando tutto risponde', function () {
    fakeServiziSani();

    $this->artisan('ai:healthcheck')
        // Una sola attesa per riga di output: ogni riga viene consumata dalla
        // prima attesa che la soddisfa.
        ->expectsOutputToContain('Claude Code CLI')
        ->expectsOutputToContain('Horizon')
        ->expectsOutputToContain('Meilisearch')
        ->expectsOutputToContain('Server MCP')
        ->expectsOutputToContain('Tutti i servizi rispondono')
        ->assertExitCode(0);
});

it('esce con codice 1 se Horizon non ha nessun master supervisor attivo', function () {
    fakeServiziSani();
    fakeHorizon([]);

    $this->artisan('ai:healthcheck')
        ->expectsOutputToContain('nessun master supervisor attivo')
        ->expectsOutputToContain('Almeno un servizio non risponde')
        ->assertExitCode(1);
});

it('esce con codice 1 se Horizon è in pausa', function () {
    fakeServiziSani();
    fakeHorizon([(object) ['name' => 'horizon-test', 'status' => 'paused']]);

    $this->artisan('ai:healthcheck')
        ->expectsOutputToContain('in pausa')
        ->assertExitCode(1);
});

it('esce con codice 1 se il binario claude non risponde', function () {
    Process::fake(['*claude*' => Process::result(output: '', errorOutput: 'command not found', exitCode: 127)]);
    Http::fake([
        '*/health' => Http::response(['status' => 'available']),
        '*/mcp' => Http::response(['result' => ['tools' => toolEsposti()]]),
    ]);

    $this->artisan('ai:healthcheck')
        ->expectsOutputToContain('command not found')
        ->expectsOutputToContain('Almeno un servizio non risponde')
        ->assertExitCode(1);
});

it('esce con codice 1 se Meilisearch è giù e suggerisce come riavviarlo', function () {
    Process::fake(['*claude*' => Process::result(output: '2.1.220')]);
    Http::fake([
        '*/health' => fn () => throw new ConnectionException('Connection refused'),
        '*/mcp' => Http::response(['result' => ['tools' => toolEsposti()]]),
    ]);

    // Un servizio giù non deve far esplodere il comando: deve farlo riportare.
    $this->artisan('ai:healthcheck')
        ->expectsOutputToContain('brew services start meilisearch')
        ->assertExitCode(1);
});

it('esce con codice 1 se il server MCP non espone tool', function () {
    Process::fake(['*claude*' => Process::result(output: '2.1.220')]);
    Http::fake([
        '*/health' => Http::response(['status' => 'available']),
        '*/mcp' => Http::response(['error' => ['message' => 'Server Error']], 500),
    ]);

    $this->artisan('ai:healthcheck')
        ->expectsOutputToContain('nessun tool nella risposta')
        ->assertExitCode(1);
});

it('esce con codice 1 se il server espone meno tool di quanti ne dichiara', function () {
    Process::fake(['*claude*' => Process::result(output: '2.1.220')]);
    Http::fake([
        '*/health' => Http::response(['status' => 'available']),
        '*/mcp' => Http::response(['result' => ['tools' => [['name' => 'search_player']]]]),
    ]);

    // Una registrazione saltata non si vede finché un run headless non
    // fallisce a metà: l'healthcheck deve accorgersene prima.
    $this->artisan('ai:healthcheck')
        ->expectsOutputToContain('qualche registrazione non è arrivata')
        ->assertExitCode(1);
});

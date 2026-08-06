<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

function fakeServiziSani(): void
{
    Process::fake(['*claude*' => Process::result(output: '2.1.220 (Claude Code)')]);

    Http::fake([
        '*/health' => Http::response(['status' => 'available']),
        '*/mcp' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => [
            ['name' => 'search_player'], ['name' => 'save_signals'],
        ]]]),
    ]);
}

it('riporta OK e esce con codice 0 quando tutto risponde', function () {
    fakeServiziSani();

    $this->artisan('ai:healthcheck')
        // Una sola attesa per riga di output: ogni riga viene consumata dalla
        // prima attesa che la soddisfa.
        ->expectsOutputToContain('Claude Code CLI')
        ->expectsOutputToContain('Meilisearch')
        ->expectsOutputToContain('Server MCP')
        ->expectsOutputToContain('Tutti i servizi rispondono')
        ->assertExitCode(0);
});

it('esce con codice 1 se il binario claude non risponde', function () {
    Process::fake(['*claude*' => Process::result(output: '', errorOutput: 'command not found', exitCode: 127)]);
    Http::fake([
        '*/health' => Http::response(['status' => 'available']),
        '*/mcp' => Http::response(['result' => ['tools' => [['name' => 'search_player']]]]),
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
        '*/mcp' => Http::response(['result' => ['tools' => [['name' => 'search_player']]]]),
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

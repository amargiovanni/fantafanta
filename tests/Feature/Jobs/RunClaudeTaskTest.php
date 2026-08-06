<?php

use App\Enums\AiRunStatus;
use App\Jobs\RunClaudeTask;
use App\Models\AiRun;
use App\Models\Source;
use App\Support\PromptComposer;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Support\Facades\Process;

/**
 * In questi test `claude` non viene MAI eseguito davvero: il processo è finto.
 * Una suite che spende soldi di sottoscrizione a ogni giro non è una suite.
 */
beforeEach(function () {
    Process::preventStrayProcesses();

    $this->source = Source::factory()->create([
        'title' => 'Lautaro salta due giornate',
        'raw_content' => 'Lesione al flessore per Lautaro Martinez.',
    ]);

    $this->variables = [
        'today' => '2026-08-06',
        'source_id' => $this->source->id,
        'source_type' => 'note',
        'source_title' => $this->source->title,
        'source_url' => '—',
        'source_content' => $this->source->raw_content,
    ];
});

function claudeOutput(array $overrides = []): string
{
    return json_encode(array_merge([
        'type' => 'result',
        'subtype' => 'success',
        'is_error' => false,
        'duration_ms' => 12000,
        'num_turns' => 6,
        'result' => '{"source_id":1,"signals_written":1,"needs_review":0}',
    ], $overrides));
}

function runTask(array $variables, array $context = []): void
{
    (new RunClaudeTask('extract-signals', 'extract-signals.md', $context, $variables))->handle();
}

it('registra in ai_runs una esecuzione riuscita con hash, durata e output', function () {
    Process::fake(['*claude*' => Process::result(output: claudeOutput())]);

    runTask($this->variables, ['source_id' => $this->source->id]);

    $run = AiRun::query()->sole();

    expect($run->task)->toBe('extract-signals')
        ->and($run->prompt_file)->toBe('extract-signals.md')
        ->and($run->status)->toBe(AiRunStatus::Succeeded)
        ->and($run->prompt_hash)->toHaveLength(64)
        ->and($run->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($run->output_raw)->toContain('signals_written')
        ->and($run->context)->toBe(['source_id' => $this->source->id])
        ->and($run->error)->toBeNull();
});

it('costruisce il comando previsto dal contratto del briefing', function () {
    Process::fake(['*claude*' => Process::result(output: claudeOutput())]);

    runTask($this->variables);

    Process::assertRan(function ($process) {
        $command = $process->command;

        expect($command[0])->toBe(config('fanta.claude.binary'))
            ->and($command[1])->toBe('-p')
            // Il testo della fonte finisce davvero nel prompt inviato.
            ->and($command[2])->toContain('Lesione al flessore per Lautaro Martinez.')
            ->and($command[2])->not->toContain('{{');

        expect(array_slice($command, 3))->toBe([
            '--output-format', 'json',
            '--max-turns', '30',
            '--allowedTools', 'mcp__fanta-asta__*',
            '--mcp-config', '.mcp.json',
        ]);

        return true;
    });
});

it('segna il run come fallito quando claude esce con errore', function () {
    Process::fake(['*claude*' => Process::result(output: '', errorOutput: 'Invalid API key · Please run /login', exitCode: 1)]);

    expect(fn () => runTask($this->variables))->toThrow(RuntimeException::class);

    expect(AiRun::query()->sole())
        ->status->toBe(AiRunStatus::Failed)
        ->error->toContain('Please run /login');
});

it('segna il run come fallito quando l\'output non è JSON', function () {
    Process::fake(['*claude*' => Process::result(output: 'Ciao! Ho finito di lavorare.')]);

    expect(fn () => runTask($this->variables))->toThrow(RuntimeException::class);

    expect(AiRun::query()->sole())
        ->status->toBe(AiRunStatus::Failed)
        ->error->toContain('Output non interpretabile come JSON')
        ->output_raw->toContain('Ciao! Ho finito di lavorare.');
});

it('segna il run come fallito quando claude riporta un errore proprio', function () {
    Process::fake(['*claude*' => Process::result(output: claudeOutput([
        'is_error' => true,
        'result' => 'Raggiunto il limite di turni senza completare il compito.',
    ]))]);

    expect(fn () => runTask($this->variables))->toThrow(RuntimeException::class);

    expect(AiRun::query()->sole())
        ->status->toBe(AiRunStatus::Failed)
        ->error->toBe('Raggiunto il limite di turni senza completare il compito.');
});

it('mette l\'errore del tentativo precedente in appendice al prompt del retry', function () {
    Process::fake(['*claude*' => Process::result(output: claudeOutput())]);

    $context = ['source_id' => $this->source->id];

    AiRun::factory()->create([
        'task' => 'extract-signals',
        'context' => $context,
        'status' => AiRunStatus::Failed,
        'error' => 'save_signals ha rifiutato il batch: impact 5 fuori range.',
    ]);

    $job = new RunClaudeTask('extract-signals', 'extract-signals.md', $context, $this->variables);

    // Simula il secondo tentativo della coda.
    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('attempts')->andReturn(2);
    $job->setJob($queueJob);

    $job->handle();

    Process::assertRan(function ($process) {
        expect($process->command[2])
            ->toContain('Tentativo precedente fallito')
            ->toContain('impact 5 fuori range');

        return true;
    });
});

it('non esegue nulla se il prompt ha segnaposto senza valore', function () {
    Process::fake();

    expect(fn () => runTask(['today' => '2026-08-06']))
        ->toThrow(RuntimeException::class, 'segnaposto');

    expect(AiRun::query()->count())->toBe(0);
    Process::assertNothingRan();
});

it('rifiuta un prompt inesistente', function () {
    expect(fn () => PromptComposer::compose('non-esiste.md'))
        ->toThrow(RuntimeException::class, 'Prompt non trovato');
});

it('gira sulla coda ai', function () {
    expect((new RunClaudeTask('extract-signals', 'extract-signals.md'))->queue)->toBe('ai');
});

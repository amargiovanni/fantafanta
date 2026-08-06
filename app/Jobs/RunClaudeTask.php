<?php

namespace App\Jobs;

use App\Enums\AiRunStatus;
use App\Enums\SourceStatus;
use App\Models\AiRun;
use App\Models\Source;
use App\Support\PromptComposer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Esegue un task di Claude Code in modalità headless.
 *
 * È l'unico punto dell'applicazione che invoca l'AI. Il contratto (briefing §3):
 *
 *   claude -p "<prompt>" --output-format json --max-turns 30 \
 *          --allowedTools "mcp__fanta-asta__*" --mcp-config .mcp.json
 *
 * Claude scrive i risultati passando dai tool MCP, non facendosi interpretare
 * l'output da Laravel: il JSON che restituisce serve da audit e da fallback.
 * Ogni esecuzione, riuscita o fallita, lascia una riga in `ai_runs`.
 *
 * Al secondo tentativo il prompt riporta in appendice l'errore del primo, così
 * che Claude sappia cosa correggere invece di ripetere lo stesso errore.
 */
class RunClaudeTask implements ShouldQueue
{
    use Queueable;

    /** Un solo ritentativo: ogni esecuzione costa tempo e sottoscrizione reale. */
    public int $tries = 2;

    /** Deve superare il timeout del processo, altrimenti il worker lo ucciderebbe prima. */
    public int $timeout = 360;

    /**
     * @param  array<string, mixed>  $context  Contesto di dominio del run (es. ['source_id' => 12]).
     * @param  array<string, string|int|null>  $variables  Valori dei segnaposto del prompt.
     */
    public function __construct(
        public readonly string $task,
        public readonly string $promptFile,
        public readonly array $context = [],
        public readonly array $variables = [],
        string $queue = 'ai',
    ) {
        $this->onQueue($queue);
    }

    public function handle(): void
    {
        $prompt = PromptComposer::compose($this->promptFile, $this->variables);

        if ($previousError = $this->previousError()) {
            $prompt .= $this->retryAppendix($previousError);
        }

        $run = AiRun::query()->create([
            'task' => $this->task,
            'prompt_file' => $this->promptFile,
            'prompt_hash' => hash('sha256', $prompt),
            'status' => AiRunStatus::Running,
            'context' => $this->context,
        ]);

        $startedAt = microtime(true);

        try {
            $result = Process::path(base_path())
                ->timeout((int) config('fanta.claude.timeout'))
                ->run($this->command($prompt));
        } catch (Throwable $exception) {
            $this->fail($run, $startedAt, null, $exception->getMessage());

            throw $exception;
        }

        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        $output = $result->output();

        if (! $result->successful()) {
            $this->fail($run, $startedAt, $output, trim($result->errorOutput()) ?: "claude è uscito con codice {$result->exitCode()}.");

            throw new \RuntimeException("Esecuzione di claude fallita per il task {$this->task}: ".$run->fresh()->error);
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            $this->fail($run, $startedAt, $output, 'Output non interpretabile come JSON: atteso il formato di --output-format json.');

            throw new \RuntimeException("Output di claude non valido per il task {$this->task}.");
        }

        if (($decoded['is_error'] ?? false) === true) {
            $this->fail($run, $startedAt, $output, (string) ($decoded['result'] ?? 'Claude ha segnalato un errore senza dettagli.'));

            throw new \RuntimeException("Claude ha riportato un errore per il task {$this->task}.");
        }

        $run->update([
            'status' => AiRunStatus::Succeeded,
            'duration_ms' => $duration,
            'output_raw' => $output,
        ]);
    }

    /**
     * Esauriti i tentativi, l'errore deve essere visibile dove l'utente guarda.
     *
     * Quando il run riguardava una source, la si marca come fallita: senza
     * questo, una fonte resterebbe per sempre "in lavorazione" e il problema
     * sarebbe leggibile solo in `ai_runs`.
     */
    public function failed(?Throwable $exception): void
    {
        $sourceId = $this->context['source_id'] ?? null;

        if ($sourceId === null) {
            return;
        }

        $lastError = AiRun::query()
            ->where('task', $this->task)
            ->where('context', json_encode($this->context))
            ->latest('id')
            ->value('error');

        Source::query()->whereKey($sourceId)->update([
            'status' => SourceStatus::Failed,
            'error' => $lastError ?? $exception?->getMessage() ?? 'Esecuzione AI fallita.',
        ]);
    }

    /**
     * Comando completo, in forma di array: nessuna interpolazione di shell,
     * quindi nessun rischio che il testo di un articolo venga interpretato.
     *
     * @return array<int, string>
     */
    public function command(string $prompt): array
    {
        return [
            (string) config('fanta.claude.binary'),
            '-p', $prompt,
            '--output-format', 'json',
            '--max-turns', (string) config('fanta.claude.max_turns'),
            '--allowedTools', (string) config('fanta.claude.allowed_tools'),
            '--mcp-config', (string) config('fanta.claude.mcp_config'),
        ];
    }

    /**
     * Errore dell'ultimo tentativo fallito per lo stesso task e contesto.
     *
     * La memoria fra un tentativo e l'altro è la tabella di audit: le proprietà
     * del job non sopravvivono al retry, perché la coda ripubblica il payload
     * originale.
     */
    private function previousError(): ?string
    {
        if ($this->attempts() <= 1) {
            return null;
        }

        return AiRun::query()
            ->where('task', $this->task)
            ->where('context', json_encode($this->context))
            ->where('status', AiRunStatus::Failed)
            ->latest('id')
            ->value('error');
    }

    private function retryAppendix(string $error): string
    {
        return <<<TXT


        ---

        ## Tentativo precedente fallito

        L'esecuzione precedente di questo stesso task è terminata con questo errore:

        ```
        {$error}
        ```

        Tienine conto: correggi ciò che ha causato l'errore invece di ripetere gli stessi passi.
        TXT;
    }

    private function fail(AiRun $run, float $startedAt, ?string $output, string $error): void
    {
        $run->update([
            'status' => AiRunStatus::Failed,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'output_raw' => $output,
            'error' => $error,
        ]);
    }
}

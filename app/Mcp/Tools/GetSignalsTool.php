<?php

namespace App\Mcp\Tools;

use App\Enums\SignalType;
use App\Models\Signal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[Description(<<<'TXT'
Elenca i segnali già raccolti, filtrabili per giocatore, tipo e data.

Serve a non riscrivere ciò che esiste e a individuare i segnali che una nuova
notizia contraddice: quelli vanno passati in `supersedes` a save_signals, non
lasciati attivi accanto al nuovo. Di default mostra solo i segnali attivi.
Non scrive nulla.
TXT)]
class GetSignalsTool extends Tool
{
    protected string $name = 'get_signals';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'player_id' => $schema->integer()
                ->description('Filtra sui segnali di un singolo giocatore.'),
            'type' => $schema->string()
                ->description('Filtra per tipo di segnale.')
                ->enum(SignalType::values()),
            'since' => $schema->string()
                ->description('Solo segnali con event_date uguale o successiva a questa data (YYYY-MM-DD).')
                ->format('date'),
            'only_active' => $schema->boolean()
                ->description('Se true (default) esclude i segnali già superati.'),
            'needs_review' => $schema->boolean()
                ->description('Se true, solo i segnali in attesa di revisione manuale (nome non risolto).'),
            'limit' => $schema->integer()
                ->description('Numero massimo di segnali (default 50).')
                ->min(1)
                ->max(200),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'player_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'in:'.implode(',', SignalType::values())],
            'since' => ['nullable', 'date'],
            'only_active' => ['nullable', 'boolean'],
            'needs_review' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Signal::query()->with(['source', 'player']);

        if (($validated['only_active'] ?? true) === true) {
            $query->active();
        }

        if (isset($validated['player_id'])) {
            $query->where('player_id', $validated['player_id']);
        }

        if (isset($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (isset($validated['since'])) {
            $query->whereDate('event_date', '>=', $validated['since']);
        }

        if (isset($validated['needs_review'])) {
            $query->where('needs_review', $validated['needs_review']);
        }

        $signals = $query->orderByDesc('id')->limit($validated['limit'] ?? 50)->get();

        return Response::structured([
            'count' => $signals->count(),
            'signals' => $signals->map(fn (Signal $signal) => [
                'id' => $signal->id,
                'player_id' => $signal->player_id,
                'player_name' => $signal->player?->name,
                'raw_name' => $signal->raw_name,
                'type' => $signal->type->value,
                'impact' => $signal->impact,
                'confidence' => (float) $signal->confidence,
                'event_date' => $signal->event_date?->toDateString(),
                'payload' => $signal->payload,
                'needs_review' => $signal->needs_review,
                'superseded_by' => $signal->superseded_by,
                'source' => [
                    'id' => $signal->source->id,
                    'title' => $signal->source->title,
                    'url' => $signal->source->url,
                ],
            ])->all(),
        ]);
    }
}

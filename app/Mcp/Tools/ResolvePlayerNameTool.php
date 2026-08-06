<?php

namespace App\Mcp\Tools;

use App\Services\PlayerResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description(<<<'TXT'
Risolve un nome grezzo trovato in un articolo nel giocatore canonico del
listone.

Tre esiti possibili:
- "matched": il nome è attribuito con certezza. Il server registra la forma
  usata come alias, così la stessa scrittura non richiederà più una ricerca.
  Usa il player_id restituito.
- "ambiguous": ci sono candidati ma nessuno abbastanza sicuro. Il server NON
  ha scritto nulla. Non scegliere tu: crea il segnale con player_id null,
  needs_review=true e raw_name, così lo assegna una persona.
- "not_found": nessun candidato. Stesso trattamento dell'ambiguo.

Attribuire un segnale al giocatore sbagliato è l'errore più costoso di questa
applicazione: nel dubbio, needs_review.
TXT)]
class ResolvePlayerNameTool extends Tool
{
    protected string $name = 'resolve_player_name';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Il nome esattamente come compare nella fonte.')
                ->required(),
            'context' => $schema->string()
                ->description('Facoltativo: squadra o frase in cui compare il nome, utile a distinguere omonimi.'),
        ];
    }

    public function handle(Request $request, PlayerResolver $resolver): Response|ResponseFactory
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'context' => ['nullable', 'string', 'max:1000'],
        ]);

        $outcome = $resolver->resolve($validated['name']);
        $player = $outcome['player'];

        return Response::structured([
            'raw_name' => $validated['name'],
            'status' => $outcome['status'],
            'similarity' => $outcome['similarity'],
            'alias_created' => $outcome['alias_created'],
            'player' => $player === null ? null : [
                'id' => $player->id,
                'name' => $player->name,
                'role' => $player->role->value,
                'real_team' => $player->real_team,
            ],
            'candidates' => $outcome['candidates'],
            'guidance' => match ($outcome['status']) {
                'matched' => 'Nome risolto: usa questo player_id nel segnale.',
                'ambiguous' => 'Nome ambiguo: crea il segnale con player_id null, needs_review=true e raw_name.',
                default => 'Nome non trovato nel listone: crea il segnale con player_id null, needs_review=true e raw_name.',
            },
        ]);
    }
}

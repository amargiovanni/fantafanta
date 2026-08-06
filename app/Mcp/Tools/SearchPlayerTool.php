<?php

namespace App\Mcp\Tools;

use App\Services\PlayerResolver;
use App\Support\NameNormalizer;
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
Cerca un giocatore del listone per nome, in modo tollerante agli errori e alle
forme abbreviate: "lautaro", "Martinez L." e "martinez lautaro" trovano lo
stesso giocatore. Non scrive nulla.

Ritorna i candidati ordinati per somiglianza (0–1) con id, ruolo e squadra.
Una somiglianza sotto 0.85, o due candidati troppo vicini fra loro, significa
che il nome NON è identificato con certezza: non attribuire un segnale a
occhio, usa resolve_player_name o lascia il segnale in revisione.
TXT)]
class SearchPlayerTool extends Tool
{
    protected string $name = 'search_player';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Nome del giocatore così come appare nel testo, anche parziale o abbreviato.')
                ->required(),
            'limit' => $schema->integer()
                ->description('Numero massimo di candidati da restituire (default 10).')
                ->min(1)
                ->max(25),
        ];
    }

    public function handle(Request $request, PlayerResolver $resolver): Response|ResponseFactory
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);

        $normalized = NameNormalizer::normalize($validated['name']);

        if ($normalized === '') {
            return Response::error('Il nome da cercare è vuoto dopo la normalizzazione: passa il nome come appare nel testo.');
        }

        $candidates = $resolver->candidates($normalized, $validated['limit'] ?? 10);

        return Response::structured([
            'query' => $validated['name'],
            'normalized_query' => $normalized,
            'count' => count($candidates),
            'candidates' => $candidates,
            'auto_match_threshold' => PlayerResolver::AUTO_MATCH_THRESHOLD,
        ]);
    }
}

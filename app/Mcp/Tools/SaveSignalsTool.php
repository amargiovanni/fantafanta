<?php

namespace App\Mcp\Tools;

use App\Enums\SignalType;
use App\Exceptions\SignalValidationException;
use App\Services\SignalWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description(<<<'TXT'
Salva in un colpo solo i segnali estratti da una fonte.

Ogni segnale richiede: type (dall'enum), source_id, confidence 0–1, impact
intero -2..+2, e o un player_id esistente, oppure player_id null insieme a
needs_review=true e raw_name (il nome così come compare nel testo).

Comportamento del server, di cui puoi fidarti senza replicarlo:
- il batch è transazionale: se anche un solo segnale è invalido non viene
  scritto NIENTE e ricevi l'elenco puntuale degli errori da correggere;
- dedup automatica: se per quel giocatore esiste già un segnale attivo dello
  stesso tipo da un'altra fonte, il server ne alza la confidence invece di
  duplicarlo (azione "corroborated"); se la fonte è la stessa non cambia nulla
  (azione "duplicate_ignored"), quindi richiamare il tool non fa danni;
- supersede: passa in `supersedes` gli id dei segnali che questa notizia
  smentisce (li trovi con get_signals). Un "rientro" che supera un
  "infortunio" dello stesso giocatore viene comunque marcato dal server anche
  se non lo indichi.

Scala di impact: -2 il giocatore diventa molto meno appetibile (infortunio
lungo), -1 peggiora, 0 neutro/informativo, +1 migliora, +2 molto più
appetibile (diventa rigorista, titolare inamovibile).
TXT)]
class SaveSignalsTool extends Tool
{
    protected string $name = 'save_signals';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'signals' => $schema->array()
                ->description('I segnali da salvare. Almeno uno.')
                ->required()
                ->min(1)
                ->items($schema->object([
                    'type' => $schema->string()
                        ->description('Tipo di segnale.')
                        ->enum(SignalType::values())
                        ->required(),
                    'source_id' => $schema->integer()
                        ->description('Id della fonte da cui è tratto il segnale.')
                        ->required(),
                    'confidence' => $schema->number()
                        ->description('Quanto la fonte è affidabile su questa informazione, da 0 a 1.')
                        ->min(0)
                        ->max(1)
                        ->required(),
                    'impact' => $schema->integer()
                        ->description('Effetto sull\'appetibilità in asta, da -2 a +2.')
                        ->min(-2)
                        ->max(2)
                        ->required(),
                    'player_id' => $schema->integer()
                        ->description('Id del giocatore, se risolto con certezza. Altrimenti ometti e usa raw_name + needs_review.'),
                    'raw_name' => $schema->string()
                        ->description('Nome come appare nel testo. Obbligatorio quando player_id manca.'),
                    'needs_review' => $schema->boolean()
                        ->description('true quando il nome non è stato risolto e serve una revisione manuale.'),
                    'event_date' => $schema->string()
                        ->description('Data dell\'evento riportato (YYYY-MM-DD), non la data dell\'articolo se diversa.')
                        ->format('date'),
                    'payload' => $schema->object()
                        ->description('Dettagli tipizzati: durata stimata dell\'infortunio, testo della fonte, ballottaggio con chi, ecc.'),
                    'supersedes' => $schema->array()
                        ->description('Id dei segnali che questo rende obsoleti.')
                        ->items($schema->integer()),
                ])),
        ];
    }

    public function handle(Request $request, SignalWriter $writer): Response|ResponseFactory
    {
        $validated = $request->validate([
            'signals' => ['required', 'array', 'min:1'],
        ]);

        try {
            $results = $writer->saveBatch($validated['signals']);
        } catch (SignalValidationException $exception) {
            // Errore dettagliato e correggibile: è il contratto del briefing §6.
            return Response::error(
                "Nessun segnale è stato salvato. Correggi questi errori e richiama save_signals:\n- "
                .implode("\n- ", $exception->errors()),
            );
        }

        $counts = array_count_values(array_column($results, 'action'));

        return Response::structured([
            'saved' => count($results),
            'created' => $counts['created'] ?? 0,
            'corroborated' => $counts['corroborated'] ?? 0,
            'duplicate_ignored' => $counts['duplicate_ignored'] ?? 0,
            'needs_review' => count(array_filter($results, fn (array $r) => $r['player_id'] === null)),
            'results' => $results,
        ]);
    }
}

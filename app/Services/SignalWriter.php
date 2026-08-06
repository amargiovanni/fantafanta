<?php

namespace App\Services;

use App\Enums\SignalType;
use App\Exceptions\SignalValidationException;
use App\Models\Player;
use App\Models\Signal;
use App\Models\Source;
use App\Support\NameNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Unico punto di scrittura dei segnali.
 *
 * Regge tre responsabilità che non possono stare nel prompt, perché un prompt
 * non è un vincolo ma un auspicio (briefing §6):
 *
 *  1. VALIDAZIONE — nessun segnale entra con tipo fuori enum, confidence fuori
 *     scala, impatto fuori range, o senza un giocatore né una traccia del nome
 *     non risolto. L'intero batch è transazionale: o entra tutto, o niente.
 *  2. DEDUP — la stessa notizia ripresa da due testate non crea due segnali:
 *     corrobora quello esistente alzandone la confidence, con tetto a 1.0.
 *     Se la fonte è la stessa (tipico di un retry del job) non cambia nulla:
 *     la scrittura è idempotente.
 *  3. SUPERSEDE — un segnale che contraddice il passato lo marca come superato
 *     invece di affiancarglisi. Oltre alla lista esplicita passata da Claude,
 *     c'è una rete di sicurezza deterministica: un `rientro` supera sempre un
 *     `infortunio` precedente dello stesso giocatore, che Claude se ne ricordi
 *     o meno.
 */
class SignalWriter
{
    /**
     * Salva un batch di segnali.
     *
     * @param  array<int, array<string, mixed>>  $signals
     * @return array<int, array{index: int, action: string, signal_id: int, player_id: int|null, type: string, confidence: float, superseded: array<int, int>}>
     *
     * @throws SignalValidationException
     */
    public function saveBatch(array $signals, bool $autoSupersede = true): array
    {
        $errors = $this->validate($signals);

        if ($errors !== []) {
            throw new SignalValidationException($errors);
        }

        return DB::transaction(function () use ($signals, $autoSupersede) {
            $results = [];

            foreach (array_values($signals) as $index => $input) {
                $results[] = $this->saveOne($index, $input, $autoSupersede);
            }

            return $results;
        });
    }

    /**
     * Validazione completa del batch, senza scrivere nulla.
     *
     * @param  array<int, array<string, mixed>>  $signals
     * @return array<int, string>
     */
    public function validate(array $signals): array
    {
        $errors = [];

        if ($signals === []) {
            return ['Il batch è vuoto: serve almeno un segnale.'];
        }

        foreach (array_values($signals) as $index => $signal) {
            $label = 'segnale #'.($index + 1);

            if (! is_array($signal)) {
                $errors[] = "{$label}: struttura non valida, atteso un oggetto.";

                continue;
            }

            $errors = array_merge($errors, $this->validateOne($label, $signal));
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $signal
     * @return array<int, string>
     */
    private function validateOne(string $label, array $signal): array
    {
        $errors = [];

        $type = $signal['type'] ?? null;
        if (! is_string($type) || SignalType::tryFrom($type) === null) {
            $errors[] = sprintf(
                '%s: tipo "%s" non valido. Ammessi: %s.',
                $label,
                is_scalar($type) ? (string) $type : gettype($type),
                implode(', ', SignalType::values()),
            );
        }

        $confidence = $signal['confidence'] ?? null;
        if (! is_numeric($confidence) || $confidence < 0 || $confidence > 1) {
            $errors[] = sprintf(
                '%s: confidence "%s" fuori scala, deve essere un numero fra 0 e 1.',
                $label,
                is_scalar($confidence) ? (string) $confidence : gettype($confidence),
            );
        }

        $impact = $signal['impact'] ?? null;
        if (! is_int($impact) && ! (is_string($impact) && ctype_digit(ltrim((string) $impact, '-')))) {
            $errors[] = sprintf('%s: impact deve essere un intero fra -2 e +2.', $label);
        } elseif ((int) $impact < -2 || (int) $impact > 2) {
            $errors[] = sprintf('%s: impact %d fuori range, ammesso da -2 a +2.', $label, (int) $impact);
        }

        $sourceId = $signal['source_id'] ?? null;
        if (! is_numeric($sourceId) || ! Source::query()->whereKey($sourceId)->exists()) {
            $errors[] = sprintf('%s: source_id %s inesistente.', $label, is_scalar($sourceId) ? (string) $sourceId : 'assente');
        }

        $playerId = $signal['player_id'] ?? null;
        $rawName = $signal['raw_name'] ?? null;
        $needsReview = (bool) ($signal['needs_review'] ?? false);

        if ($playerId !== null && $playerId !== '') {
            if (! is_numeric($playerId) || ! Player::query()->whereKey($playerId)->exists()) {
                $errors[] = sprintf('%s: player_id %s inesistente nel listone.', $label, (string) $playerId);
            }
        } else {
            // Il caso "nome non risolto" è legittimo, ma non può essere silenzioso:
            // deve dichiararsi tale e portare il nome così com'era nell'articolo.
            if (! $needsReview || ! is_string($rawName) || trim($rawName) === '') {
                $errors[] = sprintf(
                    '%s: senza player_id servono needs_review=true e raw_name valorizzato con il nome trovato nel testo. Un segnale orfano e silenzioso non è ammesso.',
                    $label,
                );
            }
        }

        if (isset($signal['event_date']) && $signal['event_date'] !== null && strtotime((string) $signal['event_date']) === false) {
            $errors[] = sprintf('%s: event_date "%s" non è una data valida (formato atteso YYYY-MM-DD).', $label, (string) $signal['event_date']);
        }

        if (isset($signal['payload']) && $signal['payload'] !== null && ! is_array($signal['payload'])) {
            $errors[] = sprintf('%s: payload deve essere un oggetto JSON.', $label);
        }

        $errors = array_merge($errors, $this->validateSupersedes($label, $signal, $playerId));

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $signal
     * @return array<int, string>
     */
    private function validateSupersedes(string $label, array $signal, mixed $playerId): array
    {
        $supersedes = $signal['supersedes'] ?? [];

        if ($supersedes === [] || $supersedes === null) {
            return [];
        }

        if (! is_array($supersedes)) {
            return [sprintf('%s: supersedes deve essere un array di id di segnali.', $label)];
        }

        $errors = [];

        if ($playerId === null || $playerId === '') {
            return [sprintf('%s: un segnale senza player_id non può marcare come superato nessun segnale esistente.', $label)];
        }

        foreach ($supersedes as $targetId) {
            $target = Signal::query()->find($targetId);

            if ($target === null) {
                $errors[] = sprintf('%s: il segnale da superare #%s non esiste.', $label, (string) $targetId);

                continue;
            }

            if ((int) $target->player_id !== (int) $playerId) {
                $errors[] = sprintf(
                    '%s: il segnale #%s riguarda un altro giocatore (player_id %s): non può essere superato da questo.',
                    $label,
                    (string) $targetId,
                    (string) $target->player_id,
                );
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{index: int, action: string, signal_id: int, player_id: int|null, type: string, confidence: float, superseded: array<int, int>}
     */
    private function saveOne(int $index, array $input, bool $autoSupersede): array
    {
        $type = SignalType::from((string) $input['type']);
        $playerId = isset($input['player_id']) && $input['player_id'] !== '' ? (int) $input['player_id'] : null;
        $rawName = isset($input['raw_name']) ? trim((string) $input['raw_name']) : null;
        $confidence = round((float) $input['confidence'], 2);

        $existing = $this->findActiveTwin($type, $playerId, $rawName);

        if ($existing !== null) {
            $action = $this->corroborate($existing, $confidence, (int) $input['source_id']);
            $signal = $existing;
        } else {
            $signal = Signal::query()->create([
                'player_id' => $playerId,
                'type' => $type,
                'payload' => $input['payload'] ?? null,
                'confidence' => $confidence,
                'impact' => (int) $input['impact'],
                'source_id' => (int) $input['source_id'],
                'event_date' => $input['event_date'] ?? null,
                'needs_review' => $playerId === null ? true : (bool) ($input['needs_review'] ?? false),
                'raw_name' => $rawName,
            ]);
            $action = 'created';
        }

        $superseded = $this->applySupersedes($signal, $input['supersedes'] ?? [], $autoSupersede);

        return [
            'index' => $index,
            'action' => $action,
            'signal_id' => $signal->id,
            'player_id' => $signal->player_id,
            'type' => $signal->type->value,
            'confidence' => (float) $signal->confidence,
            'superseded' => $superseded,
        ];
    }

    /**
     * Segnale attivo equivalente già presente: stesso giocatore (o stesso nome
     * grezzo, quando il giocatore non è risolto) e stesso tipo.
     */
    private function findActiveTwin(SignalType $type, ?int $playerId, ?string $rawName): ?Signal
    {
        $query = Signal::query()->active()->where('type', $type->value);

        if ($playerId !== null) {
            $query->where('player_id', $playerId);
        } elseif ($rawName !== null) {
            $normalized = NameNormalizer::normalize($rawName);
            $query->whereNull('player_id')
                ->whereIn('id', Signal::query()
                    ->whereNull('player_id')
                    ->whereNotNull('raw_name')
                    ->get(['id', 'raw_name'])
                    ->filter(fn (Signal $s) => NameNormalizer::normalize((string) $s->raw_name) === $normalized)
                    ->pluck('id')
                    ->all());
        } else {
            return null;
        }

        return $query->latest('id')->first();
    }

    /**
     * Corrobora un segnale esistente con una nuova fonte.
     *
     * Formula "noisy-or" dimezzata: una conferma indipendente alza la
     * confidence in proporzione a quanto margine resta verso 1.0, quindi non
     * la satura mai e pesa di più quando la nuova fonte è a sua volta sicura.
     * Stessa fonte = nessun effetto: il job può ritentare senza gonfiare nulla.
     */
    private function corroborate(Signal $existing, float $newConfidence, int $sourceId): string
    {
        if ((int) $existing->source_id === $sourceId) {
            return 'duplicate_ignored';
        }

        $current = (float) $existing->confidence;
        $combined = min(1.0, round($current + (1 - $current) * $newConfidence * 0.5, 2));

        $existing->update(['confidence' => $combined]);

        return 'corroborated';
    }

    /**
     * @param  array<int, mixed>|null  $explicit
     * @return array<int, int>
     */
    private function applySupersedes(Signal $signal, ?array $explicit, bool $autoSupersede): array
    {
        if ($signal->player_id === null) {
            return [];
        }

        $targets = collect($explicit ?? [])->map(fn ($id) => (int) $id);

        if ($autoSupersede && $signal->type->supersedes() !== []) {
            $automatic = Signal::query()
                ->active()
                ->where('player_id', $signal->player_id)
                ->whereIn('type', array_map(fn (SignalType $t) => $t->value, $signal->type->supersedes()))
                ->where('id', '!=', $signal->id)
                ->pluck('id');

            $targets = $targets->merge($automatic);
        }

        $targets = $targets->unique()->reject(fn (int $id) => $id === $signal->id)->values();

        if ($targets->isEmpty()) {
            return [];
        }

        Signal::query()->whereIn('id', $targets)->update(['superseded_by' => $signal->id]);

        return $targets->all();
    }
}

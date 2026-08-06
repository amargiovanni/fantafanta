<?php

namespace App\Services;

use App\Enums\PlayerRole;
use App\Enums\PlayerStatus;
use App\Jobs\RecomputeValuations;
use App\Models\Player;
use App\Models\PlayerAlias;
use App\Support\FantacalcioNameParser;
use App\Support\NameNormalizer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Importa il listone quotazioni fantacalcio.it.
 *
 * Formato reale: la prima riga è un'intestazione generica (titolo/versione),
 * sempre da scartare; la seconda riga sono i veri header colonna
 * (Id,R,RM,Nome,Squadra,Qt.A,Qt.I,Diff,Qt.A M,Qt.I M,Diff M,FVM,FVM M,...).
 * Il mapping colonna → campo è passato dal chiamante (tipicamente la UI
 * Livewire, dopo l'anteprima) perché fantacalcio.it può cambiare il formato
 * (§10 dei rischi noti).
 */
class ListoneImporter
{
    private const REQUIRED_MAPPING_KEYS = ['name', 'role', 'real_team', 'quotazione', 'fvm'];

    /**
     * Legge l'intestazione reale del CSV (dopo aver scartato la prima riga
     * generica) e le prime $limit righe di dati, per l'anteprima di mapping.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<string, string>>, suggested_mapping: array<string, string>}
     */
    public function preview(string $csvContent, int $limit = 5): array
    {
        $parsed = $this->parseCsv($csvContent);
        $rows = array_slice($parsed['rows'], 0, $limit);

        return [
            'headers' => $parsed['headers'],
            'rows' => $rows,
            'suggested_mapping' => $this->guessMapping($parsed['headers']),
        ];
    }

    /**
     * Suggerisce un mapping colonna → campo confrontando gli header reali
     * con le colonne note del formato fantacalcio.it. Punto di partenza per
     * la UI, sempre confermabile/modificabile dall'utente prima dell'import.
     *
     * @param  array<int, string>  $headers
     * @return array<string, string>
     */
    public function guessMapping(array $headers): array
    {
        $known = [
            'name' => ['nome'],
            'role' => ['r'],
            'real_team' => ['squadra'],
            'quotazione' => ['qt.a', 'qta'],
            'fvm' => ['fvm'],
        ];

        $mapping = [];
        foreach ($known as $field => $candidates) {
            foreach ($headers as $header) {
                if (in_array(mb_strtolower(trim($header)), $candidates, true)) {
                    $mapping[$field] = $header;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Esegue l'import completo: crea/aggiorna i giocatori del CSV, genera
     * gli alias automatici per i nuovi giocatori, marca come `removed` chi
     * non compare più nel listone. Idempotente: un secondo import con lo
     * stesso CSV aggiorna quotazioni/fvm/stats senza toccare gli alias
     * esistenti né duplicare nulla.
     *
     * @param  array<string, mixed>  $mapping  ['name'=>'Nome','role'=>'R','real_team'=>'Squadra','quotazione'=>'Qt.A','fvm'=>'FVM','stats'=>['Pv','Mv',...]]
     * @return array{created: int, updated: int, removed: int, skipped: int, aliases_created: int}
     */
    public function import(string $csvContent, array $mapping): array
    {
        $this->assertMappingIsComplete($mapping);

        $parsed = $this->parseCsv($csvContent);
        $statsColumns = $mapping['stats'] ?? [];

        $summary = ['created' => 0, 'updated' => 0, 'removed' => 0, 'skipped' => 0, 'aliases_created' => 0];
        $touchedNormalizedNames = [];

        DB::transaction(function () use ($parsed, $mapping, $statsColumns, &$summary, &$touchedNormalizedNames) {
            foreach ($parsed['rows'] as $row) {
                $rawName = trim((string) ($row[$mapping['name']] ?? ''));
                $rawRole = mb_strtoupper(trim((string) ($row[$mapping['role']] ?? '')));

                if ($rawName === '' || ! PlayerRole::tryFrom($rawRole) instanceof PlayerRole) {
                    $summary['skipped']++;

                    continue;
                }

                $role = PlayerRole::from($rawRole);
                $parsedName = FantacalcioNameParser::parse($rawName);
                $normalizedName = NameNormalizer::normalize($parsedName['display']);

                $realTeam = trim((string) ($row[$mapping['real_team']] ?? '')) ?: null;
                $quotazione = (int) ($row[$mapping['quotazione']] ?? 0);
                $fvm = (int) ($row[$mapping['fvm']] ?? 0);

                $seasonStats = [];
                foreach ($statsColumns as $column) {
                    if (array_key_exists($column, $row)) {
                        $seasonStats[$column] = $row[$column];
                    }
                }

                $existing = Player::where('normalized_name', $normalizedName)->first();

                if ($existing) {
                    $existing->update([
                        'role' => $role,
                        'real_team' => $realTeam,
                        'quotazione' => $quotazione,
                        'fvm' => $fvm,
                        'season_stats' => $seasonStats,
                        'status' => PlayerStatus::Available,
                    ]);
                    $summary['updated']++;
                } else {
                    $player = Player::create([
                        'name' => $parsedName['display'],
                        'role' => $role,
                        'real_team' => $realTeam,
                        'quotazione' => $quotazione,
                        'fvm' => $fvm,
                        'season_stats' => $seasonStats,
                        'status' => PlayerStatus::Available,
                    ]);
                    $summary['aliases_created'] += $this->generateAliases($player, $parsedName);
                    $summary['created']++;
                }

                $touchedNormalizedNames[] = $normalizedName;
            }

            $summary['removed'] = Player::whereNotIn('normalized_name', $touchedNormalizedNames)
                ->where('status', '!=', PlayerStatus::Removed->value)
                ->update(['status' => PlayerStatus::Removed->value]);
        });

        // Il listone è cambiato: quotazioni, FVM e statistiche sono gli input
        // del motore di valutazione (briefing §5), quindi tutte le valutazioni
        // esistenti sono da riscrivere.
        RecomputeValuations::dispatch();

        return $summary;
    }

    /**
     * @param  array{surname: string, given: string, given_initial: ?string, display: string}  $parsedName
     */
    private function generateAliases(Player $player, array $parsedName): int
    {
        $candidates = [
            $parsedName['surname'],
            $parsedName['given_initial'] ? $parsedName['surname'].' '.$parsedName['given_initial'] : null,
            $parsedName['given'] ? $parsedName['given'].' '.$parsedName['surname'] : null,
        ];

        $seen = [];
        $rows = [];
        $now = now();

        foreach (array_filter($candidates) as $aliasText) {
            $normalized = NameNormalizer::normalize($aliasText);

            if ($normalized === '' || $normalized === $player->normalized_name || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $rows[] = [
                'player_id' => $player->id,
                'alias' => $aliasText,
                'normalized_alias' => $normalized,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            PlayerAlias::insert($rows);
        }

        return count($rows);
    }

    private function assertMappingIsComplete(array $mapping): void
    {
        $missing = array_diff(self::REQUIRED_MAPPING_KEYS, array_keys(array_filter($mapping)));

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Mapping colonne incompleto, mancano: '.implode(', ', $missing)
            );
        }
    }

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<string, string>>}
     */
    private function parseCsv(string $csvContent): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csvContent);
        rewind($stream);

        // Riga 1: intestazione generica di fantacalcio.it (titolo/versione), sempre scartata.
        // $escape esplicito (stringa vuota): ometterlo è deprecato da PHP 8.4.
        fgetcsv($stream, null, ',', '"', '');

        $headers = fgetcsv($stream, null, ',', '"', '');
        if ($headers === false) {
            fclose($stream);

            return ['headers' => [], 'rows' => []];
        }
        $headers = array_map(fn ($header) => trim((string) $header), $headers);

        $rows = [];
        while (($line = fgetcsv($stream, null, ',', '"', '')) !== false) {
            if ($line === [null]) {
                continue;
            }

            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = trim((string) ($line[$index] ?? ''));
            }
            $rows[] = $row;
        }

        fclose($stream);

        return ['headers' => $headers, 'rows' => $rows];
    }
}

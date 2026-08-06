<?php

use App\Enums\PlayerRole;
use App\Models\LeagueConfig;
use App\Models\Player;
use App\Models\Team;
use App\Models\Valuation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Fixture di lega e listone (Fase 2)
|--------------------------------------------------------------------------
|
| Il motore di valutazione e la validazione del piano hanno bisogno degli
| stessi tre ingredienti in quasi ogni test: una lega configurata, le squadre
| registrate e un listone. Stanno qui e non in ogni file perché una fixture
| ripetuta è una fixture che diverge, e un test del motore che gira su una
| lega diversa da quella dell'acceptance non dimostra niente.
|
*/

/**
 * Configura la lega. I default sono quelli reali di Andrea: 8 squadre da 500
 * crediti, modificatore di difesa attivo.
 *
 * @param  array<string, int>|null  $slots
 */
function configuraLega(
    int $teams = 8,
    int $credits = 500,
    ?array $slots = null,
    bool $modificatoreDifesa = true,
    bool $modificatoreFairplay = true,
): LeagueConfig {
    return tap(LeagueConfig::current(), fn (LeagueConfig $config) => $config->update([
        'teams_count' => $teams,
        'total_credits' => $credits,
        'slots' => $slots ?? LeagueConfig::DEFAULT_SLOTS,
        'modifier_defense' => $modificatoreDifesa,
        'modifier_fairplay' => $modificatoreFairplay,
    ]));
}

/**
 * Registra le squadre della lega: la prima è la mia.
 *
 * @return Collection<int, Team>
 */
function registraSquadre(int $quante = 8, int $crediti = 500): Collection
{
    return collect(range(1, $quante))->map(fn (int $i) => Team::factory()->create([
        'name' => $i === 1 ? 'La mia squadra' : "Avversario {$i}",
        'is_mine' => $i === 1,
        'credits_total' => $crediti,
    ]));
}

/**
 * Un giocatore con statistiche di stagione nel formato reale del CSV
 * fantacalcio.it (colonne Pv, Mv, Fm, Am), che è quello che il motore legge.
 */
function giocatore(
    PlayerRole $ruolo,
    int $quotazione = 20,
    int $fvm = 100,
    float $fantamedia = 6.5,
    float $mediaVoto = 6.0,
    int $presenze = 30,
    int $ammonizioni = 3,
    float $titolarita = 1.0,
    ?string $nome = null,
    ?string $squadra = null,
): Player {
    return Player::factory()->create(array_filter([
        'name' => $nome,
    ]) + [
        'role' => $ruolo,
        'real_team' => $squadra ?? 'Inter',
        'quotazione' => $quotazione,
        'fvm' => $fvm,
        'expected_starter' => $titolarita,
        'season_stats' => [
            'Pv' => (string) $presenze,
            'Mv' => (string) $mediaVoto,
            'Fm' => (string) $fantamedia,
            'Am' => (string) $ammonizioni,
        ],
    ]);
}

/**
 * Il valore corrente di un giocatore, come lo leggerebbe la sala d'asta.
 */
function valutazione(Player $player): Valuation
{
    return Valuation::query()->where('player_id', $player->id)->firstOrFail();
}

/**
 * Un listone abbastanza popolato da permettere un piano completo: per ogni
 * ruolo i titolari degli slot più una riserva di alternative.
 *
 * @return Collection<string, Collection<int, Player>>
 */
function listonePerPiano(): Collection
{
    $slots = LeagueConfig::current()->slots;

    return collect($slots)->mapWithKeys(fn (int $quanti, string $ruolo) => [
        $ruolo => collect(range(1, $quanti + 6))->map(fn (int $i) => giocatore(
            PlayerRole::from($ruolo),
            quotazione: max(1, 40 - $i),
            fvm: max(1, 300 - $i * 10),
        )),
    ]);
}

/**
 * Un piano valido costruito su quel listone: titolari i primi di ogni ruolo,
 * alternative i successivi. Serve a mille test come punto di partenza, e a
 * ciascuno per rompere una regola alla volta.
 *
 * @param  Collection<string, Collection<int, Player>>  $listone
 * @return array<int, array<string, mixed>>
 */
function pianoValido(Collection $listone, int $prezzo = 5): array
{
    $slots = LeagueConfig::current()->slots;
    $piano = [];

    foreach ($slots as $ruolo => $quanti) {
        $giocatori = $listone[$ruolo];
        $riserve = $giocatori->slice($quanti)->values();

        foreach (range(1, $quanti) as $indice) {
            $piano[] = [
                'role' => $ruolo,
                'slot_index' => $indice,
                'player_id' => $giocatori[$indice - 1]->id,
                'target_price' => $prezzo,
                'max_price' => $prezzo + 3,
                'alternatives' => [
                    ['player_id' => $riserve[0]->id, 'target_price' => $prezzo],
                    ['player_id' => $riserve[1]->id, 'target_price' => max(1, $prezzo - 1)],
                ],
            ];
        }
    }

    return $piano;
}

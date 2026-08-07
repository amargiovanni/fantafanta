<?php

use App\Enums\AuctionStatus;
use App\Enums\PlanStatus;
use App\Enums\PlayerRole;
use App\Enums\SignalType;
use App\Jobs\RecomputeValuations;
use App\Jobs\RunClaudeTask;
use App\Livewire\Dashboard;
use App\Models\Auction;
use App\Models\Plan;
use App\Models\Player;
use App\Models\Signal;
use App\Models\Team;
use App\Services\PlanWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * La dashboard sonda i servizi reali: nella suite le sonde sono finte, così i
 * test non dipendono dal fatto che Redis o Meilisearch girino sulla macchina
 * di chi li esegue.
 */
beforeEach(function () {
    Cache::forget('dashboard.health');

    Process::fake(['*claude*' => Process::result(output: '2.1.220 (Claude Code)')]);
    Http::fake([
        '*/health' => Http::response(['status' => 'available']),
        '*/mcp' => Http::response(['result' => ['tools' => [['name' => 'search_player']]]]),
    ]);
});

it('renders the dashboard route', function () {
    $this->get(route('dashboard'))->assertOk();
});

it('shows counts of players and teams', function () {
    Player::factory()->count(3)->create();
    Team::factory()->create(['is_mine' => true, 'name' => 'La mia squadra']);

    Livewire::test(Dashboard::class)
        ->assertSee('3')
        ->assertSee('La mia squadra');
});

it('mostra lo stato dei servizi', function () {
    Livewire::test(Dashboard::class)
        ->assertSee('Salute della pipeline')
        ->assertSee('Claude Code CLI')
        ->assertSee('2.1.220 (Claude Code)')
        ->assertSee('Server MCP fanta-asta');
});

it('segnala un servizio giù', function () {
    Process::fake(['*claude*' => Process::result(output: '', errorOutput: 'command not found', exitCode: 127)]);
    Cache::forget('dashboard.health');

    Livewire::test(Dashboard::class)->assertSee('command not found');
});

it('mostra i contatori della pipeline di conoscenza', function () {
    Signal::factory()->count(2)->create();
    Signal::factory()->needsReview('il Toro')->create();

    Livewire::test(Dashboard::class)
        ->assertViewHas('signalsCount', 3)
        ->assertViewHas('signalsToReview', 1)
        ->assertSee('Segnali attivi');
});

it('spiega cosa fare quando non c\'è ancora nessun piano', function () {
    Livewire::test(Dashboard::class)
        ->assertSee('Nessuna sessione')
        ->assertSee('Aprine una per poter generare il piano')
        ->assertSee('Apri sessione');
});

it('apre la sessione d\'asta e da lì offre di generare il piano', function () {
    Livewire::test(Dashboard::class)
        ->call('openAuction')
        ->assertSee('Genera piano');

    expect(Auction::query()->count())->toBe(1)
        ->and(Auction::current()->status)->toBe(AuctionStatus::Setup);
});

it('non apre due sessioni d\'asta', function () {
    Livewire::test(Dashboard::class)->call('openAuction')->call('openAuction');

    expect(Auction::query()->count())->toBe(1);
});

// Incidente reale del 2026-08-06: il bottone "Genera piano" è rimasto
// affamato dietro al backlog della coda 'ai' per 45+ minuti perché il suo
// lavoro — interattivo, l'utente aspetta la risposta — condivideva la coda
// bulk dello scraping invece della coda prioritaria 'ai-replan' (stesso
// lavoro del replan, che infatti non ha sofferto: vedi Replanner::launch,
// che usa già config('fanta.replan.queue')). config/horizon.php conferma la
// priorità: soglia d'attesa 30s su 'ai-replan' contro 300s su 'ai'.
it('mette in coda la generazione del piano sulla coda prioritaria ai-replan, senza chiamare l\'AI adesso', function () {
    Queue::fake();

    configuraLega();
    registraSquadre();
    $auction = Auction::factory()->create();
    giocatore(PlayerRole::Attaccante);

    Livewire::test(Dashboard::class)
        ->call('generatePlan')
        ->assertSee('Generazione del piano avviata');

    $plan = Plan::query()->where('auction_id', $auction->id)->sole();

    expect($plan->status)->toBe(PlanStatus::Generating)
        ->and($plan->version)->toBe(1);

    Queue::assertPushed(RunClaudeTask::class, fn (RunClaudeTask $job) => $job->task === 'generate-plan'
        && $job->promptFile === 'generate-plan.md'
        && $job->context === ['auction_id' => $auction->id, 'plan_id' => $plan->id]
        && $job->queue === config('fanta.replan.queue')
        && $job->queue === 'ai-replan');
});

it('rifiuta di generare un piano senza asta o senza listone', function () {
    Queue::fake();

    Livewire::test(Dashboard::class)
        ->call('generatePlan')
        ->assertSee('Apri prima la sessione d\'asta');

    Auction::factory()->create();

    Livewire::test(Dashboard::class)
        ->call('generatePlan')
        ->assertSee('Il listone è vuoto');

    Queue::assertNotPushed(RunClaudeTask::class);
});

it('rifiuta un secondo generatePlan mentre il primo è ancora in generazione', function () {
    Queue::fake();

    configuraLega();
    registraSquadre();
    $auction = Auction::factory()->create();
    giocatore(PlayerRole::Attaccante);

    Livewire::test(Dashboard::class)->call('generatePlan');

    Livewire::test(Dashboard::class)
        ->call('generatePlan')
        ->assertSee('C\'è già una generazione in corso');

    Queue::assertPushed(RunClaudeTask::class, 1);
    expect(Plan::query()->where('auction_id', $auction->id)->count())->toBe(1);
});

it('mette in coda il ricalcolo delle valutazioni', function () {
    Queue::fake();

    Livewire::test(Dashboard::class)
        ->call('recomputeValuations')
        ->assertSee('Ricalcolo delle valutazioni in coda');

    Queue::assertPushed(RecomputeValuations::class);
});

it('mostra il piano corrente per reparto, con alternative, note e versione', function () {
    Queue::fake();

    configuraLega();
    registraSquadre();
    $auction = Auction::factory()->create();
    $listone = listonePerPiano();

    app(PlanWriter::class)->save($auction, pianoValido($listone), 'Difesa concentrata sull\'Inter.');

    Livewire::test(Dashboard::class)
        ->assertSee('versione 1')
        ->assertSee('Difesa concentrata sull\'Inter.')
        ->assertSee('Portieri')
        ->assertSee('Difensori')
        ->assertSee('Centrocampisti')
        ->assertSee('Attaccanti')
        ->assertSee($listone['A'][0]->name)
        ->assertSee('Da prendere');
});

it('offre la stampa del piano con una testata dedicata alla pagina stampata', function () {
    Queue::fake();

    configuraLega();
    registraSquadre();
    $auction = Auction::factory()->create();
    $listone = listonePerPiano();

    app(PlanWriter::class)->save($auction, pianoValido($listone), 'Nota di piano.');

    Livewire::test(Dashboard::class)
        ->assertSee('Stampa piano')
        ->assertSeeHtml('window.print()')
        ->assertSee('Piano d\'acquisto — versione 1', false);
});

it('non mostra il bottone di stampa senza un piano', function () {
    Queue::fake();

    Livewire::test(Dashboard::class)->assertDontSee('Stampa piano');
});

it('segnala quando una versione più recente del piano è in elaborazione', function () {
    Queue::fake();

    configuraLega();
    registraSquadre();
    $auction = Auction::factory()->create();
    $listone = listonePerPiano();

    app(PlanWriter::class)->save($auction, pianoValido($listone), 'Prima versione.');

    Plan::factory()->generating()->create(['auction_id' => $auction->id, 'version' => 2]);

    Livewire::test(Dashboard::class)->assertSee('ricalcolo in corso');
});

it('mostra i segnali recenti con il loro impatto', function () {
    Queue::fake();

    $player = giocatore(PlayerRole::Attaccante, nome: 'Bomber Uno');

    Signal::factory()->create([
        'player_id' => $player->id,
        'type' => SignalType::Rigorista,
        'impact' => 2,
        'confidence' => 0.9,
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('Segnali recenti')
        ->assertSee('Bomber Uno')
        ->assertSee('Rigorista');
});

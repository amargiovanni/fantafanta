<?php

use App\Mcp\Servers\FantaAstaServer;
use App\Mcp\Tools\GetCurrentPlanTool;
use App\Mcp\Tools\SavePlanTool;
use App\Models\Acquisition;
use App\Models\Auction;
use App\Models\Plan;
use App\Services\PlanWriter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;

/**
 * I due tool che chiudono il cerchio della Fase 2: quello con cui Claude
 * scrive il piano, e quello con cui lo rilegge al giro dopo.
 */
beforeEach(function () {
    Queue::fake();

    configuraLega();
    $this->squadre = registraSquadre();
    $this->auction = Auction::factory()->create();
    $this->listone = listonePerPiano();
});

it('save_plan scrive i 25 slot e risponde con il riepilogo', function () {
    FantaAstaServer::tool(SavePlanTool::class, [
        'strategy_notes' => "Difesa concentrata su Inter.\nUn top in attacco, il resto fascia media.",
        'slots' => pianoValido($this->listone),
    ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('version', 1)
            ->where('status', 'ready')
            ->where('slots_saved', 25)
            ->where('pending_slots', 25)
            ->where('acquired_slots', 0)
            ->where('planned_spend', 125)
            ->where('budget_summary.C.allocated', 40)
            ->etc()
        );

    expect(Plan::query()->count())->toBe(1);
});

it('save_plan rifiuta il piano intero elencando TUTTE le violazioni, e al secondo colpo lo accetta', function () {
    $rotto = pianoValido($this->listone);
    $rotto[0]['player_id'] = $this->listone['A'][0]->id;  // portiere che è un attaccante
    $rotto[5]['alternatives'] = [];                       // slot senza ripieghi
    $rotto[9]['target_price'] = 0;                        // prezzo impossibile

    $risposta = FantaAstaServer::tool(SavePlanTool::class, [
        'strategy_notes' => 'Prima proposta.',
        'slots' => $rotto,
    ]);

    $risposta->assertHasErrors();
    $risposta->assertSee('non può occupare uno slot P');
    $risposta->assertSee('servono almeno 2 alternative');
    $risposta->assertSee('ogni slot costa almeno 1 credito');
    $risposta->assertSee('richiama save_plan una volta sola');

    expect(Plan::query()->count())->toBe(0);

    // Corretto tutto in un colpo, come il messaggio d'errore chiede.
    FantaAstaServer::tool(SavePlanTool::class, [
        'strategy_notes' => 'Corretto: ruoli a posto, alternative complete, prezzi minimi rispettati.',
        'slots' => pianoValido($this->listone),
    ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('version', 1)->etc());

    expect(Plan::query()->count())->toBe(1);
});

it('save_plan si rifiuta di scrivere se non c\'è una sessione d\'asta', function () {
    Auction::query()->delete();

    FantaAstaServer::tool(SavePlanTool::class, [
        'strategy_notes' => 'Note.',
        'slots' => pianoValido($this->listone),
    ])->assertHasErrors();
});

it('save_plan riconosce i miei acquisti e chiude quegli slot', function () {
    $mio = $this->listone['C'][0];

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $mio->id,
        'team_id' => $this->squadre[0]->id,
        'price' => 44,
    ]);

    $piano = collect(pianoValido($this->listone))->map(function (array $slot) use ($mio) {
        if ($slot['player_id'] === $mio->id) {
            $slot['target_price'] = 44;
            $slot['max_price'] = 44;
            $slot['alternatives'] = [];
        }

        return $slot;
    })->all();

    FantaAstaServer::tool(SavePlanTool::class, [
        'strategy_notes' => 'Centrocampo già aperto con il colpo grosso.',
        'slots' => $piano,
        'trigger' => 'acquisition',
    ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('acquired_slots', 1)
            ->where('pending_slots', 24)
            ->where('budget_summary.C.spent', 44)
            ->etc()
        );
});

it('get_current_plan restituisce l\'ultima versione pronta con i nomi e lo stato degli slot', function () {
    app(PlanWriter::class)->save($this->auction, pianoValido($this->listone), 'Versione uno.');
    app(PlanWriter::class)->save($this->auction, pianoValido($this->listone), 'Versione due.');

    FantaAstaServer::tool(GetCurrentPlanTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('plan.version', 2)
            ->where('plan.strategy_notes', 'Versione due.')
            ->where('plan.newer_version_generating', false)
            ->where('plan.slots.0.role', 'P')
            ->where('plan.slots.0.slot_index', 1)
            ->where('plan.slots.0.player_name', $this->listone['P'][0]->name)
            ->where('plan.slots.0.slot_status', 'pending')
            ->where('plan.slots.0.alternatives.0.player_name', fn ($nome) => $nome !== null)
            ->where('plan.slots', fn ($slots) => count($slots) === 25)
            ->etc()
        );
});

it('get_current_plan segnala quando una versione più recente è in elaborazione', function () {
    app(PlanWriter::class)->save($this->auction, pianoValido($this->listone), 'Versione uno.');

    Plan::factory()->generating()->create([
        'auction_id' => $this->auction->id,
        'version' => 2,
    ]);

    FantaAstaServer::tool(GetCurrentPlanTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('plan.version', 1)
            ->where('plan.newer_version_generating', true)
            ->etc()
        );
});

it('get_current_plan dice chiaramente che il piano non esiste ancora', function () {
    FantaAstaServer::tool(GetCurrentPlanTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('plan', null)
            ->where('message', 'Nessun piano ancora pronto per questa asta: questa sarà la versione 1.')
            ->etc()
        );
});

it('get_current_plan mostra l\'alternativa promossa dopo che il titolare è sfumato', function () {
    $piano = app(PlanWriter::class)->save($this->auction, pianoValido($this->listone), 'Versione uno.');
    $slot = $piano->slots()->where('role', 'A')->where('slot_index', 1)->firstOrFail();
    $alternativa = $slot->alternatives[0]['player_id'];

    Acquisition::factory()->create([
        'auction_id' => $this->auction->id,
        'player_id' => $slot->player_id,
        'team_id' => $this->squadre[5]->id,
        'price' => 40,
    ]);

    FantaAstaServer::tool(GetCurrentPlanTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('plan.slots.19.slot_status', 'lost')
            ->where('plan.slots.19.player_id', $alternativa)
            ->etc()
        );
});

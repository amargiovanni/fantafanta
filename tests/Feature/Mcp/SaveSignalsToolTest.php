<?php

use App\Enums\SignalType;
use App\Mcp\Servers\FantaAstaServer;
use App\Mcp\Tools\SaveSignalsTool;
use App\Models\Player;
use App\Models\Signal;
use App\Models\Source;

beforeEach(function () {
    $this->player = Player::factory()->create(['name' => 'MARTINEZ Lautaro', 'real_team' => 'Inter']);
    $this->source = Source::factory()->create(['title' => 'Gazzetta — Lautaro ko']);
});

it('salva un segnale valido collegandolo a giocatore e fonte', function () {
    FantaAstaServer::tool(SaveSignalsTool::class, [
        'signals' => [[
            'player_id' => $this->player->id,
            'type' => 'infortunio',
            'source_id' => $this->source->id,
            'confidence' => 0.9,
            'impact' => -2,
            'event_date' => '2026-08-05',
            'payload' => ['stop_stimato_giorni' => 20, 'dettaglio' => 'lesione al flessore'],
        ]],
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('created', 1)->where('needs_review', 0)->etc());

    $signal = Signal::query()->sole();

    expect($signal->player_id)->toBe($this->player->id)
        ->and($signal->source_id)->toBe($this->source->id)
        ->and($signal->type)->toBe(SignalType::Infortunio)
        ->and($signal->impact)->toBe(-2)
        ->and($signal->confidence)->toBe(0.9)
        ->and($signal->payload['stop_stimato_giorni'])->toBe(20)
        ->and($signal->needs_review)->toBeFalse();
});

it('accetta un segnale non risolto solo se dichiara raw_name e needs_review', function () {
    FantaAstaServer::tool(SaveSignalsTool::class, [
        'signals' => [[
            'player_id' => null,
            'raw_name' => 'il Tucu',
            'needs_review' => true,
            'type' => 'ballottaggio',
            'source_id' => $this->source->id,
            'confidence' => 0.5,
            'impact' => 0,
        ]],
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('needs_review', 1)->etc());

    expect(Signal::query()->sole())
        ->player_id->toBeNull()
        ->raw_name->toBe('il Tucu')
        ->needs_review->toBeTrue();
});

it('rifiuta un segnale orfano che non si dichiara tale', function () {
    FantaAstaServer::tool(SaveSignalsTool::class, [
        'signals' => [[
            'type' => 'infortunio',
            'source_id' => $this->source->id,
            'confidence' => 0.8,
            'impact' => -1,
        ]],
    ])->assertHasErrors();

    expect(Signal::query()->count())->toBe(0);
});

it('rifiuta gli input invalidi spiegando esattamente cosa non va', function (array $signal, string $atteso) {
    $response = FantaAstaServer::tool(SaveSignalsTool::class, ['signals' => [$signal]]);

    $response->assertHasErrors();
    $response->assertSee($atteso);

    expect(Signal::query()->count())->toBe(0);
})->with([
    'tipo fuori enum' => [
        fn () => ['player_id' => 1, 'type' => 'raffreddore', 'source_id' => 1, 'confidence' => 0.5, 'impact' => 0],
        'tipo "raffreddore" non valido',
    ],
    'confidence oltre 1' => [
        fn () => ['player_id' => 1, 'type' => 'forma', 'source_id' => 1, 'confidence' => 1.4, 'impact' => 0],
        'confidence "1.4" fuori scala',
    ],
    'confidence negativa' => [
        fn () => ['player_id' => 1, 'type' => 'forma', 'source_id' => 1, 'confidence' => -0.2, 'impact' => 0],
        'fuori scala',
    ],
    'impact fuori range' => [
        fn () => ['player_id' => 1, 'type' => 'forma', 'source_id' => 1, 'confidence' => 0.5, 'impact' => 5],
        'impact 5 fuori range',
    ],
    'giocatore inesistente' => [
        fn () => ['player_id' => 987654, 'type' => 'forma', 'source_id' => 1, 'confidence' => 0.5, 'impact' => 0],
        'player_id 987654 inesistente nel listone',
    ],
    'fonte inesistente' => [
        fn () => ['player_id' => 1, 'type' => 'forma', 'source_id' => 987654, 'confidence' => 0.5, 'impact' => 0],
        'source_id 987654 inesistente',
    ],
    'data non valida' => [
        fn () => ['player_id' => 1, 'type' => 'forma', 'source_id' => 1, 'confidence' => 0.5, 'impact' => 0, 'event_date' => 'ieri sera'],
        'event_date "ieri sera" non è una data valida',
    ],
]);

it('non salva NIENTE se anche un solo segnale del batch è invalido', function () {
    FantaAstaServer::tool(SaveSignalsTool::class, [
        'signals' => [
            [
                'player_id' => $this->player->id,
                'type' => 'infortunio',
                'source_id' => $this->source->id,
                'confidence' => 0.9,
                'impact' => -2,
            ],
            [
                'player_id' => $this->player->id,
                'type' => 'inventato',
                'source_id' => $this->source->id,
                'confidence' => 0.9,
                'impact' => 0,
            ],
        ],
    ])->assertHasErrors();

    // Il primo segnale era valido: se fosse stato scritto, il batch non sarebbe transazionale.
    expect(Signal::query()->count())->toBe(0);
});

it('corrobora invece di duplicare quando la stessa notizia arriva da un\'altra fonte', function () {
    $altraFonte = Source::factory()->create(['title' => 'SOS Fanta — stesso infortunio']);

    $base = [
        'player_id' => $this->player->id,
        'type' => 'infortunio',
        'confidence' => 0.6,
        'impact' => -2,
    ];

    FantaAstaServer::tool(SaveSignalsTool::class, ['signals' => [[...$base, 'source_id' => $this->source->id]]])->assertOk();

    FantaAstaServer::tool(SaveSignalsTool::class, ['signals' => [[...$base, 'source_id' => $altraFonte->id]]])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('created', 0)->where('corroborated', 1)->etc());

    $signal = Signal::query()->sole();

    // 0.6 + (1 - 0.6) * 0.6 * 0.5 = 0.72
    expect($signal->confidence)->toBe(0.72);
});

it('non gonfia la confidence quando lo stesso job ritenta con la stessa fonte', function () {
    $payload = ['signals' => [[
        'player_id' => $this->player->id,
        'type' => 'infortunio',
        'source_id' => $this->source->id,
        'confidence' => 0.7,
        'impact' => -2,
    ]]];

    FantaAstaServer::tool(SaveSignalsTool::class, $payload)->assertOk();
    FantaAstaServer::tool(SaveSignalsTool::class, $payload)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('duplicate_ignored', 1)->etc());

    expect(Signal::query()->count())->toBe(1)
        ->and(Signal::query()->sole()->confidence)->toBe(0.7);
});

it('non supera il tetto di 1.0 nemmeno con molte conferme', function () {
    $base = ['player_id' => $this->player->id, 'type' => 'rigorista', 'confidence' => 1.0, 'impact' => 2];

    foreach (range(1, 8) as $i) {
        $source = Source::factory()->create(['title' => "Fonte {$i}"]);
        FantaAstaServer::tool(SaveSignalsTool::class, ['signals' => [[...$base, 'source_id' => $source->id]]])->assertOk();
    }

    expect(Signal::query()->count())->toBe(1)
        ->and(Signal::query()->sole()->confidence)->toBeLessThanOrEqual(1.0);
});

it('marca come superati i segnali indicati esplicitamente', function () {
    $vecchio = Signal::factory()->create([
        'player_id' => $this->player->id,
        'type' => SignalType::Ballottaggio,
        'source_id' => $this->source->id,
    ]);

    FantaAstaServer::tool(SaveSignalsTool::class, [
        'signals' => [[
            'player_id' => $this->player->id,
            'type' => 'titolarita',
            'source_id' => $this->source->id,
            'confidence' => 0.85,
            'impact' => 1,
            'supersedes' => [$vecchio->id],
        ]],
    ])->assertOk();

    $nuovo = Signal::query()->where('type', 'titolarita')->sole();

    expect($vecchio->fresh()->superseded_by)->toBe($nuovo->id)
        ->and($vecchio->fresh()->isActive())->toBeFalse();
});

it('impedisce di superare il segnale di un altro giocatore', function () {
    $altro = Player::factory()->create(['name' => 'DUMFRIES Denzel']);
    $suoSegnale = Signal::factory()->create(['player_id' => $altro->id, 'source_id' => $this->source->id]);

    FantaAstaServer::tool(SaveSignalsTool::class, [
        'signals' => [[
            'player_id' => $this->player->id,
            'type' => 'rientro',
            'source_id' => $this->source->id,
            'confidence' => 0.9,
            'impact' => 1,
            'supersedes' => [$suoSegnale->id],
        ]],
    ])
        ->assertHasErrors()
        ->assertSee('riguarda un altro giocatore');

    expect($suoSegnale->fresh()->superseded_by)->toBeNull();
});

it('impedisce a un segnale non risolto di superare qualsiasi cosa', function () {
    $esistente = Signal::factory()->create(['player_id' => $this->player->id, 'source_id' => $this->source->id]);

    FantaAstaServer::tool(SaveSignalsTool::class, [
        'signals' => [[
            'player_id' => null,
            'raw_name' => 'un tale',
            'needs_review' => true,
            'type' => 'rientro',
            'source_id' => $this->source->id,
            'confidence' => 0.9,
            'impact' => 1,
            'supersedes' => [$esistente->id],
        ]],
    ])->assertHasErrors()->assertSee('non può marcare come superato');

    expect($esistente->fresh()->superseded_by)->toBeNull();
});

it('rifiuta un batch vuoto', function () {
    FantaAstaServer::tool(SaveSignalsTool::class, ['signals' => []])->assertHasErrors();
});

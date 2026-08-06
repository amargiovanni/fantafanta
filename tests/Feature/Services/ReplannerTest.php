<?php

use App\Enums\PlanStatus;
use App\Enums\PlanTrigger;
use App\Jobs\RunClaudeTask;
use App\Jobs\ScheduleReplan;
use App\Models\Auction;
use App\Models\Plan;
use App\Services\Replanner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * Il debounce del replan (briefing §7.3).
 *
 * La regola è una sola frase — «il run parte venti secondi dopo l'ultima
 * aggiudicazione» — e tutti i casi difficili stanno nei bordi: la raffica che
 * non finisce mai, il run che è ancora in volo, il job che si sveglia dopo che
 * qualcun altro ha già fatto il lavoro.
 */
beforeEach(function () {
    Queue::fake();
    Cache::flush();

    configuraLega();
    registraSquadre();

    $this->auction = Auction::factory()->live()->create();
    $this->replanner = app(Replanner::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('scrive un marker con il primo e l\'ultimo evento della raffica', function () {
    Carbon::setTestNow($t0 = Carbon::parse('2026-08-07 21:00:00'));
    $this->replanner->schedule($this->auction);

    Carbon::setTestNow($t0->copy()->addSeconds(8));
    $this->replanner->schedule($this->auction);

    $marker = Cache::get(Replanner::markerKey($this->auction->id));

    expect($marker['first'])->toBe($t0->timestamp)
        ->and($marker['last'])->toBe($t0->copy()->addSeconds(8)->timestamp);

    Queue::assertPushed(ScheduleReplan::class, 2);
});

it('il job che scopre di non essere il più giovane esce senza fare nulla', function () {
    Carbon::setTestNow($t0 = Carbon::parse('2026-08-07 21:00:00'));
    $this->replanner->schedule($this->auction);

    Carbon::setTestNow($t0->copy()->addSeconds(10));
    $this->replanner->schedule($this->auction);

    Carbon::setTestNow($t0->copy()->addSeconds(30));

    expect($this->replanner->shouldRun($this->auction->id, $t0->timestamp))->toBeFalse()
        ->and($this->replanner->shouldRun($this->auction->id, $t0->copy()->addSeconds(10)->timestamp))->toBeTrue();
});

it('una raffica che non finisce mai non blocca il replan per sempre', function () {
    Carbon::setTestNow($t0 = Carbon::parse('2026-08-07 21:00:00'));
    $this->replanner->schedule($this->auction);

    // Un acquisto ogni quindici secondi: con un debounce puro il momento buono
    // non arriverebbe mai. Dopo `max_wait` si parte comunque.
    for ($secondi = 15; $secondi <= 105; $secondi += 15) {
        Carbon::setTestNow($t0->copy()->addSeconds($secondi));
        $this->replanner->schedule($this->auction);
    }

    Carbon::setTestNow($t0->copy()->addSeconds(120));

    expect($this->replanner->shouldRun($this->auction->id, $t0->copy()->addSeconds(30)->timestamp))->toBeTrue();
});

it('senza marker non parte nulla: qualcun altro ha già servito quegli eventi', function () {
    expect($this->replanner->shouldRun($this->auction->id, now()->timestamp))->toBeFalse();
});

it('non sovrappone due run e conserva gli eventi non ancora serviti', function () {
    $this->replanner->schedule($this->auction);
    $primo = $this->replanner->launch($this->auction);

    expect($primo)->not->toBeNull()
        ->and(Cache::get(Replanner::markerKey($this->auction->id)))->toBeNull();

    // Arriva un altro acquisto mentre il primo run è ancora in volo.
    $this->replanner->schedule($this->auction);

    expect($this->replanner->launch($this->auction))->toBeNull()
        // Il marker sopravvive: quell'acquisto dovrà pur entrare in un piano.
        ->and(Cache::get(Replanner::markerKey($this->auction->id)))->not->toBeNull();

    Queue::assertPushed(RunClaudeTask::class, 1);
});

it('il job si rimette in coda quando trova un replan già in corso', function () {
    Carbon::setTestNow($t0 = Carbon::parse('2026-08-07 21:00:00'));

    $this->replanner->launch($this->auction, PlanTrigger::Manual);
    $this->replanner->schedule($this->auction);

    Carbon::setTestNow($t0->copy()->addSeconds(25));

    (new ScheduleReplan($this->auction->id, $t0->timestamp))->handle($this->replanner);

    Queue::assertPushed(ScheduleReplan::class, function (ScheduleReplan $job) {
        return $job->requeues === 1;
    });

    // Nessun secondo run: quello di prima non è ancora atterrato.
    Queue::assertPushed(RunClaudeTask::class, 1);
});

it('non fa girare nulla su un\'asta che non è in corso', function () {
    $chiusa = Auction::factory()->closed()->create();

    $this->replanner->schedule($chiusa);

    (new ScheduleReplan($chiusa->id, now()->timestamp))->handle($this->replanner);

    Queue::assertNotPushed(RunClaudeTask::class);

    expect(Plan::query()->where('status', PlanStatus::Generating)->count())->toBe(0);
});

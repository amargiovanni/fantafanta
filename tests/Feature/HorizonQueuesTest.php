<?php

/**
 * Le code non sono un dettaglio di configurazione: sono il contratto di
 * priorità dell'architettura a due velocità. Questi test impediscono che una
 * modifica distratta rimetta il replan dietro un'estrazione segnali.
 */
it('dedica un supervisor esclusivo alla coda ai-replan', function () {
    $supervisors = config('horizon.defaults');

    expect($supervisors)->toHaveKeys(['supervisor-replan', 'supervisor-ai', 'supervisor-general'])
        ->and($supervisors['supervisor-replan']['queue'])->toBe(['ai-replan'])
        ->and($supervisors['supervisor-ai']['queue'])->toBe(['ai'])
        ->and($supervisors['supervisor-general']['queue'])->toBe(['scraping', 'default']);
});

it('copre tutte e quattro le code del progetto senza sovrapposizioni', function () {
    $queues = collect(config('horizon.defaults'))->flatMap(fn (array $s) => $s['queue'])->all();

    expect($queues)->toEqualCanonicalizing(['ai-replan', 'ai', 'scraping', 'default'])
        ->and($queues)->toHaveCount(count(array_unique($queues)));
});

it('lascia al job il tempo di completare `claude -p` prima di essere ucciso', function () {
    $supervisors = config('horizon.defaults');

    // Il processo claude ha timeout 300s: il worker deve dargli margine.
    expect($supervisors['supervisor-ai']['timeout'])->toBeGreaterThan(300)
        ->and($supervisors['supervisor-replan']['timeout'])->toBeGreaterThan(300)
        // retry_after di Redis deve superare il timeout più alto, altrimenti
        // un job vivo verrebbe eseguito una seconda volta in parallelo.
        ->and(config('queue.connections.redis.retry_after'))
        ->toBeGreaterThan(max(array_column($supervisors, 'timeout')));
});

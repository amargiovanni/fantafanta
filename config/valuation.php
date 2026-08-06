<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Motore di valutazione deterministico
    |--------------------------------------------------------------------------
    |
    | Ogni numero del ValuationEngine vive qui e da nessun'altra parte
    | (briefing §5): il motore è una formula parametrica, e i parametri si
    | tarano fra un'asta e l'altra senza toccare il codice né i test.
    |
    | Le costanti sono quelle della specifica algoritmica versionata in
    | docs/superpowers/specs/2026-08-06-valuation-engine.md. Cambiarle qui
    | cambia il comportamento del motore: sono decisioni di dominio, non
    | dettagli di implementazione.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | 1. Base value
    |--------------------------------------------------------------------------
    |
    | Pesi della componente "quanto vale questo giocatore secondo il listone".
    | FVM pesa più della quotazione perché incorpora già il rendimento atteso;
    | le statistiche storiche entrano per un quinto, e solo se ci sono.
    |
    */

    'base' => [
        'weights' => [
            'fvm' => 0.5,
            'quotazione' => 0.3,
            'performance' => 0.2,
        ],

        // perf_norm = clamp((fantamedia − floor) / span, 0, 1) × min(presenze / full, 1)
        'performance' => [
            'fantamedia_floor' => 4.5,
            'fantamedia_span' => 3.0,
            'appearances_full' => 25,
        ],

        // Nessun giocatore vale zero: all'asta si compra anche il tappabuchi.
        'floor' => 1.0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ripartizione teorica del monte crediti per reparto
    |--------------------------------------------------------------------------
    |
    | credit_pool = teams_count × total_credits, diviso per ruolo. Con il
    | modificatore di difesa attivo portiere e difensori pesano di più, perché
    | in quella lega un reparto arretrato solido vale punti quanto un attaccante
    | (briefing §2). È il punto di partenza: la ripartizione finale del piano la
    | decide Claude e la motiva nelle strategy_notes.
    |
    */

    'pool_share' => [
        'with_defense_modifier' => ['P' => 0.09, 'D' => 0.21, 'C' => 0.30, 'A' => 0.40],
        'without_defense_modifier' => ['P' => 0.07, 'D' => 0.17, 'C' => 0.30, 'A' => 0.46],
    ],

    /*
    |--------------------------------------------------------------------------
    | 2. Aggiustamento da segnali
    |--------------------------------------------------------------------------
    |
    | w = impact/2 × confidence × decay. I segnali pre-asta invecchiano piano
    | (45 giorni per esaurirsi, e mai sotto un quarto del peso): a luglio una
    | notizia di giugno conta ancora.
    |
    */

    'signals' => [
        'decay_days' => 45,
        'decay_floor' => 0.25,

        // Nessuna somma di segnali può più che dimezzare o quasi raddoppiare un valore.
        'sum_clamp' => ['min' => -0.6, 'max' => 0.6],

        /*
        | Casi speciali tipizzati: certe notizie non sono "un po' di peso in
        | più o in meno", sono un interruttore. Un infortunio da cinque mesi
        | non rende il giocatore meno appetibile, lo toglie dall'asta.
        */
        'injury' => [
            'days_per_month' => 30,
            'long_months' => 4,
            'long_multiplier' => 0.15,
            'medium_months' => 2,
            'medium_multiplier' => 0.5,

            // Chiavi del payload da cui leggere la durata stimata, in ordine di preferenza.
            'duration_days_keys' => ['stop_stimato_giorni', 'durata_giorni', 'giorni_stop'],
            'duration_months_keys' => ['stop_stimato_mesi', 'durata_mesi', 'mesi_stop'],
        ],

        // Il rigorista di ruolo offensivo porta bonus a ogni giornata: si paga.
        'penalty_taker' => [
            'bonus' => 0.12,
            'roles' => ['C', 'A'],
        ],

        // Chi lascia la Serie A non vale niente in questa asta, per quanto forte sia.
        'market_out' => [
            'min_confidence' => 0.8,
            'value' => 1.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Titolarità attesa
    |--------------------------------------------------------------------------
    |
    | adjusted ×= floor + span × expected_starter. Una riserva vale il 60% di
    | sé stessa titolare: i voti che non prende non li porta.
    |
    */

    'expected_starter' => [
        'floor' => 0.6,
        'span' => 0.4,
    ],

    /*
    |--------------------------------------------------------------------------
    | 3. Modificatori di lega
    |--------------------------------------------------------------------------
    */

    'modifiers' => [
        // Difesa: premia portieri e difensori titolari con media voto alta.
        'defense' => [
            'roles' => ['P', 'D'],
            'min_media_voto' => 6.0,
            'min_expected_starter' => 0.7,
            'base_bonus' => 0.05,
            'step_bonus' => 0.05,
            'step_size' => 0.5,
            'cap' => 0.20,
        ],

        // Fairplay: tie-breaker, mai un driver di scelta (briefing §2).
        'fairplay' => [
            'bookings_per_appearance' => 0.35,
            'multiplier' => 0.97,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 4. Inflazione live
    |--------------------------------------------------------------------------
    |
    | Prezzi pagati contro valore teorico, per ruolo. Serve un minimo di
    | acquisti prima di credere al dato, e l'effetto è smorzato: inseguire i
    | picchi dei primi tre attaccanti è il modo classico di finire i crediti a
    | metà asta.
    |
    */

    'inflation' => [
        'min_acquisitions' => 3,
        'clamp' => ['min' => 0.7, 'max' => 1.6],
        'damping' => 0.7,
    ],

    /*
    |--------------------------------------------------------------------------
    | 5. Scarsità
    |--------------------------------------------------------------------------
    |
    | domanda / offerta nel ruolo, dove la domanda conta solo gli avversari che
    | hanno ancora i crediti per competere. Il bonus si applica ai soli
    | giocatori del piano corrente: pagare la scarsità di chi non ti interessa
    | non ha senso.
    |
    */

    'scarcity' => [
        'tiers' => 5,
        'clamp' => ['min' => 0.5, 'max' => 3.0],
        'max_bid_bonus_factor' => 0.1,
    ],

    /*
    |--------------------------------------------------------------------------
    | 6. max_bid
    |--------------------------------------------------------------------------
    |
    | Il tetto invalicabile è aritmetico, non strategico: ogni slot ancora
    | aperto costa almeno un credito, quindi non posso mai offrire più di
    | crediti_residui − (slot_aperti − 1) (briefing §5.6).
    |
    */

    'max_bid' => [
        'min_slot_cost' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Chiavi delle statistiche di stagione
    |--------------------------------------------------------------------------
    |
    | `season_stats` conserva le colonne del CSV di fantacalcio.it così come si
    | chiamano nel file (Pv, Mv, Fm, Am...), perché il mapping dell'import è
    | configurabile e il formato cambia fra una stagione e l'altra (briefing
    | §10). Il motore cerca la prima chiave che trova, confronto senza
    | distinzione di maiuscole.
    |
    */

    'stats_keys' => [
        'fantamedia' => ['Fm', 'fantamedia', 'fm'],
        'media_voto' => ['Mv', 'media_voto', 'mv'],
        'appearances' => ['Pv', 'presenze', 'pv'],
        'bookings' => ['Am', 'ammonizioni', 'am'],
    ],

];

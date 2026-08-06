<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Integrazione Claude Code headless
    |--------------------------------------------------------------------------
    |
    | L'AI è raggiunta esclusivamente tramite il binario `claude` in modalità
    | headless, mai via API: la sottoscrizione è già attiva sulla macchina e
    | nel progetto non esiste nessuna chiave Anthropic (briefing §11).
    |
    | `binary` è un percorso assoluto perché il worker di coda non eredita il
    | PATH interattivo della shell dell'utente.
    |
    */

    'claude' => [
        'binary' => env('CLAUDE_BINARY', env('HOME').'/.local/bin/claude'),
        'timeout' => (int) env('CLAUDE_TIMEOUT', 300),
        'max_turns' => (int) env('CLAUDE_MAX_TURNS', 30),
        'allowed_tools' => env('CLAUDE_ALLOWED_TOOLS', 'mcp__fanta-asta__*'),
        'mcp_config' => env('CLAUDE_MCP_CONFIG', '.mcp.json'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Replanning (briefing §7.3)
    |--------------------------------------------------------------------------
    |
    | Ogni aggiudicazione rende il piano vecchio, ma un run per acquisto
    | significherebbe tre run mentre il banditore è già al nome dopo. Il replan
    | parte quindi in coda al silenzio: `debounce` secondi dopo l'ULTIMA
    | aggiudicazione.
    |
    | `max_wait` è il contrappeso: in una fase di asta concitata gli acquisti
    | possono susseguirsi a meno di venti secondi l'uno dall'altro per minuti,
    | e un debounce puro non partirebbe mai. Trascorsi `max_wait` secondi dal
    | primo evento non ancora pianificato il run parte comunque, anche se la
    | raffica continua.
    |
    */

    'replan' => [
        'debounce' => (int) env('FANTA_REPLAN_DEBOUNCE', 20),
        'max_wait' => (int) env('FANTA_REPLAN_MAX_WAIT', 90),
        'queue' => env('FANTA_REPLAN_QUEUE', 'ai-replan'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Server MCP
    |--------------------------------------------------------------------------
    |
    | URL con cui il processo `claude` raggiunge l'app. Serve all'healthcheck;
    | il valore effettivamente usato dal CLI è quello scritto in .mcp.json.
    |
    */

    'mcp_url' => env('MCP_URL', env('APP_URL', 'https://fanta-asta.test').'/mcp'),

    /*
    |--------------------------------------------------------------------------
    | Servizi verificati dall'healthcheck
    |--------------------------------------------------------------------------
    */

    'meilisearch_health_url' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700').'/health',

    /*
    |--------------------------------------------------------------------------
    | Scraping (briefing §7.4, spec Fase 4)
    |--------------------------------------------------------------------------
    |
    | Etica e robustezza non sono opzionali: user-agent identificato, rispetto
    | di robots.txt, un solo articolo ogni `rate_limit_seconds` per dominio.
    | `backoff_ms` è la sequenza di attesa su 429/5xx (il numero di elementi è
    | anche il numero di ritentativi); esaurita la sequenza il fallimento
    | conta per il circuit breaker del target.
    |
    | `max_extractions_per_scrape` è il tetto di spesa: ogni articolo nuovo è
    | un run Claude a pagamento. Non si applica alle fonti caricate a mano.
    |
    */

    'scraping' => [
        'queue' => env('FANTA_SCRAPING_QUEUE', 'scraping'),
        'user_agent' => env('FANTA_SCRAPE_USER_AGENT', 'FantaAstaBot/1.0 (+uso personale, contatto locale)'),

        'schedule_interval_minutes' => (int) env('FANTA_SCRAPE_INTERVAL_MINUTES', 30),

        'rate_limit_seconds' => (int) env('FANTA_SCRAPE_RATE_LIMIT_SECONDS', 2),
        'backoff_ms' => [30000, 120000],

        'robots_cache_hours' => (int) env('FANTA_SCRAPE_ROBOTS_CACHE_HOURS', 24),

        'circuit_breaker_threshold' => (int) env('FANTA_SCRAPE_CIRCUIT_THRESHOLD', 5),
        'circuit_breaker_cooldown_minutes' => (int) env('FANTA_SCRAPE_CIRCUIT_COOLDOWN_MINUTES', 30),

        'dedup_window_days' => (int) env('FANTA_SCRAPE_DEDUP_WINDOW_DAYS', 7),
        'title_similarity_threshold' => (float) env('FANTA_SCRAPE_TITLE_SIMILARITY', 85.0),

        'max_extractions_per_scrape' => (int) env('FANTA_MAX_EXTRACTIONS_PER_SCRAPE', 20),

        'full_scrape' => [
            'window_days' => (int) env('FANTA_FULL_SCRAPE_WINDOW_DAYS', 7),
            'html_pages' => (int) env('FANTA_FULL_SCRAPE_HTML_PAGES', 3),
        ],

        // Parser dedicato per una testata specifica, chiave = scrape_targets.id,
        // valore = class-string di un TargetParser. Vuoto finché non serve.
        'parser_overrides' => [],
    ],

];

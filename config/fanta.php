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

];

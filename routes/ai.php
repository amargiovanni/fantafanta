<?php

use App\Mcp\Servers\FantaAstaServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| Server MCP
|--------------------------------------------------------------------------
|
| Il server è esposto senza middleware di autenticazione: l'app è single
| tenant e gira solo su Herd in locale (briefing §1). L'unico client è il
| processo `claude -p` lanciato dai job dell'app stessa, configurato in
| .mcp.json nel root del progetto.
|
*/

Mcp::web('/mcp', FantaAstaServer::class);

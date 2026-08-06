<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetPlayerTool;
use App\Mcp\Tools\GetSignalsTool;
use App\Mcp\Tools\ResolvePlayerNameTool;
use App\Mcp\Tools\SaveSignalsTool;
use App\Mcp\Tools\SearchPlayerTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

/**
 * Server MCP dell'applicazione: è il solo canale attraverso cui Claude Code
 * legge e scrive i dati di Fanta Asta.
 *
 * Nessun tool si fida dell'input: la validazione sta qui, non nel prompt.
 */
#[Name('fanta-asta')]
#[Version('1.0.0')]
#[Instructions(<<<'TXT'
Server dei dati di Fanta Asta AI, l'applicazione che prepara e conduce l'asta
del fantacalcio (regolamento Classic, lega italiana).

Cosa puoi fare in questa fase:
- cercare e identificare i giocatori del listone ufficiale (search_player, get_player);
- leggere i segnali già raccolti (get_signals);
- risolvere un nome grezzo trovato in un articolo nel giocatore canonico (resolve_player_name);
- scrivere i segnali estratti dalle fonti (save_signals).

Regole del server, applicate lato server e non negoziabili:
- un segnale senza player_id deve dichiarare needs_review=true e riportare
  raw_name, il nome esatto trovato nel testo. Non esistono segnali orfani muti;
- confidence sta fra 0 e 1, impact è un intero fra -2 e +2, type appartiene
  all'enum documentato nel tool;
- un batch che contiene anche un solo segnale invalido viene rifiutato per
  intero, con l'elenco puntuale degli errori: correggi e richiama il tool;
- non duplicare: se lo stesso segnale esiste già da altra fonte il server lo
  corrobora da sé alzandone la confidence.
TXT)]
class FantaAstaServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        SearchPlayerTool::class,
        GetPlayerTool::class,
        GetSignalsTool::class,
        SaveSignalsTool::class,
        ResolvePlayerNameTool::class,
    ];
}

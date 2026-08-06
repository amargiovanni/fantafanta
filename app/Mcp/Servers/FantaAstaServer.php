<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetAuctionLogTool;
use App\Mcp\Tools\GetAvailablePlayersTool;
use App\Mcp\Tools\GetBudgetAnalysisTool;
use App\Mcp\Tools\GetCurrentPlanTool;
use App\Mcp\Tools\GetLeagueStateTool;
use App\Mcp\Tools\GetPlayerTool;
use App\Mcp\Tools\GetSignalsTool;
use App\Mcp\Tools\ResolvePlayerNameTool;
use App\Mcp\Tools\SavePlanTool;
use App\Mcp\Tools\SaveSignalsTool;
use App\Mcp\Tools\SearchPlayerTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;

/**
 * Server MCP dell'applicazione: è il solo canale attraverso cui Claude Code
 * legge e scrive i dati di Fanta Asta.
 *
 * Nessun tool si fida dell'input: la validazione sta qui, non nel prompt.
 */
#[Name('fanta-asta')]
#[Version('2.0.0')]
#[Instructions(<<<'TXT'
Server dei dati di Fanta Asta AI, l'applicazione che prepara e conduce l'asta
del fantacalcio (regolamento Classic, lega italiana).

Cosa puoi fare:
- cercare e identificare i giocatori del listone ufficiale (search_player,
  get_player, resolve_player_name);
- leggere e scrivere i segnali raccolti dalle fonti (get_signals, save_signals);
- leggere lo stato della lega e del mercato (get_league_state,
  get_available_players, get_budget_analysis, get_auction_log);
- leggere il piano d'acquisto corrente (get_current_plan) e scriverne una
  nuova versione (save_plan).

Regole del server, applicate lato server e non negoziabili:
- un segnale senza player_id deve dichiarare needs_review=true e riportare
  raw_name, il nome esatto trovato nel testo. Non esistono segnali orfani muti;
- confidence sta fra 0 e 1, impact è un intero fra -2 e +2, type appartiene
  all'enum documentato nel tool;
- un batch che contiene anche un solo segnale invalido viene rifiutato per
  intero, con l'elenco puntuale degli errori: correggi e richiama il tool;
- non duplicare: se lo stesso segnale esiste già da altra fonte il server lo
  corrobora da sé alzandone la confidence;
- il piano viene accettato solo se è completo e coerente: 25 slot con i
  conteggi per ruolo della lega, nessun giocatore titolare due volte, nessun
  giocatore già venduto ad altri, ruoli corrispondenti, almeno due alternative
  per ogni slot ancora aperto e somma dei target_price entro i crediti
  residui. Se qualcosa non torna il tool rifiuta TUTTO il piano e ti restituisce
  l'elenco completo delle violazioni: correggile tutte insieme e richiama
  save_plan una volta sola.

Le valutazioni (adjusted_value, max_bid, tier, scarcity_index) sono calcolate
da un motore deterministico in PHP a ogni segnale e a ogni aggiudicazione: sono
un dato, non una tua stima, e non si ricalcolano a mano. Il tuo lavoro è
decidere cosa farne.
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
        GetLeagueStateTool::class,
        GetAvailablePlayersTool::class,
        GetBudgetAnalysisTool::class,
        GetCurrentPlanTool::class,
        GetAuctionLogTool::class,
        SavePlanTool::class,
    ];

    /**
     * Quanti tool il server dichiara di esporre.
     *
     * Serve all'healthcheck: confrontare questo numero con quelli che il
     * server risponde davvero via HTTP è l'unico modo di accorgersi che una
     * registrazione è saltata prima che lo scopra un run headless.
     */
    public static function declaredToolCount(): int
    {
        // Il costruttore del server vuole un transport, che qui non serve e
        // non esiste: il valore di default della proprietà si legge così.
        $tools = (new ReflectionClass(static::class))->getDefaultProperties()['tools'] ?? [];

        return count($tools);
    }
}

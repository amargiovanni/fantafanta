# Fanta Asta AI

Assistente per l'asta del fantacalcio: un listone che diventa un piano d'acquisto
con priorità, prezzi obiettivo e alternative, aggiornato in tempo reale mentre
l'asta procede. Zero ricerca manuale durante l'asta: quando un nome viene
battuto, la sala mostra subito quanto vale e cosa fare.

Questo file è il manuale operativo del progetto: chi arriva da zero trova qui
come installarlo, farlo girare e cosa controllare la sera dell'asta. La
documentazione di dominio (regole, motore di valutazione, architettura) sta in
`briefing.md` e nelle ADR sotto `docs/adr/`.

## Indice

- [Com'è fatta](#comè-fatta)
- [Prerequisiti](#prerequisiti)
- [Setup da zero](#setup-da-zero)
- [Avvio](#avvio)
- [Comandi utili](#comandi-utili)
- [Runbook del giorno dell'asta](#runbook-del-giorno-dellasta)
- [Costi e limiti di Claude](#costi-e-limiti-di-claude)
- [Test e qualità](#test-e-qualità)
- [Troubleshooting](#troubleshooting)

## Com'è fatta

Architettura a due velocità (ADR 0002): tutto quello che serve durante l'asta
— valutazioni, prezzo massimo, promozione dell'alternativa quando un
avversario batte il titolare — è **PHP puro, sincrono, senza AI**. L'AI
(`claude` in modalità headless, mai via API — niente chiavi Anthropic nel
progetto) entra solo per generare o ripianificare il piano d'acquisto, sempre
in coda, mai nel percorso caldo della sala.

Stack: Laravel 13, Livewire 4, Tailwind 4, Pest 4, Horizon (code Redis),
Meilisearch (ricerca fuzzy dei giocatori), SQLite (unico motore di database
usato dal progetto — vedi ADR 0004 per i limiti di questa scelta se un giorno
si migra a MySQL).

Tre aree, tre URL:

| Area | Rotta | A cosa serve |
|---|---|---|
| Dashboard | `/` | Stato dell'asta, salute della pipeline, genera/ricalcola il piano |
| Listone | `/listone`, `/listone/import` | Anagrafica giocatori, import CSV |
| Lega | `/lega` | Crediti, squadre, modificatori di regolamento |
| Conoscenza | `/conoscenza`, `/conoscenza/revisione`, `/conoscenza/segnali`, `/conoscenza/testate` | Fonti, segnali estratti, revisione, testate monitorate |
| **Sala d'asta** | `/asta` | La schermata che conta: cerca il nome, vede il prezzo, assegna |

## Prerequisiti

- **[Herd](https://herd.laravel.com/)** — il progetto è servito su
  `https://fanta-asta.test`. Herd gestisce PHP e Nginx: non serve
  `php artisan serve` in sviluppo locale.
- **PHP 8.4+**, **Composer** (via Herd).
- **Node 20+** e **npm** (per Vite/Tailwind).
- **Redis** — code (Horizon) e cache dei marker di debounce.
  ```bash
  brew services start redis
  ```
- **Meilisearch** — ricerca fuzzy dei giocatori nella sala d'asta.
  ```bash
  brew services start meilisearch
  ```
- **Claude Code CLI** (`claude`), autenticato con una sessione valida sulla
  macchina. Il percorso del binario è in `CLAUDE_BINARY` (default
  `~/.local/bin/claude`). Senza una sessione autenticata, ogni run — piano
  iniziale o replan — fallisce e la riga `plans` finisce in `failed`.

## Setup da zero

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configura `.env` (i valori di sviluppo tipici, coerenti con Herd):

```
APP_URL=https://fanta-asta.test
DB_CONNECTION=sqlite
QUEUE_CONNECTION=redis
CACHE_STORE=database
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://127.0.0.1:7700
```

```bash
touch database/database.sqlite
php artisan migrate
php artisan db:seed          # testate di scraping precaricate
npm install
npm run build
php artisan scout:sync-index-settings   # sincronizza gli indici Meilisearch
```

Verifica che tutto risponda:

```bash
php artisan ai:healthcheck
```

## Avvio

In sviluppo, tre processi indipendenti oltre a Herd (che serve già le
richieste HTTP):

```bash
php artisan horizon          # worker delle code (replan, ricalcolo, scraping, AI)
php artisan schedule:work    # scheduler (scraping periodico, briefing §7.4)
npm run dev                  # Vite, solo mentre si tocca resources/css o resources/js
```

Per il lavoro quotidiano su CSS/JS senza hot reload, `npm run build` basta.

`composer run dev` avvia server PHP integrato + coda + log + Vite tutti
insieme — comodo se non si usa Herd, ma **su questo progetto si usa Herd**:
preferire `php artisan horizon` + `php artisan schedule:work` separati, così
Horizon resta visibile e riavviabile senza toccare il resto.

Horizon ha una dashboard propria su `https://fanta-asta.test/horizon`, utile
per vedere code, throughput e job falliti a colpo d'occhio.

## Comandi utili

| Comando | Cosa fa |
|---|---|
| `php artisan ai:healthcheck` | Verifica claude CLI, Redis, Meilisearch, server MCP. Da lanciare prima di ogni asta. |
| `php artisan asta:simulate` | Simula un'asta senza claude reale (di default): estrae giocatori a caso pesati per fascia, simula offerte avversarie, registra acquisizioni vere tramite lo stesso funnel della sala. Vedi `--help` per le opzioni; usato per collaudare la sala e il replan senza aspettare un'asta vera. |
| `php artisan horizon:status` | Il master supervisor è in esecuzione? |
| `php artisan queue:monitor redis:default,redis:ai,redis:ai-replan,redis:scraping` | Quanti job sono in coda su ciascuna coda, per accorgersi di un accumulo. |
| `php artisan schedule:list` | Cosa è schedulato e quando gira di nuovo. |
| `php artisan db:seed --class=ScrapeTargetSeeder` | Ricarica le testate di scraping monitorate (idempotente, usa `updateOrCreate`). |

## Runbook del giorno dell'asta

Da fare **prima** che l'asta cominci, in ordine:

1. **Servizi su.**
   ```bash
   brew services list | grep -E "redis|meilisearch"
   ```
   Entrambi `started`. Herd già serve l'app.

2. **Horizon attivo**, in un terminale dedicato che resta aperto per tutta
   l'asta:
   ```bash
   php artisan horizon
   ```
   Senza Horizon, gli acquisti si registrano comunque (la sala scrive
   direttamente su `acquisitions`) ma **il replan non parte mai**: il piano
   resta fermo alla versione con cui si è iniziato.

3. **Healthcheck.**
   ```bash
   php artisan ai:healthcheck
   ```
   Tutti e quattro i servizi (claude, Redis, Meilisearch, MCP) devono
   rispondere OK. Un KO su `claude` di solito significa sessione scaduta:
   riautenticarsi prima, non durante l'asta.

4. **Listone aggiornato.** Da `/listone/import`, l'ultimo export CSV di
   fantacalcio.it della settimana. Controllare il conteggio giocatori
   importati contro l'anteprima.

5. **Scrape completo** (facoltativo ma consigliato la sera stessa, per
   catturare le ultime notizie di formazione/infortuni):
   - da `/conoscenza/testate`, bottone "Scrape completo" su ciascuna testata
     abilitata, oppure lasciare che lo scheduler (se `schedule:work` è
     attivo) lo faccia da solo entro `schedule_interval_minutes` (default 30).
   - da `/conoscenza/revisione`, smaltire la coda di segnali che richiedono
     conferma manuale (soglia di confidenza sotto la quale non si assegna
     mai in automatico, briefing §10).

6. **Genera il piano** dalla dashboard (`/`), bottone "Genera piano". Aspetta
   che compaia (badge "generazione in corso" mentre gira): è un run reale di
   Claude, non istantaneo. Se fallisce, la riga `plans` passa a `failed` e il
   bottone torna disponibile — leggere l'errore in `ai_runs` prima di
   rilanciare.

7. **Apri `/asta`** e lascia la scheda aperta per tutta la durata. La sala
   fa il resto: cerca il nome, vede il prezzo (`max_bid` in evidenza),
   registra chi ha vinto. Ogni acquisto ricalcola le valutazioni (debounced,
   ADR 0004) e programma un replan (debounced, 20s di silenzio o 90s di
   attesa massima — ADR 0005).

Durante l'asta, se qualcosa sembra fermo: guardare Horizon (`/horizon`)
prima di sospettare un bug — un replan "in corso" da più di un minuto con
Horizon fermo è quasi sempre la causa, non il sintomo.

## Costi e limiti di Claude

Ogni generazione di piano o replan è un run reale di `claude -p`, a
consumo/limite della sottoscrizione attiva sulla macchina — non c'è
simulazione economica lato Laravel. Punti da tenere a mente:

- **Il debounce del replan (20s, tetto 90s) esiste apposta per non
  moltiplicare i run** durante una raffica di acquisti: un'asta con
  aggiudicazioni ravvicinate produce comunque un run per "pausa nel
  silenzio", non uno per acquisto.
- **Lo scraping ha un tetto per giro** (`max_extractions_per_scrape`, default
  20): ogni articolo nuovo scoperto diventa un run Claude per l'estrazione
  segnali. Il tetto non vale per le fonti caricate a mano da `/conoscenza`.
- **`asta:simulate` di default non chiama mai claude reale** — serve
  `--replan` esplicito per farlo, e va usato con Horizon attivo e
  consapevolezza di quanti run si stanno per generare (vedi la sezione
  comandi sopra).
- **`CLAUDE_TIMEOUT`** (default 300s) e **`CLAUDE_MAX_TURNS`** (default 30)
  in `config/fanta.php` sono i limiti per singolo run: un piano che non
  finisce nei turni concessi va in `failed`, non resta appeso.

## Test e qualità

```bash
php artisan test          # suite Pest completa — nessuna rete reale, nessuna chiamata a claude
./vendor/bin/pint --test  # stile PHP, nessuna modifica (togliere --test per applicarlo)
npm run build             # obbligatorio dopo ogni modifica a resources/css o resources/js
```

Nessun test della suite chiama la rete o `claude` per davvero — `Process`,
`Http` e `Queue` sono sempre finti nei test che li toccano.

## Troubleshooting

**Dashboard mostra "Claude Code: KO".**
`php artisan ai:healthcheck` per il dettaglio. Cause tipiche: sessione
`claude` scaduta (riautenticarsi da terminale), binario non nel percorso
configurato (`CLAUDE_BINARY`), o il CLI non installato sulla macchina che
esegue i worker (se Horizon gira altrove rispetto a dove si è lanciato
l'healthcheck, controllare *quella* macchina).

**Una testata di scraping non produce più segnali (feed morto).**
Un feed RSS può rispondere 200 e non essere morto nel senso HTTP — solo
fermo nel contenuto (è successo con Gazzetta dello Sport, fermo dal 2023
pur rispondendo 200: vedi `database/seeders/ScrapeTargetSeeder.php`). Per
verificare un feed a mano:
```bash
curl -sL "https://esempio.it/feed" | grep -o "<pubDate>[^<]*</pubDate>" | head -3
```
Se le date sono vecchie, azzerare `rss_url` per quel target da
`/conoscenza/testate` (o nel seeder se è un target di base): `ParserRegistry`
passa automaticamente a `HtmlListParser`, che fa crawl della pagina invece
del feed.

**Il replan non parte mai / resta "in corso" per sempre.**
1. `php artisan horizon:status` — se il master supervisor non è in
   esecuzione, i job restano in coda e invecchiano senza essere eseguiti:
   `php artisan horizon`.
2. Se Horizon è su ma il piano resta `generating` da minuti, controllare
   `ai_runs` per l'ultimo run di quel task: un `failed` con errore leggibile
   di solito indica una sessione `claude` scaduta a metà asta.
3. Un replan bloccato non impedisce comunque di registrare acquisti: la
   promozione dell'alternativa (rete di sicurezza deterministica, ADR 0005)
   funziona anche senza un replan riuscito.

**Il listone importato ha meno giocatori del previsto.**
Il mapping colonne del CSV è confermato manualmente in fase di import
(`/listone/import`, briefing §10: "il formato del CSV di fantacalcio.it
cambia"). Controllare l'anteprima prima di confermare, non dopo.

**`npm run build` non aggiorna nulla in pagina.**
Herd serve i file compilati da `public/build`: un `npm run build` mancante
dopo una modifica a `resources/css/app.css` o `resources/js/app.js` lascia
la pagina con l'asset vecchio. Non serve riavviare Herd, basta ricompilare.

# Fanta Asta AI — Briefing di progetto

> Documento operativo per gli agenti di sviluppo. Versione 1.0 — Agosto 2026.
> Single tenant, single user (Andrea). Ambiente: macOS + Laravel Herd.

---

## 1. Visione

Un'applicazione Laravel che accompagna Andrea in tutte le fasi dell'asta del fantacalcio (regolamento Classic):

1. **Prima dell'asta**: accumula conoscenza (link, PDF, documenti, note, scraping delle testate di settore), la trasforma in **segnali strutturati per giocatore** tramite AI, e produce un **piano d'acquisto completo** — la rosa obiettivo di 25 giocatori con prezzo target accanto a ogni nome.
2. **Durante l'asta**: registrazione fulminea di ogni aggiudicazione (giocatore, prezzo, squadra acquirente) e **ricalcolo automatico del piano** dopo ogni evento. Il piano non è mai un "consiglio": è sempre la rosa aggiornata da comprare, con l'investimento previsto per ciascun nome.
3. **Sempre**: risposta istantanea alla domanda "quanto vale per me il giocatore appena estratto?", perché l'asta è **random** (ordine di estrazione deciso dal sistema fantacalcio.it, non a chiamata) e ogni nome può arrivare in qualunque momento.

### Principio architetturale cardine: due velocità

- **Livello deterministico (istantaneo, PHP puro)**: valutazioni, prezzi massimi, tier, inflazione, scarsità per ruolo. Sempre pre-calcolato, consultabile in <50ms. Nessuna chiamata AI nel percorso critico dell'asta.
- **Livello strategico (asincrono, Claude Code headless)**: estrazione segnali dalle fonti, generazione e ricalcolo del piano. Gira in coda, il risultato appare in UI appena pronto.

Se un agente si trova a mettere una chiamata AI in un percorso sincrono della UI d'asta, sta sbagliando: fermarsi e ripensare.

### Non-goals espliciti (v1)

- **Niente gestione stagione**: formazioni settimanali, mercato di riparazione, svincoli, live match. Solo asta.
- **Niente multi-utente / multi-tenant**: un solo utente, nessuna auth complessa (basta un login locale minimale o nessuno, l'app gira su Herd in locale).
- **Niente asta a chiamata o a busta chiusa**: solo modalità random fantacalcio.it.
- **Niente app mobile nativa**: web responsive (l'asta si segue da MacBook, il resto anche da telefono).
- **Niente fonti dati oltre fantacalcio.it** per quotazioni/statistiche: il listone CSV è l'unica base statistica ufficiale. Le news arrivano dallo scraping e dai caricamenti manuali.

---

## 2. Regole della lega (configurazione, non hardcoded)

Memorizzate in `league_config` (tabella singleton, editabile da backoffice):

| Parametro | Valore default |
|---|---|
| Modalità | Classic |
| Slot rosa | 3 P / 8 D / 8 C / 6 A (25 giocatori) |
| Crediti iniziali | impostati da Andrea al setup (input, non fisso) |
| Numero squadre | impostato al setup; le squadre si registrano PRIMA dell'asta |
| Modificatore difesa | **SÌ** |
| Modificatore fairplay | **SÌ** |
| Tipo asta | Random (estrazione casuale fantacalcio.it) |

### Implicazioni strategiche dei modificatori (da incorporare nel reasoning)

- **Modificatore difesa**: premia la media voto dei difensori schierati (portiere + almeno 4 difensori). Conseguenze: (a) i difensori *titolari fissi* con buona media voto valgono più della loro quotazione nominale; (b) ha senso concentrare difensori della stessa squadra reale con buona fase difensiva; (c) il portiere titolare di una big vale un investimento superiore alla norma; (d) il budget difesa va alzato rispetto a una lega senza modificatore.
- **Modificatore fairplay**: bonus alle squadre senza cartellini/malus disciplinari. Impatto minore, ma a parità di valutazione va preferito il giocatore disciplinato (pochi gialli storici). È un tie-breaker, non un driver.
- **Asta random**: non esiste "strategia di chiamata". Ogni giocatore del listone deve avere SEMPRE un prezzo massimo consigliato pre-calcolato. Il piano deve prevedere alternative per ogni slot, perché l'ordine di estrazione è imprevedibile e un target può arrivare quando il budget è già impegnato altrove.

---

## 3. Stack tecnico

| Componente | Scelta | Note |
|---|---|---|
| Framework | Laravel 12 su Herd | dominio locale `fanta-asta.test` |
| DB | SQLite (default) o MySQL via Herd Pro | single tenant: SQLite è sufficiente; usare comunque migration standard così il passaggio è indolore |
| Code | Laravel Horizon + Redis | Redis via Herd; tutte le pipeline AI e scraping girano in coda |
| Ricerca fuzzy | Laravel Scout + Meilisearch | indispensabile per il matching nomi (ricerca in-asta e normalizzazione alias) |
| Frontend | Livewire 3 + Alpine + Tailwind | reattività senza SPA; la schermata d'asta è un componente Livewire con polling |
| Realtime | Polling Livewire (3s) sulla versione del piano | Reverb è opzionale in v2; il polling è più che sufficiente e più robusto |
| AI reasoning | **Claude Code headless** (`claude -p`) | sottoscrizione premium già attiva sul Mac; NESSUNA chiave API Anthropic nel progetto |
| MCP server | Package MCP ufficiale Laravel | l'app espone i propri dati come tool MCP; Claude Code li consuma |
| Scraping | Guzzle + readability/DomCrawler; feed RSS dove disponibili | rispettare rate limit; dedup obbligatoria |
| PDF/doc parsing | `smalot/pdfparser` per PDF testuali; fallback estrazione via Claude (il PDF va convertito in testo prima del prompt) | |
| Test | Pest | copertura obbligatoria su motore valutazione, import CSV, matching nomi |

### Integrazione Claude Code headless — contratto

Claude NON viene chiamato via API. Laravel lancia un processo:

```bash
claude -p "$(cat prompt.md)" \
  --output-format json \
  --max-turns 30 \
  --allowedTools "mcp__fanta-asta__*" \
  --mcp-config .mcp.json
```

- `.mcp.json` nel root del progetto punta al server MCP dell'app stessa (`http://fanta-asta.test/mcp`).
- Ogni esecuzione è incapsulata in un Job Laravel (`RunClaudeTask`) con: timeout generoso (300s), retry 1, log completo in tabella `ai_runs` (prompt usato, output raw, esito, durata, token se disponibili).
- I **prompt sono file versionati** in `resources/prompts/*.md` (es. `extract-signals.md`, `generate-plan.md`, `replan.md`). Mai prompt inline nel codice. Ogni modifica ai prompt passa da commit: è sviluppo guidato da specifiche, i prompt SONO specifiche.
- L'output di Claude per i task strutturati deve essere JSON puro (istruito nel prompt); il job lo valida contro uno schema prima di persistere. Output non valido → retry con messaggio d'errore in appendice, poi fail visibile in backoffice.
- Claude scrive i risultati tramite i tool MCP di scrittura (`save_signals`, `save_plan`), NON tramite parsing dell'output da parte di Laravel, quando possibile. Il JSON di output serve da fallback e audit.

---

## 4. Modello dati

Tutte le tabelle con timestamp standard. Nomi in inglese, commenti in italiano nelle migration.

### Anagrafica e listone

- **`players`** — entità canonica. Campi: `name`, `normalized_name`, `role` (P/D/C/A), `real_team`, `quotazione` (Qt.A classic), `fvm`, `season_stats` (JSON: fantamedia, media voto, presenze, gol, assist, ammonizioni, espulsioni, rigori — tutto ciò che il CSV fantacalcio.it fornisce, anche multi-stagione se disponibile), `status` (available / acquired / removed), flag `is_rigorista`, `expected_starter` (0–1). Indice Meilisearch.
- **`player_aliases`** — `player_id`, `alias`. Popolata: (a) automaticamente all'import (cognome solo, nome+iniziale, senza accenti/apostrofi); (b) dall'AI durante l'estrazione segnali quando risolve un nome nuovo; (c) manualmente da backoffice.
- **`league_config`** — singleton, vedi §2.
- **`teams`** — le squadre della lega, registrate prima dell'asta. `name`, `is_mine` (bool, una sola), `credits_total`, `credits_spent` (calcolato).

### Conoscenza e segnali

- **`sources`** — ogni cosa che entra: `type` (link / pdf / doc / note / scraped_article), `title`, `url`, `raw_content` (testo estratto), `content_hash` (dedup), `origin` (manual / scheduled_scrape / full_scrape), `processed_at`.
- **`scrape_targets`** — testate da monitorare: `name`, `url`, `rss_url` (nullable), `enabled`, `last_scraped_at`. Seed iniziale con le principali testate fantacalcistiche italiane; Andrea può aggiungerne da backoffice.
- **`signals`** — il cuore della conoscenza. `player_id`, `type` (enum: `infortunio`, `rientro`, `squalifica`, `rigorista`, `ballottaggio`, `titolarita`, `mercato_in`, `mercato_out`, `cambio_modulo`, `forma`, `altro`), `payload` (JSON: dettagli tipizzati, es. durata stimata infortunio), `confidence` (0–1), `impact` (-2..+2 sull'appetibilità), `source_id`, `event_date`, `superseded_by` (nullable: un rientro supera un infortunio). L'AI è l'unica scrittrice; il backoffice permette correzione/cancellazione manuale.

### Asta e piano

- **`auctions`** — sessione d'asta: `name`, `status` (setup / live / closed), `started_at`.
- **`acquisitions`** — ogni aggiudicazione: `auction_id`, `player_id`, `team_id`, `price`, `created_at`. Il trigger di replanning parte da qui. Undo = soft delete con ripristino stato giocatore.
- **`plans`** — versionato, append-only: `auction_id`, `version`, `trigger` (initial / acquisition / manual), `status` (generating / ready / failed), `strategy_notes` (testo breve di Claude: 2-3 righe sul razionale della versione), `budget_summary` (JSON per reparto).
- **`plan_slots`** — 25 righe per piano: `plan_id`, `role`, `slot_index`, `player_id`, `target_price`, `max_price`, `alternatives` (JSON: array di {player_id, target_price} in ordine di preferenza, min 2 per slot), `slot_status` (pending / acquired / lost). "Acquired" = quel giocatore è già mio; "lost" = preso da altri, lo slot mostra la prima alternativa promossa.
- **`valuations`** — output del motore deterministico, ricalcolato a ogni evento: `player_id`, `base_value`, `adjusted_value` (con segnali e modificatori), `max_bid` (prezzo massimo consigliato dato budget e piano correnti), `tier` (1–5), `scarcity_index` (quanto è scarso il suo profilo nel ruolo), `computed_at`. È la tabella che risponde in <50ms quando un nome viene estratto.
- **`ai_runs`** — audit di ogni esecuzione Claude: `task`, `prompt_file`, `prompt_hash`, `status`, `duration_ms`, `output_raw`, `error`.

---

## 5. Motore di valutazione deterministico (PHP, nessuna AI)

Servizio `ValuationEngine`, ricalcolo completo in coda dopo: import CSV, nuovo segnale, ogni aggiudicazione, modifica config. Deve completare in pochi secondi per l'intero listone.

Componenti del calcolo, in ordine:

1. **Base value** — da quotazione, FVM e statistiche storiche del CSV, normalizzato sul budget della lega (i crediti totali della lega ÷ crediti "teorici" del listone danno il fattore di scala).
2. **Aggiustamento segnali** — somma pesata degli `impact` dei segnali attivi (non superseded), pesati per `confidence` e decadimento temporale. Un infortunio lungo azzera quasi il valore; un nuovo rigorista lo alza sensibilmente.
3. **Aggiustamento modificatori** — bonus difensori/portieri titolari con buona media voto (mod. difesa); malus lieve ai cartellinati cronici (mod. fairplay).
4. **Inflazione live** — durante l'asta: crediti effettivamente spesi vs valore teorico dei giocatori già assegnati → fattore d'inflazione per ruolo. Se le big degli attaccanti stanno andando a +30% del previsto, i `max_bid` degli attaccanti restanti si aggiornano.
5. **Scarsità** — quanti giocatori di pari tier restano disponibili nel ruolo vs quanti slot aperti hanno le squadre avversarie in quel ruolo. Scarsità alta → `max_bid` sale per i target del piano.
6. **Vincolo di budget** — `max_bid` di ogni giocatore non può mai superare: crediti residui − (slot ancora da riempire − 1), perché ogni slot costa almeno 1 credito.

Il motore è testato con Pest su casi noti (fixture di listone ridotto). È la parte più critica del progetto dopo la UX d'asta.

---

## 6. Server MCP (Laravel)

Namespace tool: `fanta-asta`. Tool esposti (tutti read salvo indicato):

| Tool | Descrizione |
|---|---|
| `get_league_state` | config lega, squadre, crediti residui di tutti, slot aperti per ruolo per squadra |
| `get_available_players` | filtri: ruolo, tier, min/max valutazione, ordinamento; include valuation corrente |
| `get_player` | scheda completa: stats, segnali attivi con fonte e data, valuation, storia prezzi in asta |
| `search_player` | ricerca fuzzy per nome/alias, ritorna candidati con score |
| `get_signals` | segnali recenti, filtrabili per tipo/giocatore/data |
| `get_current_plan` | ultima versione ready del piano con stato slot |
| `get_auction_log` | acquisizioni in ordine cronologico, con squadra e prezzo vs valutazione |
| `get_budget_analysis` | inflazione per ruolo, spesa media, crediti residui avversari, scarsità per tier |
| `save_signals` (write) | batch di segnali estratti da una source; valida player_id o crea alias se risolve un nome |
| `save_plan` (write) | nuova versione del piano: 25 slot completi, alternative incluse; validazione dura (budget totale ≤ crediti residui + già spesi nei miei acquired, ruoli corretti, niente giocatori già assegnati ad altri) |
| `resolve_player_name` (write) | dato un nome grezzo, cerca il canonico; se match sicuro, registra alias |

Regola di sicurezza: i tool write validano tutto server-side. Claude non può mai mettere il piano in stato incoerente (budget sforato, giocatore già venduto, slot mancanti). Se la validazione fallisce, il tool ritorna l'errore dettagliato e Claude corregge nel turno successivo.

---

## 7. Pipeline AI (Claude Code headless)

### 7.1 Estrazione segnali (`resources/prompts/extract-signals.md`)

Trigger: ogni nuova `source` non processata (upload manuale o scraping). Job in coda, batch per efficienza.

Il prompt istruisce Claude a: leggere il testo della source; identificare ogni informazione fanta-rilevante; risolvere ogni nome con `search_player`/`resolve_player_name` (MAI creare segnali con nomi non risolti — se un nome non matcha, segnale con `player_id` null e flag `needs_review` per il backoffice); classificare tipo, impatto, confidence; controllare i segnali esistenti e marcare `superseded` quelli contraddetti (es. "recuperato" supera "infortunato"); scrivere tutto con `save_signals`. Dedup semantica: se il segnale identico esiste già da altra fonte, incrementa confidence invece di duplicare.

### 7.2 Generazione piano iniziale (`generate-plan.md`)

Trigger: manuale (bottone "Genera piano") dopo import listone e setup lega.

Il prompt definisce la dottrina strategica, che Claude applica con i dati dei tool:

- allocazione budget per reparto adattata a lega CON modificatore difesa (indicativamente più peso a P+D di quanto farebbe una lega standard; la ripartizione esatta la decide Claude motivandola nelle strategy_notes);
- struttura a tier per reparto: pochi top a cui destinare la parte grossa, fascia media di titolari affidabili, scommesse ad alto upside, tappabuchi da 1;
- per OGNI slot: titolare del piano + almeno 2 alternative con prezzi, perché l'asta random non garantisce l'ordine;
- massimizzare titolarità attesa e coprire i rigoristi; diversificare le squadre reali in attacco (turnover/scontri diretti), concentrare in difesa se conviene per il modificatore;
- output via `save_plan`.

### 7.3 Replanning (`replan.md`)

Trigger: (a) **automatico dopo ogni aggiudicazione**, con debounce di 20 secondi (se in 20s arrivano 3 acquisti, un solo run con tutti e tre); (b) bottone manuale "Ricalcola ora"; (c) automatico se un giocatore del piano (titolare di slot) viene preso da altri.

Contratto di output: **sempre una rosa completa** — i miei acquisti già fatti negli slot `acquired`, e per ogni slot aperto il nuovo target con prezzo, dati crediti residui, inflazione corrente e disponibilità reale. Mai testo discorsivo come output primario: le strategy_notes sono max 3 righe. Il piano È la risposta.

Priorità di coda massima: il replan deve tipicamente completare in 30–90 secondi. La UI mostra lo stato "ricalcolo in corso" con la versione precedente ancora attiva e marcata come tale.

### 7.4 Scraping schedulato e completo

- **Schedulato**: `schedule` Laravel ogni 30 minuti (configurabile) su tutte le `scrape_targets` abilitate: RSS se disponibile, altrimenti crawl della sezione news. Dedup per `content_hash` e per similarità titolo. Solo articoli nuovi → coda estrazione.
- **Full scrape on demand**: bottone in backoffice → job batch che ripassa tutte le testate in profondità (paginazione archivi recenti, finestra configurabile, default 7 giorni). Barra di avanzamento in UI (batch progress di Horizon).
- Etica e robustezza: user-agent identificato, rispetto robots.txt, rate limit per dominio (max 1 req/2s), circuit breaker su errori ripetuti.

---

## 8. UX — tre aree

### 8.1 Backoffice conoscenza

- Drop zone universale: trascini PDF/doc, incolli link o testo, scrivi una nota → una source, processata in automatico. Zero configurazione per item.
- Lista sources con stato pipeline (in coda / processata / errore / needs_review) e segnali estratti espandibili.
- Vista segnali per giocatore con possibilità di correggere/eliminare; coda `needs_review` per i nomi non risolti (assegnazione manuale → crea alias, così l'errore non si ripete).
- Gestione scrape_targets e bottone full scrape.
- Import listone CSV (upload, anteprima mapping colonne, conferma; re-import aggiorna quotazioni senza perdere segnali/alias).
- Setup lega e squadre.

### 8.2 Sala d'asta (la schermata che decide il successo del progetto)

Progettata per il flusso reale dell'asta random: viene estratto un nome → Andrea lo cerca → decide in pochi secondi → quando il martello cade, registra l'esito. Requisiti non negoziabili:

- **Search box sempre a fuoco**, fuzzy (Meilisearch), risultati mentre digiti, frecce + invio per selezionare. Dopo ogni registrazione il focus torna lì automaticamente.
- **Scheda decisione istantanea** alla selezione: `max_bid` ENORME al centro, tier, se è nel piano (e in quale slot, e quale alternativa scatta se sfuma), segnali attivi in una riga (icone: infortunio, rigorista, ballottaggio...), quotazione e stats essenziali. Tutto da `valuations`: nessuna attesa.
- **Registrazione in un solo flusso di tastiera**: giocatore selezionato → digiti il prezzo → tasto rapido per la squadra (1–9 mappate alle squadre, 0 o invio = io) → fatto. Target: sotto i 3 secondi, zero mouse. Toast di conferma + **undo** immediato (ultimo evento, un tasto).
- **Colonna piano vivo**: la rosa obiettivo corrente per ruolo, ogni riga = nome + prezzo target, righe verdi (prese), rosse barrate con alternativa promossa (perse), badge versione piano + spinner se il replan sta girando.
- **Colonna lega**: crediti residui e slot aperti per ruolo di ogni squadra (fondamentale per anticipare rilanci: chi ha 200 crediti e 0 attaccanti rilancerà).
- **Barra di stato mia squadra**: budget residuo, speso per reparto vs allocazione piano, slot riempiti (es. D 3/8).
- Layout desktop-first a tre colonne, ma responsive: da tablet/telefono deve restare usabile per la sola consultazione.

### 8.3 Dashboard pre-asta

Piano corrente leggibile e stampabile, top segnali recenti, salute pipeline (sources in coda, ultimo scrape), bottoni: genera piano, full scrape, ricalcola.

---

## 9. Fasi di delivery e acceptance criteria

Ogni fase produce un incremento funzionante e testato. Non iniziare la fase N+1 con acceptance della N aperti.

### Fase 0 — Fondamenta
Scaffolding Laravel 12, Horizon, Scout+Meilisearch, config lega, CRUD squadre, import CSV listone con normalizzazione e generazione alias automatici.
- [ ] Import del CSV fantacalcio.it reale: tutti i giocatori presenti, ruoli corretti, zero duplicati
- [ ] `search_player` fuzzy trova "lautaro", "Martinez L.", "martinez lautaro" → stesso player
- [ ] Re-import aggiorna quotazioni senza toccare alias
- [ ] Test Pest su import e normalizzazione

### Fase 1 — Conoscenza
Backoffice ingestion (drop zone), pipeline estrazione con Claude Code headless, tabella signals, coda needs_review, MCP server con tool read + `save_signals` + `resolve_player_name`.
- [ ] Dato un articolo con un infortunio noto, il segnale compare con player corretto, tipo `infortunio`, fonte linkata
- [ ] Nome non risolvibile → needs_review, non un segnale orfano silenzioso
- [ ] "Recuperato" supera il precedente "infortunato" (superseded)
- [ ] Ogni run tracciato in `ai_runs`
- [ ] PDF testuale caricato → testo estratto → segnali

### Fase 2 — Cervello
ValuationEngine completo, `generate-plan.md`, tool `save_plan` con validazione dura, dashboard pre-asta.
- [ ] Piano: 25 slot, ruoli esatti, budget totale ≤ crediti, ≥2 alternative per slot
- [ ] Piano invalido proposto da Claude → rifiutato dal tool con errore chiaro, Claude corregge, run va comunque a buon fine
- [ ] Un segnale `infortunio` grave abbassa visibilmente `adjusted_value` e il giocatore esce dai piani rigenerati
- [ ] Valutazioni per l'intero listone ricalcolate in < 10s

### Fase 3 — Sala d'asta
UI live completa, registrazione acquisizioni, undo, replan automatico con debounce, inflazione live, polling piano.
- [ ] Registrazione completa da tastiera in ≤ 3 secondi, focus che torna alla search
- [ ] Aggiudicazione a un avversario → suoi crediti/slot aggiornati subito, replan parte (debounced), nuova versione appare senza reload
- [ ] Target del piano preso da altri → slot rosso con alternativa promossa anche PRIMA del replan (promozione deterministica immediata, il replan poi rifinisce)
- [ ] `max_bid` mai superiore a crediti residui − slot aperti + 1
- [ ] Undo ripristina crediti, slot e stato giocatore

### Fase 4 — Scraping automatico
Scheduler testate, full scrape con progress, dedup, circuit breaker.
- [ ] Stesso articolo da due fonti → una source processata, confidence del segnale aumentata, nessun duplicato
- [ ] Full scrape mostra avanzamento e non blocca il resto dell'app
- [ ] Dominio che risponde 429/errori → backoff, nessun martellamento

### Fase 5 — Rifinitura
Dark mode (l'asta è di sera), stampa piano, performance pass sulla sala d'asta, empty states, seed di demo per provare tutto senza asta reale (simulatore: estrae nomi random e registra acquisti finti — utilissimo anche per collaudare il replan sotto carico).

---

## 10. Rischi noti e mitigazioni

| Rischio | Mitigazione |
|---|---|
| Matching nomi sbagliato → segnali sul giocatore errato | alias table + fuzzy + soglia di confidenza: sotto soglia sempre needs_review, mai auto-assegnazione azzardata |
| Replan lento durante raffiche di acquisti | debounce 20s, coda dedicata alta priorità, promozione alternativa deterministica immediata come rete di sicurezza |
| Claude produce piano invalido | validazione server-side nel tool `save_plan`, retry con errore; la UI non mostra mai piani non validati |
| Sessione `claude` scaduta/non autenticata il giorno dell'asta | healthcheck in dashboard ("Claude Code: OK/KO") + comando artisan `ai:healthcheck` da lanciare prima dell'asta |
| Scraping fragile (markup che cambia) | preferire RSS; parser per-testata isolati; il fallimento di una testata non ferma le altre |
| Fantacalcio.it cambia formato CSV | mapping colonne configurabile in fase import, con anteprima |

## 11. Convenzioni per gli agenti

- Lingua del codice: inglese. Lingua di UI, prompt e documentazione: italiano.
- Ogni decisione architetturale non banale → ADR in `docs/adr/`.
- Prompt = specifiche versionate: modificarli con la stessa cura del codice, mai a caldo in produzione senza commit.
- Conventional commits; PR piccole per fase; test Pest obbligatori su ValuationEngine, import, matching, validazione piano.
- Niente chiavi API Anthropic da nessuna parte: se serve, la strada è sempre `claude -p`.

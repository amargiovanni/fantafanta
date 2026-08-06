# 0003. Integrazione con Claude Code headless via server MCP

**Status**: Proposed
**Date**: 2026-08-06
**Deciders**: [da confermare — Claude come estensore, Andrea (PO) come decisore]

## Contesto

La Fase 1 introduce il primo uso reale dell'AI nel progetto: leggere le fonti
di conoscenza (articoli, PDF, note) e trasformarle in segnali strutturati per
giocatore. Serviva decidere **come** l'applicazione parla con il modello e,
soprattutto, **come scrive** i dati che ne derivano.

I vincoli erano dati e non trattabili:

- Nessuna chiave API Anthropic nel progetto: la strada è la sottoscrizione già
  attiva sulla macchina, cioè il binario `claude` in modalità headless
  (briefing §3 e §11).
- L'AI non è affidabile per costruzione. Può sbagliare un nome, inventare un
  valore fuori scala, dimenticare un passaggio del prompt. Un segnale
  attribuito al giocatore sbagliato falsa una valutazione **senza dare alcun
  segnale d'allarme** — è il rischio numero uno del progetto (briefing §10).
- Ogni esecuzione costa tempo reale e sottoscrizione reale: deve essere
  tracciabile, ripetibile e non moltiplicabile per errore.

## Decisione

### 1. Il contratto di esecuzione

L'unico punto dell'applicazione che invoca l'AI è il job `RunClaudeTask`
(coda `ai`, o `ai-replan` dalla Fase 3), che esegue:

```
claude -p "<prompt composto>" --output-format json --max-turns 30 \
       --allowedTools "mcp__fanta-asta__*" --mcp-config .mcp.json \
       --strict-mcp-config
```

`--strict-mcp-config` è un'aggiunta rispetto al briefing §3, resa necessaria
dal comportamento reale del CLI: senza di esso il processo caricherebbe anche i
server MCP personali dell'utente e chiederebbe l'approvazione interattiva del
server di progetto — impossibile in headless. Con il flag, il run vede i dati
di Fanta Asta e nient'altro.

Il prompt non è mai inline nel codice: vive in `resources/prompts/*.md` con
segnaposto `{{ nome }}` sostituiti da `PromptComposer`, che **fallisce** se un
segnaposto resta senza valore. I prompt sono specifiche versionate e passano da
commit come il codice (briefing §7.1).

### 2. La scrittura passa dai tool MCP, non dal parsing dell'output

L'app espone il server MCP `fanta-asta` (package ufficiale `laravel/mcp`) su
`routes/ai.php`. Claude legge con `search_player`, `get_player`, `get_signals` e
scrive con `save_signals` e `resolve_player_name`. Il JSON finale che il modello
produce serve **solo** da audit di riserva: non è la via di scrittura.

Il motivo è che il parsing di un output libero rimanda la validazione a valle,
dove l'errore è già entrato in casa. Passando dai tool, la validazione avviene
prima della scrittura e l'errore torna al modello mentre può ancora correggerlo.

### 3. La validazione server-side è il confine di sicurezza

Il prompt non è un vincolo: è un auspicio. Il vincolo è il codice dei tool.
`save_signals` applica, lato server e senza fidarsi di nulla:

- `type` nell'enum, `confidence` in [0,1], `impact` intero in [-2,+2],
  `source_id` esistente;
- o un `player_id` esistente, **oppure** `player_id` assente insieme a
  `needs_review = true` e `raw_name` valorizzato. Non esiste una terza via: un
  segnale orfano e silenzioso è impossibile da scrivere;
- batch **transazionale**: un solo segnale invalido rifiuta l'intero batch e
  restituisce l'elenco puntuale degli errori, uno per segnale, in forma
  correggibile al turno successivo (briefing §6);
- un segnale non può marcare come superato un segnale di un altro giocatore.

Alla risoluzione dei nomi si applica la stessa filosofia: `PlayerResolver`
aggancia automaticamente un nome solo sopra una soglia di somiglianza (0.85) e
con un distacco minimo dal secondo candidato (0.10). Sotto soglia non sceglie:
restituisce i candidati e il segnale finisce in revisione manuale. "Thuram", con
due Thuram in listone, non viene mai attribuito a caso.

### 4. Due reti di sicurezza deterministiche

Perché il comportamento corretto non dipenda dal fatto che il modello ricordi
le istruzioni:

- **dedup**: un segnale identico (stesso giocatore, stesso tipo, attivo) da
  un'altra fonte non viene duplicato ma corrobora l'esistente alzandone la
  confidence con tetto a 1.0; dalla **stessa** fonte non produce alcun effetto,
  quindi un retry del job è idempotente;
- **supersede automatico**: un `rientro` supera sempre l'`infortunio`
  precedente dello stesso giocatore, che Claude lo dichiari o meno.

### 5. Audit completo

Ogni esecuzione — riuscita o fallita — lascia una riga in `ai_runs`: task,
file di prompt, `prompt_hash` (sha256 del prompt effettivamente inviato),
durata, output grezzo, errore, contesto di dominio. Da un segnale sbagliato si
risale sempre al run che l'ha prodotto e all'input esatto che ha ricevuto.

Il retry (`tries = 2`) riporta in appendice al prompt l'errore del tentativo
precedente, letto da `ai_runs`: la memoria fra i tentativi è la tabella di
audit, perché le proprietà del job non sopravvivono alla ripubblicazione in
coda.

## Alternative considerate

- **API Anthropic con SDK invece del CLI headless** — esclusa dal briefing
  (§11): nessuna chiave API nel progetto, la sottoscrizione è già pagata.
- **Far restituire a Claude un JSON e farlo scrivere a Laravel** — scartata: è
  la via che sposta la validazione dopo la generazione e obbliga a mantenere
  uno schema di parsing parallelo ai tool. Il JSON resta come audit di riserva.
- **Validare solo nel prompt, tenendo i tool permissivi** — scartata: un prompt
  descrive l'intenzione, non la garantisce. La garanzia sta nel codice che
  scrive sul database.
- **Nessun MCP: passare i dati nel prompt e riprendere l'output** — scartata:
  il listone e i segnali esistenti non stanno in un prompt, e servirebbe
  comunque una via di scrittura validata.
- **Auto-assegnare il nome più simile invece della coda di revisione** —
  scartata: sotto soglia l'errore sarebbe invisibile. Un segnale in revisione
  è visibile e si corregge in dieci secondi (briefing §10).

## Conseguenze

**Positive:**
- L'AI non può mettere il database in uno stato incoerente, per costruzione e
  non per buona volontà del prompt.
- La correzione manuale di un nome non risolto crea un alias: lo stesso errore
  non si ripete, e la qualità del matching migliora con l'uso.
- Ogni euro speso in esecuzioni è tracciato e ispezionabile.
- La stessa struttura regge la Fase 2 (`save_plan` con validazione dura del
  budget) senza cambiare impianto: cambia il tool, non il contratto.

**Negative / obblighi creati:**
- Ogni nuovo tool di scrittura deve portarsi la propria validazione completa e
  i propri test: è lavoro che non si può saltare "perché tanto il prompt lo
  dice".
- L'applicazione dipende dal fatto che il binario `claude` sia presente e
  autenticato, e che il server MCP sia raggiungibile da fuori sul dominio
  Herd. Mitigazione: `ai:healthcheck` verifica entrambi (più Redis e
  Meilisearch) e la dashboard ne mostra lo stato.
- Il server MCP è esposto senza autenticazione. È accettabile solo perché
  l'app è single tenant e gira in locale su Herd: se un giorno venisse
  pubblicata, questa è la prima cosa da chiudere.
- I prompt, essendo specifiche, vanno mantenuti allineati ai tool: se cambia
  l'enum dei segnali va aggiornato anche `extract-signals.md`.

## Riferimenti

- `briefing.md` §3 (contratto Claude Code headless), §6 (server MCP e regola di
  sicurezza), §7.1 (estrazione segnali), §10 (rischi), §11 (convenzioni)
- `docs/superpowers/specs/2026-08-06-fanta-asta-design.md` §3
- ADR [0002](0002-two-speed-architecture.md) (architettura a due velocità)

# 0004. Design del motore di valutazione deterministico

**Status**: Proposed
**Date**: 2026-08-06
**Deciders**: [da confermare — Claude come estensore, Andrea (PO) come decisore]

## Contesto

La Fase 2 introduce il numero su cui, la sera dell'asta, si alza la mano.
Quando un nome viene battuto ci sono pochi secondi per decidere se offrire e
fino a quanto: non c'è tempo di leggere una scheda, tanto meno di aspettare un
modello. Serve un valore già pronto, e serve poterci credere.

I vincoli erano dati:

- **Nessuna AI nel percorso sincrono** (design §3). La valutazione deve
  esistere *prima* che serva, e leggerla deve costare una query.
- **Determinismo.** Lo stesso stato del database deve produrre gli stessi
  numeri. Un valore che cambia da solo fra due letture non è consultabile sotto
  pressione: si smette semplicemente di guardarlo.
- **Ricalcolo completo sotto i 10 secondi su ~600 giocatori** (briefing §9).
- **Ogni componente del calcolo deve essere spiegabile e tarabile.** È il
  pezzo del progetto più esposto al "questo numero non mi torna": se non si può
  rispondere indicando una formula e una costante, il motore non viene usato.

## Decisione

### 1. Due velocità anche dentro il motore

Il motore (`App\Services\ValuationEngine`) è **PHP puro**: nessuna chiamata di
rete, nessun modello, nessuna euristica non scritta. L'AI decide *cosa fare*
del valore — il piano, le priorità, i compromessi di budget — ma il valore lo
calcola il codice.

La divisione del lavoro è netta e va difesa: se un giorno il motore chiedesse
"quanto vale secondo te" a un modello, perderemmo insieme il determinismo, il
budget dei 50 ms e la possibilità di testarlo.

Il ricalcolo gira in coda (`RecomputeValuations`, coda `default` — non `ai`,
per non mettersi in fila dietro a un replan da un minuto) e persiste in
`valuations`. La sala d'asta legge quella tabella e basta.

### 2. Ricalcolo totale, mai incrementale

Ogni trigger — import del listone, segnale creato/modificato/superato/
cancellato, aggiudicazione o suo annullamento, modifica della configurazione di
lega, salvataggio di un piano — ricalcola **tutto il listone**.

È una scelta contro l'istinto dell'ottimizzazione, e il motivo è che il calcolo
non è locale: i tier sono quintili fra i disponibili, la scarsità dipende dagli
slot aperti di tutti, l'inflazione dipende dai prezzi pagati nel reparto. Un
solo acquisto sposta i numeri di decine di giocatori che non c'entrano nulla
con lui. Un ricalcolo incrementale sarebbe una lista di eccezioni da mantenere,
e il giorno che ne dimenticassimo una nessuno se ne accorgerebbe.

Il costo misurato è **0,3 secondi su 600 giocatori** (test di performance nella
suite, soglia a 10 s): non c'è niente da ottimizzare.

I trigger sono observer sui modelli (`SignalObserver`, `AcquisitionObserver`,
`LeagueConfigObserver`) più un dispatch esplicito in `ListoneImporter` e in
`PlanWriter`. Stanno negli observer e non nella UI perché gli stessi fatti
arriveranno da tre percorsi diversi — la sala d'asta della Fase 3, il
simulatore della Fase 5, i test — e non possono comportarsi in tre modi.

### 3. Formule parametriche, costanti in configurazione

Nel codice del motore **non esiste un solo numero letterale**. Pesi, quote di
ripartizione del monte crediti per reparto, decadimento dei segnali,
moltiplicatori dei casi speciali, soglie dei modificatori, clamp di inflazione
e scarsità: tutto sta in `config/valuation.php`, con un commento che dice cosa
significa il parametro e perché vale quanto vale.

Sono decisioni di dominio, non dettagli di implementazione: si tarano fra
un'asta e l'altra, e devono poterlo fare senza toccare il codice né riscrivere
i test. La specifica algoritmica che le origina è versionata in
`docs/superpowers/specs/2026-08-06-valuation-engine.md`.

L'ordine del calcolo è quello della specifica: base value dal listone scalato
sul monte crediti reale → segnali con decadimento e casi speciali → modificatori
di lega → inflazione live → scarsità → vincolo di budget.

### 4. I casi speciali sono interruttori, non pesi

La somma pesata degli impatti (clampata a ±0,6) descrive bene le notizie
ordinarie e male quelle che cambiano la natura del giocatore. Tre casi sono
trattati come moltiplicatori diretti, tipizzati dal payload del segnale:

- infortunio stimato ≥ 4 mesi → ×0,15 (2-4 mesi → ×0,5);
- cessione fuori dalla Serie A confermata (confidence ≥ 0,8) → valore 1, punto;
- rigorista di ruolo offensivo → +12%.

Un infortunio da cinque mesi non rende un giocatore "meno appetibile": lo toglie
dall'asta. Un clamp a ±0,6 non saprebbe dirlo.

### 5. Upsert massivo, quattro query, nessun N+1

Il motore carica in memoria giocatori, segnali attivi, acquisizioni e stato
della lega con una query ciascuno, calcola tutto in PHP e scrive con un
`upsert` a blocchi da 200 righe su `valuations`, che ha `player_id` unico.

Il blocco esiste per SQLite, che ha un tetto ai parametri di una singola
istruzione; la sala d'asta gira su SQLite e il limite va rispettato lì dove si
manifesta.

Lo stato della lega (crediti spesi, slot aperti per ruolo, domanda avversaria)
è estratto una volta sola in `App\Services\LeagueState`, condiviso fra motore,
tool MCP e dashboard: due definizioni diverse di "crediti residui" produrrebbero
un `max_bid` mostrato in asta diverso da quello validato in `save_plan`.

### 6. Scelte lasciate ai margini della specifica, decise qui

- **`base_value` e `adjusted_value` sono decimali (8,2), `max_bid` è intero.**
  Il valore serve anche a ordinare e a formare i quintili: arrotondare a credito
  intero appiattirebbe la coda del listone, dove decine di giocatori valgono
  "circa 1". L'offerta invece è un numero che si dice a voce.
- **`acquisitions.valuation_at_purchase`**: una colonna in più rispetto al
  modello dati del briefing. L'inflazione confronta i prezzi pagati con il
  valore che il giocatore aveva **allora**, e quel valore cambia a ogni segnale
  nuovo. Senza lo scatto, l'inflazione di ieri si riscriverebbe da sola oggi.
- **I giocatori oltre i "comprabili"** (oltre i primi `teams_count × slot` per
  ruolo) ricevono lo stesso valore per punto di punteggio grezzo dei comprabili,
  con floor a 1 credito. È la lettura di "per interpolazione" della specifica
  che mantiene l'ordinamento monotono e non regala valore a chi non lo ha.
- **`season_stats` si legge per chiavi configurabili** (`Pv`, `Mv`, `Fm`, `Am`
  e sinonimi, confronto senza maiuscole, virgola decimale gestita): il mapping
  dell'import è scelto dall'utente e il formato del CSV di fantacalcio.it cambia
  (briefing §10). Il motore non può dipendere da un nome di colonna.
- **Senza "la mia squadra" registrata** il motore usa una squadra virtuale col
  budget pieno della lega, invece di azzerare i `max_bid`. Prima del setup
  completo la dashboard deve mostrare numeri sensati; un tetto a zero sarebbe
  una risposta sbagliata, non prudente.

## Alternative considerate

- **Chiedere la valutazione al modello** — esclusa dal briefing §5 e da ADR
  0002: costo, latenza e soprattutto niente determinismo.
- **Ricalcolo incrementale per giocatore toccato** — scartata: il calcolo non è
  locale (tier, scarsità e inflazione sono di reparto) e l'ottimizzazione
  risolverebbe un problema che i numeri dicono non esistere (0,3 s su 600).
- **Costanti come costanti di classe invece che in config** — scartata: la
  taratura è un gesto di dominio che deve poter avvenire fra due aste senza
  toccare PHP.
- **Valori interi anche per `adjusted_value`** — scartata: rende indistinguibile
  la parte bassa del listone, che è proprio dove si decidono i tappabuchi.
- **Ricalcolo sincrono alla registrazione dell'acquisto** — scartata: viola il
  budget dei tre secondi della sala d'asta (briefing §9 Fase 3). In sincrono
  resta solo la promozione deterministica dell'alternativa, che è O(slot).
- **Snapshot dell'inflazione calcolato a posteriori dalle valutazioni correnti**
  — scartata a favore di `valuation_at_purchase`: leggere il passato con gli
  occhi di oggi produce un'inflazione che cambia da sola.

## Conseguenze

**Positive:**
- Il valore è sempre già pronto e la lettura costa una query: il percorso
  sincrono dell'asta resta libero.
- Ogni componente ha un test dedicato sui casi noti (ripartizione del pool,
  infortunio lungo, supersede, modificatore difesa col suo cap, inflazione
  ammortizzata, vincolo di budget al caso limite, determinismo, performance).
- Una contestazione su un numero si risolve indicando una formula e una riga di
  config, non leggendo il codice.
- La taratura fra un'asta e l'altra non tocca il codice e non rompe i test.

**Negative / obblighi creati:**
- Ogni nuovo trigger va aggiunto a mano: un fatto che cambia le valutazioni e
  non accoda il ricalcolo produce numeri fermi a ieri, che è il modo peggiore
  di sbagliare perché sembrano aggiornati. Gli observer coprono i casi noti; i
  percorsi che scrivono con `update()` di massa li aggirano per costruzione.
- Una raffica di segnali accoda un job per segnale. Sono job da frazioni di
  secondo e idempotenti, quindi il costo è tollerato, ma con l'ingestione
  automatica della Fase 4 andrà messo un debounce (o `ShouldBeUnique`) come
  quello già previsto per il replan.
- `config/valuation.php` diventa parte del contratto: cambiarne un valore
  cambia il comportamento del motore, e va trattato come una modifica di codice
  (commit, motivazione, test rieseguiti).
- Il motore assume che `season_stats` contenga le colonne del CSV. Un listone
  importato senza statistiche funziona — la quotazione fa da proxy — ma perde
  il modificatore di difesa e il fairplay, che sono buona parte del vantaggio
  in questa lega.

## Riferimenti

- `briefing.md` §5 (motore di valutazione), §4 (modello dati), §9 Fase 2
  (acceptance criteria), §10 (rischi)
- `docs/superpowers/specs/2026-08-06-valuation-engine.md` (specifica algoritmica)
- `docs/superpowers/specs/2026-08-06-plan-doctrine.md` (dottrina del piano)
- ADR [0002](0002-two-speed-architecture.md) (architettura a due velocità)
- ADR [0003](0003-claude-code-headless-and-mcp.md) (validazione server-side dei tool)

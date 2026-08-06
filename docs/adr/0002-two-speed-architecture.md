# 0002. Architettura a due velocità: livello deterministico vs livello strategico

**Status**: Accepted
**Date**: 2026-08-06
**Deciders**: [da confermare — Claude come estensore, Andrea (PO) come decisore]

## Contesto

`briefing.md` §1 definisce il principio architetturale cardine del progetto:
l'asta fantacalcio è **random** (ordine di estrazione deciso dal sistema
fantacalcio.it, non a chiamata), quindi ogni giocatore del listone può essere
estratto in qualunque momento e la UI d'asta deve rispondere "quanto vale per
me questo giocatore" in tempo reale, senza attese.

Allo stesso tempo, il progetto vuole usare Claude Code headless come motore di
reasoning strategico (estrazione segnali dalle fonti, generazione e ricalcolo
del piano d'acquisto — briefing §7), un'operazione intrinsecamente asincrona
(minimo 30-90 secondi per un replan, briefing §7.3) e non deterministica
nell'output.

Mettere una chiamata AI nel percorso sincrono della sala d'asta violerebbe il
requisito di latenza (<50ms, briefing §5) e introdurrebbe un punto di fallimento
(sessione Claude scaduta, rate limit, timeout) esattamente nel momento in cui
l'app non può permetterselo: mentre il martello sta per cadere.

## Decisione

Adottiamo una separazione netta in due livelli, non negoziabile per l'intero
progetto:

- **Livello deterministico (sincrono, PHP puro)**: valutazioni (`valuations`),
  prezzi massimi, tier, inflazione, scarsità per ruolo. Sempre pre-calcolato in
  coda dopo ogni evento rilevante (import, segnale, acquisizione, modifica
  config — briefing §5), mai calcolato on-demand nella richiesta HTTP della
  sala d'asta. Nessuna chiamata AI in questo percorso.
- **Livello strategico (asincrono, Claude Code headless via `claude -p`)**:
  estrazione segnali dalle fonti, generazione e ricalcolo del piano d'acquisto.
  Gira in coda Horizon (coda dedicata `ai-replan` a priorità massima per il
  replan, `ai` per estrazione segnali e piano iniziale — design doc §3); il
  risultato appare in UI appena pronto (polling `wire:poll`), la UI mostra
  sempre l'ultima versione pronta del piano, mai uno stato di attesa bloccante.
- **Rete di sicurezza sincrona**: la promozione deterministica dell'alternativa
  di uno slot quando il target del piano viene preso da un avversario è
  l'unica eccezione — è sincrona alla registrazione dell'acquisto perché è
  puro aggiornamento di stato (nessuna chiamata AI), non un ricalcolo
  strategico (briefing §9 Fase 3, design doc §3).

Se un agente di sviluppo si trova a inserire una chiamata AI in un percorso
sincrono della UI d'asta, deve fermarsi e ripensare l'approccio (briefing §1,
esplicito).

## Alternative considerate

- **Chiamare Claude sincronamente al momento dell'estrazione del giocatore**
  (per un'analisi "fresca" ad ogni nome) — scartata: viola il requisito di
  latenza <50ms e introduce un single point of failure nel momento più critico
  dell'asta (briefing §10, "sessione claude scaduta il giorno dell'asta").
- **Websocket/Reverb per il realtime del piano invece del polling** — non
  scartata in assoluto ma rimandata: il briefing la marca esplicitamente come
  "opzionale in v2"; il polling Livewire (3s) è sufficiente e più robusto per
  v1 (meno infrastruttura da tenere in piedi il giorno dell'asta).
- **Ricalcolo delle valuations on-demand invece che precalcolato in coda** —
  scartata: anche se il motore è "pochi secondi per l'intero listone"
  (briefing §5), l'unica strategia compatibile con <50ms in sala d'asta è
  leggere una tabella già calcolata, mai ricalcolare nella request.

## Conseguenze

**Positive:**
- La sala d'asta (la schermata che "decide il successo del progetto",
  briefing §8.2) non ha mai una dipendenza runtime da Claude Code: resta
  utilizzabile anche se `claude` non risponde durante l'asta.
- Separazione netta di responsabilità facilita testing: il motore
  deterministico si testa con Pest su casi noti senza mai mockare l'AI; la
  pipeline AI si testa isolatamente (Fase 1-3) sul contratto MCP.

**Negative / obblighi creati:**
- Ogni feature futura che tocca la sala d'asta va progettata chiedendosi
  esplicitamente "questo calcolo può aspettare la prossima esecuzione della
  coda?" — se la risposta è "deve essere immediato", non può passare da
  Claude.
- Introduce uno stato intermedio da gestire in UI ("piano in fase di
  ricalcolo, versione precedente ancora attiva") che va progettato con cura
  fin dalla Fase 2/3, non aggiunto in un secondo momento.
- Richiede code Horizon dedicate e monitorate (`ai-replan` a priorità massima)
  fin dalla Fase 1, non solo quando si arriva alla sala d'asta in Fase 3.

## Riferimenti

- `briefing.md` §1 ("Principio architetturale cardine: due velocità"), §5, §7.3, §9 (Fase 3), §10
- `docs/superpowers/specs/2026-08-06-fanta-asta-design.md` §3

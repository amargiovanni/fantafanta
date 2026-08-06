# 0005. Meccanica della sala d'asta: macchina a stati, debounce del replan, undo reversibile

**Status**: Proposed
**Date**: 2026-08-07
**Deciders**: [da confermare — Claude come estensore, Andrea (PO) come decisore]

## Contesto

La sala d'asta è la schermata che decide il successo del progetto (briefing
§8.2). Tutto il lavoro delle Fasi 0-2 — listone, segnali, valutazioni, piano —
esiste per essere consultato in tre secondi, in piedi, mentre un banditore
chiama nomi in ordine casuale e otto persone rilanciano.

I vincoli, tutti dati e nessuno negoziabile:

- **Registrazione in meno di tre secondi, zero mouse** (briefing §9 Fase 3).
  Non è un obiettivo di gradevolezza: se registrare un acquisto costa più del
  tempo che passa fra due chiamate, l'applicazione viene abbandonata a metà
  serata e il piano smette di corrispondere alla realtà.
- **Nessuna AI e nessun calcolo pesante nel percorso sincrono** (ADR 0002,
  design §3). La sala legge `valuations`, `plan_slots` e `teams`, e scrive una
  riga in `acquisitions`.
- **Il piano non può restare falso** nemmeno per un minuto: se un titolare
  sfuma, l'alternativa deve essere già lì mentre il replan è ancora in coda.
- **L'undo deve ripristinare tutto**, piano compreso, perché un errore di
  battitura alle 23 è certo e la correzione deve costare un tasto.

## Decisione

### 1. Una sola macchina a stati, sul client, e nessuna logica di dominio dentro

Il flusso è quello della specifica:

```
searching → selected → pricing → assigning → confirmed
```

Vive in un unico componente Alpine (`resources/js/app.js`, `auctionRoom`) e non
decide niente di sostanziale: stabilisce solo **quale tasto significa cosa** e
manda al server una sola chiamata — giocatore, prezzo, squadra. Chi è
disponibile, quanto può spendere quella squadra e cosa succede al piano lo
stabilisce il server, dove sta anche il resto del sistema.

Lo stato non è una copia da tenere allineata: è **derivato** da
`$wire.selectedId` e dal prezzo digitato. Una macchina a stati con una variabile
`state` scritta a mano si disallinea al primo percorso non previsto, e in asta
un disallineamento significa registrare l'acquisto sbagliato.

### 2. La barra spaziatrice è il confine fra prezzo e squadra

La specifica chiede che dopo il prezzo **un solo tasto** assegni: `Invio` o `0`
a me, `1`-`9` all'avversario. C'è però un'ambiguità irriducibile: prezzo e
squadre parlano lo stesso alfabeto. Battuto `4` e poi `3`, non esiste modo di
sapere se si intende «43 crediti» o «43... no, alla squadra 3».

Le uscite possibili erano tre: un secondo `Invio` di conferma (escluso dalla
specifica: «niente secondo Invio»), una disambiguazione a tempo (esclusa: sotto
stress una soglia di 400 ms è un generatore di errori), oppure un tasto di
confine che non sia una cifra. Abbiamo scelto il terzo:

- **`Invio`** → aggiudicato **a me**, subito. È il percorso più corto — prezzo e
  un tasto — ed è quello che si percorre con le mani che tremano.
- **`Spazio`** → cade il martello: da qui in poi le cifre sono squadre. Il tasto
  successivo (`0`-`9`) **conferma immediatamente**, come chiede la specifica.

`Spazio` sta sotto il pollice, non è una cifra, e ha un significato mnemonico
(il martello). L'aggiunta rispetto alla specifica è di un tasto solo, e solo
sugli acquisti altrui: i miei, che sono quelli che contano, restano a due tasti.

`U` annulla, ma **solo a ricerca vuota**: mentre si scrive un nome la `u` è una
lettera come tutte le altre, e un undo scattato per una lettera sarebbe peggio
del problema che risolve.

### 3. Gli effetti della registrazione stanno nell'observer, non nella UI

`Room::record()` valida gli ingressi, applica due guardie di plausibilità
(una squadra non può spendere crediti che non ha né riempire un reparto già
completo — una riga così falserebbe da lì in avanti inflazione, `max_bid` e
piano) e crea la riga `acquisitions` dentro una transazione. Tutto il resto è
`AcquisitionObserver`, che già esisteva dalla Fase 2:

1. scatto della valutazione corrente (`valuation_at_purchase`);
2. `players.status` → `acquired`;
3. `PlanSlotPromoter::apply()` — slot `acquired` se il giocatore è mio, slot
   `lost` con promozione della prima alternativa disponibile se è di un altro;

e **dopo il commit**: `RecomputeValuations` e il replan con debounce.

La ragione è quella già scritta nell'observer: gli stessi fatti arrivano da tre
percorsi — la sala, i test, il simulatore della Fase 5 — e non possono avere tre
comportamenti. La UI d'asta non è un quarto posto dove ricordarsi le cose.

I **crediti spesi restano derivati** dalla somma delle `acquisitions` non
cancellate: nessun contatore da tenere sincronizzato, quindi nessun contatore
che possa divergere dopo un undo.

### 4. Il replan è un debounce di coda con un tetto d'attesa

Ogni aggiudicazione scrive un marker in cache (`replan:pending:{auction}`) con
due timestamp — `first`, il primo evento non ancora servito, e `last`, l'ultimo
arrivato — e accoda uno `ScheduleReplan` con venti secondi di ritardo che porta
a bordo il **timestamp della propria schedulazione**. Al risveglio, il job che
scopre che `last` è più recente di sé stesso esce: esiste un job più giovane che
farà il lavoro con più informazioni. Di una raffica di tre acquisti sopravvive
un solo run, venti secondi dopo l'ultimo (briefing §7.3).

A questo abbiamo aggiunto un **tetto di attesa** (`fanta.replan.max_wait`, 90 s)
che la specifica non chiedeva. Un debounce puro ha una patologia semplice: se
gli acquisti si susseguono a meno di venti secondi l'uno dall'altro — cioè
esattamente quello che succede nella fase calda di un'asta — il momento buono
non arriva mai e il piano non si aggiorna per minuti. Trascorsi 90 secondi dal
primo evento non servito il run parte comunque, anche se la raffica continua.

Un replan non si sovrappone mai a un altro (`Replanner::launch()` rifiuta se ne
esiste già uno in volo): due run che partono dallo stesso stato scriverebbero
due versioni del piano e vincerebbe l'ultimo ad arrivare, non il più informato.
Il job che trova la strada occupata **si rimette in coda** invece di scartare gli
eventi, con un tetto di dieci tentativi.

Il bottone "Ricalcola ora" chiama `launch()` direttamente, scavalcando il
debounce ma non la regola di non sovrapposizione.

### 5. La riga `plans` in stato `generating` nasce all'avvio, non alla fine

Il replan crea **subito** la sua riga `plans` con `status = generating` e
`version = max + 1`. È quella riga — non la coda di Horizon, che nessuna UI
guarda — a rendere osservabile il fatto che qualcosa sta girando: accende il
badge "ricalcolo in corso" nella sala e in dashboard, e fa rispondere
`newer_version_generating` al tool `get_current_plan`.

Ne discendono due conseguenze che vanno gestite entrambe:

- **`PlanWriter` occupa quella riga** invece di aggiungerne una accanto. Senza,
  la versione pronta nascerebbe con un numero più alto di quella annunciata
  (v2 `generating`, v3 `ready`) e il badge resterebbe acceso a run finito.
- **`RunClaudeTask::failed()` la marca `failed`.** Una riga `generating` di un
  run morto terrebbe acceso il badge tutta la sera e verrebbe occupata più
  tardi da un piano che non le appartiene.

### 6. L'undo è un `revert` da giornale, non una ricostruzione per inferenza

La promozione deterministica cambia più cose di quante ne dica il suo nome: lo
slot perso, il titolare, il prezzo target, la lista di alternative dello slot —
e le alternative **degli altri slot**, da cui il giocatore appena assegnato viene
tolto perché non è più un ripiego disponibile.

Ricostruire tutto questo all'indietro («quale alternativa avrò promosso?»)
darebbe la risposta giusta solo finché lo stato non si muove sotto, e in asta si
muove. Per questo `PlanSlotPromoter::apply()` scrive sull'acquisto un
**giornale** (`acquisitions.plan_effects`, colonna JSON nuova) con i valori di
prima di ogni slot che ha toccato, e `revert()` riscrive esattamente quelli.

Separatamente, `plan_slots.original_player_id` conserva il titolare designato
che è sfumato. Non serve al revert — il giornale basterebbe — ma serve alla UI:
la specifica chiede la riga rossa **barrata col nome perso** e sotto
l'alternativa promossa, e dopo la promozione `player_id` non è più lui.

**Limite noto e accettato**: se fra l'acquisto e il suo undo è atterrata una
nuova versione del piano, il giornale ripristina gli slot della versione
vecchia, che non è più quella mostrata. Lo stato del giocatore e i crediti
tornano comunque corretti, e il replan post-undo — che parte, perché lo stato è
cambiato di nuovo — riallinea il piano corrente. Il caso è stato osservato nel
collaudo reale ed è documentato invece che nascosto: la finestra è di 20 secondi
di debounce più la durata del run, e l'undo di sala è per definizione immediato.

L'undo profondo (oltre l'ultimo evento) resta fuori dalla sala: è un modo per
perdere il filo di cosa è vero mentre l'asta corre.

### 7. Il polling confronta un'impronta prima di ridisegnare

`wire:poll.3s` chiama `syncState()`, che calcola un'impronta dello stato con
**tre query aggregate** (versione e conteggio dei piani, conteggio e ultimo
aggiornamento degli slot, conteggio delle aggiudicazioni) e, se non è cambiata,
chiama `skipRender()`.

Una sala aperta e ferma non ridisegna quindi venti volte al minuto un piano da
25 slot, tre colonne e una scheda decisione; e quando il replan atterra, la
nuova versione compare da sola entro tre secondi senza ricaricare la pagina.

### 8. Transizioni dell'asta, e una sola `live` per volta

`setup → live → closed`, con `Auction::start()` che chiude nella stessa
transazione qualunque altra sessione fosse rimasta `live`. Il vincolo è
applicativo come quello di `is_mine` sulle squadre: due aste in corso
significherebbero due verità sui crediti spesi. La sala registra solo con
un'asta `live`; con l'asta in `setup` o `closed` resta consultabile.

## Alternative considerate

- **Tenere la macchina a stati sul server (Livewire puro)** — scartata: ogni
  transizione costerebbe un round-trip, e il budget è di tre secondi per l'intera
  registrazione. Sul server resta la sola chiamata che scrive.
- **Un secondo `Invio` per confermare la squadra** — scartata dalla specifica, e
  a ragione: raddoppia i tasti sul gesto più frequente della serata.
- **Disambiguare prezzo e squadra a tempo** (pausa di N ms) — scartata: sotto
  stress una soglia temporale produce registrazioni sbagliate, che sono il
  fallimento peggiore possibile per questa schermata.
- **Assegnare le squadre a lettere invece che a cifre** — scartata: le cifre sono
  già la legenda naturale ("squadra 3") e la tastiera numerica è sotto la mano.
- **Debounce puro senza tetto d'attesa** — scartata: si blocca esattamente nella
  fase in cui il piano serve di più (vedi §4).
- **Un `ShouldBeUnique` sul job di replan invece del marker in cache** — scartata:
  l'unicità impedisce il secondo job ma non sposta in avanti la scadenza, quindi
  il run partirebbe venti secondi dopo il **primo** acquisto della raffica e non
  vedrebbe gli altri due.
- **Undo per inferenza dal piano versionato** — scartata: non sa rimettere le
  alternative potate dagli altri slot, e dà la risposta giusta solo finché nulla
  si muove (vedi §6).
- **Un contatore `credits_spent` sulle squadre** — scartata: sarebbe un secondo
  posto dove la verità può divergere, in particolare dopo un undo. La somma delle
  acquisizioni non cancellate è già la risposta.
- **`wire:poll` sul componente intero** — scartata: ridisegna tre colonne ogni
  tre secondi anche quando non è cambiato niente, e sposta il fuoco dalla search.

## Conseguenze

**Positive:**

- La registrazione è due o tre tasti dopo la selezione, e il fuoco torna da solo
  alla search: il flusso della serata non passa mai dal mouse.
- Il piano resta vero senza aspettare l'AI: la promozione è sincrona e
  deterministica, il replan la rifinisce. Nel collaudo reale l'alternativa
  meccanica (Colombo) è stata sostituita dal replan con una scelta migliore
  (Rinaldi) 84 secondi dopo, esattamente come previsto dal briefing §7.3.
- Una raffica di acquisti costa un solo run di Claude, e lo stato "sta girando"
  è visibile in UI senza guardare Horizon.
- L'undo è esatto e testato sul caso difficile (alternative potate da altri slot).

**Negative / obblighi creati:**

- **La barra spaziatrice è una convenzione da imparare.** È scritta nella legenda
  della colonna lega e nella scheda decisione, ma resta un tasto in più rispetto
  alla specifica letterale. Va provata dal PO prima dell'asta vera: se risulta
  scomoda, l'alternativa è invertire i ruoli (`Spazio` = mio, `Invio` = apre
  l'assegnazione), non tornare all'ambiguità.
- **Il tetto d'attesa di 90 secondi è una stima**, non una misura. Con acquisti
  molto ravvicinati produce un replan ogni 90 secondi; se il run dovesse durare
  più a lungo, i job di riaccodamento si accumulerebbero fino al loro tetto di
  dieci. Entrambi i valori sono in `config/fanta.php` e vanno tarati dopo la
  prima asta vera.
- **Il giornale dell'undo è legato alla versione del piano su cui è stato scritto**
  (vedi §6): dopo un replan intermedio ripristina slot che non sono più quelli
  mostrati. È un limite accettato, non un bug risolto.
- **`plan_effects` cresce con le aggiudicazioni** (una fotografia per slot
  toccato, in pratica 1-3 slot). Trascurabile su 250 acquisti, ma è una colonna
  JSON che nessuno legge se non l'undo: se un giorno servisse un audit del piano,
  è lì che va guardato invece di aggiungere una tabella.
- **Due colonne nuove su tabelle già in uso** (`plan_slots.original_player_id`,
  `acquisitions.plan_effects`), aggiunte con una migrazione additiva e reversibile
  verificata con un `rollback` reale. Nessuna migrazione esistente è stata toccata.
- La sala assume che esista un piano `ready`. Senza, funziona lo stesso — search,
  valutazioni, registrazione — ma senza rete di sicurezza: la colonna piano lo
  dice esplicitamente invece di mostrarsi vuota.

## Riferimenti

- `briefing.md` §7.3 (replanning), §8.2 (sala d'asta), §9 Fase 3 (acceptance),
  §10 (rischi: replan lento durante le raffiche)
- ADR [0002](0002-two-speed-architecture.md) (architettura a due velocità)
- ADR [0004](0004-valuation-engine-design.md) (motore di valutazione, `max_bid`)
- `resources/prompts/replan.md` (contratto del run di ripianificazione)
- `tests/Feature/Phase3AcceptanceTest.php` (gli acceptance §9 Fase 3, uno per uno)

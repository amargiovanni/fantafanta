# Fase 5 — Rifinitura: specifica (per il lead Fase 5)

Da briefing §9 Fase 5 + debiti dichiarati dalle fasi 1–4. Obiettivo: prodotto FINITO,
collaudabile end-to-end senza asta reale.

## 1. Dark mode (l'asta è di sera)
- Tailwind 4: variante dark con strategia a classe/data-theme sul root (non solo
  prefers-color-scheme): toggle manuale nel layout, persistito (localStorage, Alpine),
  default = prefers-color-scheme.
- Mappare TUTTE le superfici: layout, dashboard, sala d'asta (prioritaria: contrasto alto,
  max_bid leggibilissimo), Conoscenza, Listone, Lega, Import. Palette: slate invertita +
  stessi accenti semantici (emerald/red/amber) con tonalità adeguate.
- Verifica reale su ogni pagina (curl non basta: screenshot o ispezione classi).

## 2. Stampa piano
- Vista piano della dashboard stampabile: @media print (nasconde nav/bottoni, tabella
  compatta per reparto con titolare+prezzi+alternative, versione piano e data in testata).
  Bottone "Stampa piano".

## 3. Empty states ed errori
- Ogni pagina con dati assenti mostra guida all'azione (niente listone → CTA import;
  nessun piano → CTA genera; nessuna squadra → CTA lega; asta non live → stato chiaro
  in sala). Già in parte fatto: passa in rassegna e completa.
- Horizon giù (code ferme): la dashboard lo dice già via healthcheck? Aggiungi check coda
  (Redis ok ma worker fermi = job pending che invecchiano) con avviso.

## 4. Performance pass sala d'asta
- Scheda decisione: query contate nei test (assert numero query, niente N+1); tempo
  server della pagina /asta < 150ms e dell'update di selezione < 50ms su DB dev 450
  giocatori (misura reale, riporta i numeri).
- Polling: verifica che il poll leggero non ricarichi l'albero se la versione non cambia.

## 5. Simulatore d'asta (collaudo senza asta reale)
- `php artisan asta:simulate {--events=30} {--interval=0} {--replan}`:
  su asta live (o ne apre una), estrae giocatori random disponibili (pesati per tier:
  i big escono presto più spesso), simula il vincitore: squadre avversarie con slot
  aperti nel ruolo offrono fino a ~adjusted_value×(0.8–1.3) random, la mia squadra vince
  se il prezzo resta ≤ max_bid del piano (comportamento "Andrea segue il piano"), altrimenti
  vince l'avversario col budget migliore. Registra acquisitions via Acquisition::create
  (funnel unico esistente: observer → promozione → debounce replan).
  - default: replan NON reale (i job restano in coda o Queue::fake via flag interno);
    con --replan lascia girare i run reali (per collaudo con Horizon attivo).
  - --interval=N secondi tra eventi per provare la sala dal vivo mentre gira.
- Log leggibile a fine run: eventi, promozioni scattate, versioni piano create.

## 6. Debiti dichiarati da chiudere ORA
- generate-plan non crea la riga plans `generating` (il replan sì): allinealo.
- wire:poll.5s sull'intera lista Conoscenza → poll leggero (conteggi/versione) come in sala.
- RecomputeValuations senza debounce su raffiche di segnali (ADR 0004): applica il
  pattern Replanner (marker cache + delay breve 5s) o ShouldBeUnique.
- README.md operativo COMPLETO (in italiano): cos'è l'app, prerequisiti (Herd, brew
  services redis/meilisearch), setup da zero, avvio (herd, horizon, scheduler), runbook
  del giorno dell'asta (checklist: servizi su, ai:healthcheck, listone aggiornato, full
  scrape, genera piano, horizon attivo, apri /asta), costi/limiti claude, troubleshooting
  (healthcheck KO, feed giù, replan fermo).
- Nota MySQL groupBy di get_available_players: SOLO documentazione (siamo su SQLite),
  già in ADR? verifica, altrimenti aggiungi riga in ADR 0004.

## 7. Collaudo end-to-end finale (con evidenza nel report)
1. DB dev ripristinato a stato pre-collaudi (o pulito e ripopolato: 450 giocatori, 8
   squadre, config lega, piano v1 reale già esistente va bene).
2. `asta:simulate --events=25 --replan` con Horizon attivo: la simulazione registra
   acquisti veri, le promozioni scattano, il debounce collassa i replan, ALMENO UN replan
   reale claude -p va a buon fine durante la corsa (evidenza ai_runs + versioni piano).
   Tetto: max 3 run reali totali nel collaudo; se la simulazione ne genererebbe di più,
   ferma gli eventi e lascia scaricare la coda.
3. Undo dell'ultimo evento simulato dalla sala (o via codice) → stato coerente.
4. Report: numeri (eventi, versioni piano, durata replan, tempi pagina), problemi trovati
   e corretti.

## Fuori scope (esplicito)
Reverb/websocket, auth, multi-lega, undo profondo, app mobile: no.

# Piano d'acquisto — dottrina e contratto save_plan (per il lead Fase 2)

Da briefing §7.2/§7.3 + §4 "Asta e piano". Il piano È la risposta: mai testo discorsivo
come output primario.

## Struttura dati
- `plans`: auction_id, version (progressivo per auction), trigger (initial/acquisition/manual),
  status (generating/ready/failed), strategy_notes (max 3 righe), budget_summary JSON
  {P: {allocated, spent}, D: ..., C: ..., A: ...}.
- `plan_slots`: 25 righe (3P/8D/8C/6A da config), plan_id, role, slot_index (1-based nel ruolo),
  player_id, target_price, max_price, alternatives JSON [{player_id, target_price} ×≥2],
  slot_status (pending/acquired/lost).

## Validazione dura in save_plan (server-side, briefing §6 — Claude non può corrompere lo stato)
Rifiuta con errore DETTAGLIATO (quale slot, quale regola) se:
1. Slot totali ≠ config (25) o ruoli con conteggio sbagliato.
2. Un player_id compare due volte (tra titolari o tra titolare e alternativa di altri slot: un
   giocatore può essere alternativa di PIÙ slot dello stesso ruolo, ma titolare una sola volta;
   un'alternativa non può essere anche titolare di un altro slot).
3. Titolare di slot: player non available (acquistato da avversario / removed) — ECCEZIONE:
   i MIEI acquired vanno nei loro slot con slot_status=acquired e target_price = prezzo pagato.
4. Ruolo del player ≠ ruolo dello slot (vale anche per le alternative).
5. Alternative < 2 per slot pending (acquired esente).
6. Budget: Σ target_price degli slot pending > crediti residui miei ⇒ rifiuto.
   Inoltre ogni target_price ≥ 1 e max_price ≥ target_price.
7. Ogni slot pending deve poter costare 1: Σ target − max(target_slot) + 1 vincolo implicito
   coperto dal punto 6 (documentare).
I miei acquired DEVONO essere tutti presenti (uno slot ciascuno). Se un vincolo fallisce il tool
ritorna Response::error con l'elenco completo delle violazioni (non solo la prima), così Claude
corregge in un turno.

## Dottrina strategica (contenuto del prompt generate-plan.md — prompt in italiano)
- Allocazione budget per reparto: parti dai default config (P 9, D 21, C 30, A 40 % — lega con
  mod. difesa) ma DECIDI tu la ripartizione finale motivandola in strategy_notes (2-3 righe).
- Struttura tier per reparto: 1-2 top player dove concentrare la spesa; titolari affidabili in
  fascia media; 2-3 scommesse alto upside; tappabuchi da 1 credito a completare.
- Massimizza titolarità attesa (expected_starter) e copri i rigoristi (≥2 rigoristi in rosa).
- Difesa: preferisci concentrare difensori di 1-2 squadre reali con buona fase difensiva +
  il loro portiere titolare (sinergia mod. difesa). Attacco: diversifica le squadre reali
  (turnover/scontri diretti).
- Fairplay: a parità di valutazione preferisci il disciplinato (tie-breaker).
- Per OGNI slot: titolare + ≥2 alternative realistiche con prezzi (asta random: il target può
  sfumare in ogni momento). Le alternative di uno slot devono essere progressivamente più
  economiche o pari, mai più care del max_price dello slot.
- Usa i tool: get_league_state, get_available_players (per tier/ruolo), get_player (dubbi),
  get_budget_analysis (inflazione/scarsità), get_current_plan (se replan). Scrivi con save_plan.

## Replan (replan.md)
- Trigger: acquisizione (debounce 20s, coda ai-replan), manuale, target perso.
- Contratto: SEMPRE rosa completa — acquired nei loro slot, per gli slot aperti nuovo target
  con prezzi aggiornati a crediti residui + inflazione + disponibilità. strategy_notes max 3
  righe su cosa è cambiato.
- Promozione deterministica immediata (PHP, non AI): quando un titolare di slot viene preso da
  altri, lo slot passa a lost e la prima alternativa disponibile è promossa titolare col suo
  target_price, PRIMA che il replan giri. Il replan poi rifinisce. (Fase 3 la collega alla UI;
  il servizio nasce in Fase 2 insieme al piano.)

## Versionamento
- plans è append-only: ogni run di generate/replan crea version = max+1 con status generating →
  ready/failed. La UI mostra sempre l'ultima ready; se una più recente è generating, badge
  "ricalcolo in corso" (Fase 3).

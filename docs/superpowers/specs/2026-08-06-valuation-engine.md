# ValuationEngine — Specifica algoritmica (per il lead Fase 2)

Autore: PO/architetto. Deriva da briefing §5. Il motore è PHP puro, deterministico,
ricalcolo completo del listone in coda (<10s), risultati persistiti in `valuations`.
Ogni numero qui sotto è un default esplicito: costanti nominate in una config
`config/valuation.php`, mai magic numbers sparsi. Ogni componente ha test Pest dedicati.

## Input
- Player: quotazione (Qt.A), fvm, season_stats (fantamedia fm, media voto mv, presenze pv, ...), role, expected_starter (0–1), is_rigorista, status.
- Signals attivi: non superseded, non needs_review, con confidence, impact (-2..+2), event_date, type, payload (es. durata infortunio).
- LeagueConfig: slots {P:3,D:8,C:8,A:6}, total_credits, teams_count, modifier_defense, modifier_fairplay.
- Stato asta: acquisitions (prezzi reali pagati), teams credits/slot residui.

## 1. Base value (pre-asta, ricalcolo su import/config)
- `raw_score = 0.5·fvm_norm + 0.3·qt_norm + 0.2·perf_norm`
  - fvm_norm = fvm / max(fvm nel ruolo); qt_norm = quotazione / max(qt nel ruolo);
  - perf_norm = clamp((fantamedia − 4.5)/3, 0, 1) · min(presenze/25, 1); se stats assenti → perf_norm = qt_norm (proxy).
- Scala a crediti: `credit_pool = teams_count × total_credits`. Ripartizione teorica del pool per ruolo (lega CON mod. difesa): P 9%, D 21%, C 30%, A 40% (costanti config; senza modificatore sarebbero P 7%, D 17%, C 30%, A 46%).
- Dentro ogni ruolo: `base_value_i = pool_ruolo × raw_score_i / Σ raw_score` calcolato SOLO sui primi (teams_count × slots_ruolo) giocatori per raw_score (i comprabili); gli altri ricevono il valore per interpolazione ma con floor 1.
- Floor: ogni base_value ≥ 1.

## 2. Aggiustamento segnali → adjusted_value
- Per ogni segnale attivo: `w = impact/2 × confidence × decay` con `decay = max(0.25, 1 − giorni_da_event_date/45)` (i segnali pre-asta invecchiano lentamente).
- Casi speciali tipizzati (dal payload):
  - `infortunio` con durata stimata ≥ 4 mesi → moltiplicatore diretto 0.15 (quasi azzera, briefing §5.2); 2–4 mesi → 0.5; < 2 mesi → tramite w standard.
  - `rigorista` nuovo → oltre al w, setta is_rigorista effettivo nel calcolo (bonus +12% attaccanti/centrocampisti).
  - `mercato_out` (lascia la Serie A) confermato con confidence ≥ 0.8 → adjusted_value = 1.
- Combinazione: `adjusted = base × Π moltiplicatori_speciali × (1 + clamp(Σ w, −0.6, +0.6))`.
- expected_starter: `adjusted ×= (0.6 + 0.4 × expected_starter)` — un titolare fisso vale pieno, una riserva ~60%.

## 3. Modificatori di lega
- Mod. difesa ON: portieri e difensori con mv ≥ 6.0 e expected_starter ≥ 0.7 → `adjusted ×= 1 + 0.05 + 0.05×(mv − 6.0)/0.5` (cap +20%). Il portiere titolare di big (mv ≥ 6.2) rientra qui naturalmente.
- Mod. fairplay ON: `ammonizioni/presenza ≥ 0.35` → `adjusted ×= 0.97` (tie-breaker, mai driver — briefing §2).

## 4. Inflazione live (durante l'asta)
- Per ruolo r: `inflation_r = Σ prezzi_pagati_r / Σ adjusted_value_al_momento_r` sui giocatori già assegnati (min 3 acquisti nel ruolo per attivarla, altrimenti 1.0; clamp [0.7, 1.6]).
- Ammortizzata: `eff_inflation_r = 1 + (inflation_r − 1) × 0.7` (non inseguire i picchi).

## 5. Scarsità
- Tier: quintili di adjusted_value per ruolo tra i disponibili → tier 1 (top) … 5.
- `scarcity_index = domanda/offerta`: domanda = Σ slot aperti nel ruolo delle squadre AVVERSARIE con crediti medi ≥ mediana; offerta = disponibili di tier ≤ tier_player nel ruolo. Clamp [0.5, 3].
- Effetto SOLO sui giocatori nel piano corrente (target o alternativa): `max_bid_bonus = 1 + 0.1 × clamp(scarcity_index − 1, 0, 1)`.

## 6. max_bid
- `max_bid_raw = adjusted × eff_inflation_r × max_bid_bonus` (bonus solo se nel piano).
- Vincolo budget assoluto: `max_bid = min(max_bid_raw, my_credits_left − (my_open_slots − 1))` (ogni slot costa ≥1).
- max_bid intero, ≥ 1 se lo slot è riempibile, 0 se status ≠ available.

## Persistenza `valuations`
player_id, base_value, adjusted_value, max_bid, tier, scarcity_index, computed_at.
Ricalcolo completo (job in coda, trigger: import, segnale nuovo/modificato, acquisizione, modifica config)
DEVE stare sotto 10s su ~600 giocatori: una sola query per tabella coinvolta, niente N+1,
upsert massivo.

## Test obbligatori (fixture listone ridotto)
1. Ripartizione pool per ruolo rispetta le percentuali config (±1 credito da arrotondamento).
2. Infortunio lungo (5 mesi, confidence 0.9) → adjusted < 20% del base; il giocatore precipita di tier.
3. "Rientro" che supera "infortunio" → il malus sparisce al ricalcolo.
4. Mod. difesa: difensore titolare mv 6.5 guadagna vs stesso senza modificatore; cap +20% rispettato.
5. Inflazione: 3 attaccanti pagati +30% sul valore → max_bid attaccanti restanti sale di ~+21% (0.7 ammortizzazione), clamp rispettato.
6. Vincolo budget: mai max_bid > crediti − (slot aperti − 1), su casi limite (ultimo slot, 1 credito).
7. Determinismo: stesso input → stesso output, due run identici.
8. Performance: ricalcolo 600 player < 10s (test con assertion su tempo, tollerante in CI).

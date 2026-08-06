# Fanta Asta AI — Piano operativo

Fonti: `briefing.md` (spec) + `docs/superpowers/specs/2026-08-06-fanta-asta-design.md` (deviazioni D1–D5).
Regola: la fase N+1 non parte con acceptance della N aperti. Ogni fase chiude con
`php artisan test` verde e `vendor/bin/pint --test` pulito, eseguiti dal PO.

## Fase 0 — Fondamenta
- [x] Scaffold Laravel 12 (Herd, `fanta-asta.test`, SQLite), Pest 4, Pint, strict mode Eloquent in dev
- [x] Horizon + Redis (code: default, ai, ai-replan, scraping), Scout + Meilisearch
- [x] Migration + model: players, player_aliases, league_config, teams (tutte reversibili, rollback verificato)
- [x] Import CSV listone fantacalcio.it: mapping colonne con anteprima, normalizzazione nomi, alias automatici, re-import idempotente su alias/segnali
- [x] Ricerca fuzzy player (Scout/Meilisearch + alias): "lautaro" / "Martinez L." / "martinez lautaro" → stesso player
- [x] Setup lega (league_config singleton) + CRUD squadre
- [x] ADR: 0001 stack e deviazioni dal briefing (D1–D4), 0002 due velocità
- [x] Gate: acceptance Fase 0 del briefing §9 + test Pest su import/normalizzazione

## Fase 1 — Conoscenza
- [x] Migration + model: sources, signals, ai_runs, scrape_targets (seed testate)
- [x] Server MCP `fanta-asta` (laravel/mcp): tool read + save_signals + resolve_player_name, validazione server-side
- [x] Job RunClaudeTask (`claude -p`, timeout 300s, retry 1, audit ai_runs) + `.mcp.json`
- [x] Prompt `resources/prompts/extract-signals.md`
- [x] Backoffice ingestion: drop zone universale (pdf/link/testo/nota), lista sources con stato, coda needs_review, vista segnali per player con correzione manuale
- [x] Parsing PDF (smalot/pdfparser) e readability per link
- [x] `ai:healthcheck` (claude CLI + redis + meilisearch) + stato in dashboard
- [x] Gate: acceptance Fase 1 §9 (segnale da articolo, needs_review, superseded, ai_runs, PDF)

## Fase 2 — Cervello
- [ ] ValuationEngine (PHP puro): base value, segnali, modificatori, inflazione, scarsità, vincolo budget; ricalcolo listone <10s
- [ ] Migration + model: auctions, acquisitions, plans, plan_slots, valuations
- [ ] Tool MCP: get_league_state, get_available_players, get_player, get_signals, get_current_plan, get_auction_log, get_budget_analysis, save_plan (validazione dura)
- [ ] Prompt `generate-plan.md` con dottrina strategica (modificatore difesa ⇒ più budget P+D, tier, ≥2 alternative/slot, rigoristi, diversificazione attacco)
- [ ] Dashboard pre-asta (piano leggibile/stampabile, top segnali, salute pipeline, bottoni azione)
- [ ] Gate: acceptance Fase 2 §9 (25 slot validi, piano invalido rifiutato→corretto, infortunio abbassa adjusted_value, <10s)

## Fase 3 — Sala d'asta
- [ ] UI live tre colonne (Livewire 4): search sempre a fuoco, scheda decisione con max_bid enorme, colonna piano vivo, colonna lega, barra mia squadra
- [ ] Registrazione tastiera-only ≤3s (prezzo → tasto squadra 1–9/0) + undo soft-delete
- [ ] Promozione deterministica immediata dell'alternativa su slot perso; replan automatico debounced 20s su coda ai-replan; polling versione piano
- [ ] Inflazione live nel motore + max_bid ≤ crediti residui − (slot aperti − 1)
- [ ] Prompt `replan.md` (sempre rosa completa, strategy_notes ≤3 righe)
- [ ] Gate: acceptance Fase 3 §9

## Fase 4 — Scraping automatico
- [ ] Scheduler 30min su scrape_targets (RSS prima, crawl fallback), dedup hash+titolo
- [ ] Full scrape on demand con batch progress Horizon
- [ ] Rate limit 1req/2s per dominio, robots.txt, user-agent, circuit breaker
- [ ] Gate: acceptance Fase 4 §9

## Fase 5 — Rifinitura
- [ ] Dark mode, stampa piano, empty states, performance pass sala d'asta
- [ ] Simulatore d'asta (estrazioni random + acquisti finti) per collaudo replan sotto carico
- [ ] README operativo (avvio servizi, healthcheck pre-asta)
- [ ] Gate: acceptance Fase 5 + collaudo end-to-end con simulatore

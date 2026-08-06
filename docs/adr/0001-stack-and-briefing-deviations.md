# 0001. Stack di progetto e deviazioni dal briefing

**Status**: Proposed
**Date**: 2026-08-06
**Deciders**: [da confermare — Claude come estensore, Andrea (PO) come decisore]

## Contesto

`briefing.md` v1.0 (§3 "Stack tecnico") specifica Laravel 12, Livewire 3,
Pest (versione non detta), Redis/Meilisearch "via Herd". Al momento dello
scaffolding (2026-08-06) l'ambiente reale e il floor di stack del workspace
(`CLAUDE.md`, verificato 2026-03-05) impongono minimi diversi e più recenti:
Laravel 12.x come minimo non-pin, Pest 4.x, Livewire 4.x, Tailwind 4.x. Questi
minimi non sono negoziabili per un progetto nuovo (CLAUDE.md: "Upgrading an
existing project off these floors is a planned task, never a drive-by" — qui
vale il contrario, si parte già sul floor).

Inoltre l'ambiente macOS reale ha Herd Free, che non include Redis/Meilisearch
come servizi gestiti: vanno installati e avviati via Homebrew.

`composer create-project` con i vincoli di versione richiesti (Horizon 5,
Scout 11, Livewire 4.3, laravel/mcp 0.9.1, Pest 4) risolve Laravel 13.24, non
12.x: è comunque conforme al floor "12.x minimo, non pin" del workspace.

## Decisione

Adottiamo lo stack risolto dall'ambiente reale, deviando da quanto scritto nel
briefing dove il floor di workspace lo richiede:

| # | Briefing | Decisione | Motivo |
|---|---|---|---|
| D1 | Livewire 3 | **Livewire 4.3** | Floor di stack workspace. Routing full-page via `Route::livewire()`/component class-based invece di `Route::get()->layout()` (v3); `wire:poll` invariato. |
| D2 | Tailwind (implicito 3) | **Tailwind 4** | Floor di stack. Config CSS-first (`@theme` in `resources/css/app.css`), nessun `tailwind.config.js`. |
| D3 | Pest non specificato | **Pest 4** | Floor di stack. |
| D4 | Redis/Meilisearch "via Herd" | **Servizi Homebrew** (`brew services start redis\|meilisearch`) | Herd Free non include questi servizi; configurazione lato Laravel identica (host/porta invariati). |
| D5 | — (nessuna menzione) | **`ai:healthcheck` verifica anche Redis e Meilisearch**, non solo `claude` | Essendo demoni brew indipendenti da Herd, un servizio giù il giorno dell'asta è il rischio operativo più alto del progetto (vedi briefing §10, design doc §5). |
| D6 | Laravel 12 | **Laravel 13.24** | `composer create-project` con i vincoli di versione richiesti risolve la 13; è comunque ≥12.x (floor rispettato). Verificato dal lockfile, non da memoria. |

## Alternative considerate

- **Pinnare Laravel 12.x esatto ignorando il floor di workspace** — scartata:
  violerebbe esplicitamente CLAUDE.md ("un progetto nuovo non parte su una
  versione contro cui abbiamo deciso").
  Livewire 3 per aderenza letterale al briefing — scartata: stesso motivo,
  floor di workspace non negoziabile per progetti nuovi; inoltre Livewire 3
  non è più installabile pulito accanto a Laravel 13/Filament 5 nel medio
  periodo.
- **Reverb per il realtime al posto del polling** — non valutata in questa
  fase: il briefing la esclude esplicitamente come "opzionale in v2", nessuna
  deviazione necessaria.

## Conseguenze

**Positive:**
- Il progetto parte allineato ai floor di stack correnti, senza debito tecnico
  dichiarato fin dal giorno 1.
- `ai:healthcheck` (Fase 1) copre il vero rischio operativo (servizi brew), non
  solo l'autenticazione di `claude`.

**Negative / obblighi creati:**
- Ogni pattern Livewire scritto nel briefing (§8, esempi impliciti v3) va
  riletto e verificato sulla documentazione v4 reale in `vendor/livewire`,
  mai a memoria (rischio già annotato nel design doc, D "Livewire 4 API
  drift").
- Il README operativo (Fase 5) deve documentare l'avvio dei servizi brew, non
  l'assunzione "Herd li fornisce già".

## Riferimenti

- `briefing.md` §3, §10
- `docs/superpowers/specs/2026-08-06-fanta-asta-design.md` §1 (D1-D6), §2, §5
- `CLAUDE.md` (workspace), sezione "Stack floors — new projects"

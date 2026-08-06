# Fanta Asta AI — Design consolidato

> Data: 2026-08-06. La specifica funzionale completa è [`briefing.md`](../../../briefing.md) (v1.0),
> che questo documento NON duplica: qui vivono solo le decisioni di consolidamento,
> le deviazioni motivate dal briefing e i vincoli operativi rilevati sull'ambiente reale.
> In caso di conflitto: CLAUDE.md di workspace > questo documento > briefing.

## 1. Deviazioni dal briefing (motivate)

| # | Briefing dice | Decisione | Motivo |
|---|---|---|---|
| D1 | Livewire 3 | **Livewire 4** | Floor di stack del workspace (CLAUDE.md, verificato 2026-03): Livewire 4.x è il minimo per progetti nuovi. Il polling della sala d'asta usa `wire:poll`, invariato. |
| D2 | Tailwind (implicito 3) | **Tailwind 4** (`@theme` in CSS, niente `tailwind.config.js`) | Stesso floor. |
| D3 | Pest (versione non detta) | **Pest 4** | Floor di stack. |
| D4 | Redis/Meilisearch "via Herd" | **Homebrew services** (`brew services start redis|meilisearch`) | L'ambiente reale ha Herd free, che non include servizi. Config identica lato Laravel. |
| D5 | — | Healthcheck `ai:healthcheck` verifica anche Redis e Meilisearch, non solo `claude` | I servizi ora sono demoni brew: un servizio giù il giorno dell'asta è il rischio operativo n.1. |

## 2. Ambiente verificato (2026-08-06)

- macOS, Herd 1.29 (free), PHP 8.4.20, Composer 2.10, sqlite3 di sistema.
- `claude` CLI 2.1.220 autenticato in `~/.local/bin/claude` — l'integrazione AI è `claude -p`, mai API key.
- Redis 8.x e Meilisearch installati via Homebrew come servizi.
- Dominio locale: `fanta-asta.test` (herd link + secure).

## 3. Architettura (conferma del briefing)

Due velocità, non negoziabile:

- **Percorso sincrono d'asta**: solo letture da `valuations` e tabelle già calcolate. Nessuna chiamata AI, nessun ricalcolo pesante inline. Budget <50ms.
- **Percorso asincrono**: code Horizon (Redis). Tre code: `ai-replan` (priorità massima), `ai` (estrazione segnali, piano iniziale), `scraping` + `default`. Il ricalcolo `valuations` (ValuationEngine, PHP puro) gira su coda ma completa in secondi; la promozione deterministica dell'alternativa di uno slot perso avviene **sincrona** alla registrazione dell'acquisto (rete di sicurezza pre-replan, come da briefing §9 Fase 3).

Componenti (dal briefing, invariati): modello dati §4, ValuationEngine §5, server MCP `fanta-asta` §6 (package ufficiale `laravel/mcp`), pipeline AI §7 con prompt versionati in `resources/prompts/*.md`, UX a tre aree §8.

## 4. Delivery

Fasi 0–5 del briefing §9, in ordine, con i loro acceptance criteria come gate: non si apre la fase N+1 con criteri della N aperti. Ogni fase è condotta da un agente lead che delega e revisiona; il PO (sessione principale) verifica ogni gate rieseguendo `php artisan test` e `vendor/bin/pint --test` e ispezionando a campione.

Il dettaglio operativo dei task è in [`tasks/todo.md`](../../../tasks/todo.md).

## 5. Rischi operativi aggiunti

| Rischio | Mitigazione |
|---|---|
| Servizio brew (redis/meili) non attivo il giorno dell'asta | `ai:healthcheck` li verifica; dashboard mostra lo stato; documentato il comando di ripristino in README |
| Livewire 4 API drift rispetto a esempi Livewire 3 | I lead verificano sulla documentazione installata (vendor) e con test feature, non su memoria |
| `claude -p` lento/limitato durante raffiche di replan | debounce 20s (briefing §7.3) + coda dedicata + la UI mantiene la versione precedente marcata |

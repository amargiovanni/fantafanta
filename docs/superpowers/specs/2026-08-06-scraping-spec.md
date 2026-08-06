# Scraping — Specifica (per il lead Fase 4)

Da briefing §7.4 + §9 Fase 4 + §10. Vincolo trasversale: NIENTE nuovi pacchetti
(D7): RSS con SimpleXML, HTML con DOMDocument/XPath, HTTP con il client Laravel (Guzzle).

## Architettura
- `App\Scraping\ScrapeRunner`: orchestratore. Per ogni scrape_target abilitato e con
  circuito chiuso → prova RSS (`rss_url`), fallback crawl HTML della pagina news (`url`).
  Il fallimento di UNA testata non ferma le altre (try/catch per target, log).
- Parser isolati: interfaccia `TargetParser` con `discover(): array<ArticleRef>` e
  `extract(ArticleRef): ?ArticleContent`; implementazioni `RssParser` (SimpleXML) e
  `HtmlListParser` (euristica: link stesso dominio sotto sezioni news, dedup URL).
  Registry per-testata (config) se una testata richiede un parser dedicato in futuro.
- Ogni articolo nuovo → `sources` (type scraped_article, origin scheduled_scrape o
  full_scrape, url, title, content_hash) → pipeline extract-signals ESISTENTE.

## Dedup (due livelli, prima di creare la source)
1. `content_hash` (già unique su sources).
2. Similarità titolo: titolo normalizzato (NameNormalizer-like) vs sources degli ultimi
   7 giorni; `similar_text` ≥ 85% → duplicato, non creare. Test con lo stesso articolo
   da due testate: una sola source processata (e la confidence dei segnali sale via
   SignalWriter, già esistente).

## Etica e robustezza (briefing §7.4)
- User-agent identificato: "FantaAstaBot/1.0 (+uso personale, contatto locale)".
- robots.txt: fetch+cache 24h per dominio, rispetto di Disallow per UA * (parser nativo
  semplice: prefix match dei path). URL disallow → skip con log.
- Rate limit per dominio: min 2s tra richieste (cache lock con timestamp ultima richiesta,
  sleep/re-dispatch se troppo presto). Vale anche nel full scrape.
- Backoff su 429/5xx: il job dell'articolo ritenta con backoff esponenziale (30s, 120s);
  429 conta come fallimento di circuito.
- Circuit breaker per target: 5 fallimenti consecutivi → circuito aperto 30 minuti
  (cache); i run schedulati saltano i circuiti aperti; contatore azzerato al successo.
  Stato del circuito visibile in backoffice.

## Scheduler
- `schedule` Laravel: ogni 30 minuti (configurabile in config/fanta.php) →
  `RunScheduledScrape` (coda scraping) su tutte le testate abilitate.
- last_scraped_at aggiornato per target; nel run schedulato considera solo articoli
  più recenti dell'ultima finestra (o non ancora visti per hash/URL).

## Full scrape on demand
- Bottone in backoffice → `Bus::batch` di job per testata (coda scraping), finestra
  configurabile default 7 giorni, con paginazione archivi dove il parser la supporta
  (RSS: tutto il feed; HTML: prime N pagine lista, N=3 default).
- Barra avanzamento in UI (polling sul progress del batch Horizon). Non blocca il resto.
- Cancellabile (batch cancel).

## Tetto di spesa estrazioni
Ogni articolo nuovo = un run claude a pagamento. `config/fanta.php`:
`max_extractions_per_scrape` (default 20). Oltre il tetto le sources restano `queued`
con nota visibile in backoffice ("in attesa: tetto estrazioni raggiunto") e un bottone
"processa comunque". Il tetto NON si applica agli upload manuali.

## Backoffice
- Gestione scrape_targets: CRUD (nome, url, rss_url, enabled), stato circuito, ultimo
  scrape, articoli trovati ultimo run.
- Bottone "Scrape ora" per singola testata + "Full scrape" globale con progress.

## Test (Http::fake sempre; mai rete reale nei test)
1. RSS con 3 articoli, 1 già noto per hash → 2 sources nuove, origin corretta.
2. Stesso articolo da due testate (titoli quasi identici) → una sola source.
3. Fallback HTML quando rss_url è null → link articolo scoperti ed estratti.
4. 429 → backoff, nessun martellamento (assert numero richieste), circuito che si apre
   dopo 5 fallimenti e si chiude dopo il cooldown.
5. robots.txt con Disallow → URL saltato.
6. Rate limit: due richieste stesso dominio distano ≥2s (time fake).
7. Full scrape: batch con progress, cancellazione, una testata rotta non ferma le altre.
8. Tetto estrazioni: 25 articoli nuovi, cap 20 → 20 in pipeline, 5 queued con nota.

## Collaudo reale
UN giro di scraping schedulato reale sulle testate seed con RSS verificati (FantaMaster,
SOS Fanta, Gazzetta): mostra sources create (titoli reali). Estrazione segnali reale
LIMITATA a 1 articolo (cap temporaneo a 1), il resto resta queued: evidenza ai_runs +
segnali. Rispetta i rate limit. Se un feed è giù, riportalo e prosegui con gli altri.

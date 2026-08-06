# 0006. Architettura dello scraping automatico: parser isolati, circuit breaker, tetto di spesa

**Status**: Proposed
**Date**: 2026-08-07
**Deciders**: [da confermare — Claude come estensore, Andrea (PO) come decisore]

## Contesto

La Fase 4 introduce la prima pipeline dell'applicazione che parla con server che
Andrea non controlla: le testate fantacalcistiche. Tre vincoli non trattabili,
dati dal briefing (§7.4, §9 Fase 4, §10) e dalla spec vincolante di fase
(`docs/superpowers/specs/2026-08-06-scraping-spec.md`):

- **Nessun pacchetto composer nuovo** (deviazione D7 del design): niente
  readability/DomCrawler, niente client RSS dedicato. RSS via SimpleXML,
  HTML via `DOMDocument`/`DOMXPath`, HTTP via il client Laravel — tutta
  standard library o già presente.
- **Etica dello scraping non negoziabile**: user-agent identificato, rispetto
  di robots.txt, rate limit per dominio, circuit breaker su errori ripetuti.
  Un progetto per uso personale non ha una scusa per comportarsi da bot
  aggressivo verso siti di terzi.
- **Ogni articolo nuovo è un run Claude a pagamento**: senza un tetto, una
  giornata di calciomercato movimentata potrebbe innescare decine di
  esecuzioni non controllate.

A questo si aggiunge un vincolo di progetto più ampio: il fallimento di una
testata non deve mai fermare le altre (rischio esplicitamente elencato nel
briefing §10, "scraping fragile"), e la pipeline di estrazione segnali
esistente dalla Fase 1 (`ProcessSource` → `RunClaudeTask` → `SignalWriter`) è
già costruita, testata e non va duplicata: un articolo scaricato deve entrare
nella stessa `sources` di un link incollato a mano.

## Decisione

### 1. Parser isolati dietro un'interfaccia comune

`App\Scraping\TargetParser` dichiara `discover(): array<ArticleRef>` (scopre
gli articoli senza scaricarli) ed `extract(ArticleRef): ?ArticleContent`
(rifinisce il titolo prima che una source venga creata). Due implementazioni:

- `RssParser` — SimpleXML su `<channel><item>` (RSS 2.0, il formato di tutti i
  feed verificati nel seed). Il titolo del feed è già affidabile: `extract()`
  non fa una seconda richiesta HTTP, si limita a incapsularlo.
- `HtmlListParser` — euristica DOMDocument/XPath sui link `<a>` dello stesso
  dominio con testo d'ancora abbastanza lungo da essere un titolo (stesso
  stile di `ArticleExtractor`, già in produzione dalla Fase 1). `extract()`
  qui SÌ visita la pagina dell'articolo, perché il testo d'ancora di una lista
  è spesso troncato o tutto maiuscolo e la dedup per titolo ha bisogno di un
  titolo vero.

`App\Scraping\ParserRegistry` sceglie RSS se `rss_url` è valorizzato,
altrimenti HTML; `ScrapeRunner` tenta il fallback HTML anche quando l'RSS è
configurato ma non restituisce nulla (feed rotto o vuoto). Un
`parser_overrides` in `config/fanta.php` (chiave `scrape_targets.id` → class
string) resta vuoto oggi ma predispone un parser dedicato per una testata
difficile, senza toccare `ScrapeRunner` quel giorno.

### 2. Dedup a due livelli, PRIMA di spendere un run Claude

Il livello 1 (hash del contenuto, vincolo unique su `sources.content_hash`)
esiste dalla Fase 1 in `ProcessSource` e non cambia. Il livello 2, nuovo, è
`App\Scraping\Support\TitleDeduper`: confronta il titolo normalizzato
(`App\Support\NameNormalizer`, già usato per i nomi giocatore — la stessa
pipeline lowercase/ASCII/spazi-collassati funziona altrettanto bene su un
titolo) contro quelli delle source degli ultimi 7 giorni con `similar_text()`
nativo, soglia 85%. Questo controllo avviene in `ScrapeRunner` **prima** di
creare la riga `sources`, non dopo: la stessa breaking news ripresa da due
testate con un titolo quasi identico produce una sola source, non due
processate in parallelo.

Un titolo sufficientemente diverso sulla stessa notizia (taglio giornalistico
diverso) NON viene deduplicato: qui la corroborazione del segnale
(`SignalWriter`, già esistente e testata dalla Fase 1) fa il suo lavoro sulle
due source distinte, alzando la confidence — la dedup di fase 4 e la
corroborazione di fase 1 coprono due casi diversi e complementari.

### 3. Etica e robustezza dietro un unico varco HTTP

`App\Scraping\Support\ScrapingHttpClient::get()` è l'unico punto da cui parte
una richiesta HTTP dello scraping. Attraversa, in ordine: circuito della
testata (`CircuitBreaker`) → robots.txt (`RobotsGuard`, cache 24h, parser
prefix-match volutamente semplice su `User-agent: *`) → rate limit di dominio
(`DomainRateLimiter`, 2s minimo, attesa via `Illuminate\Support\Sleep` —
fakeabile nei test, non un `sleep()` nudo) → la richiesta vera, con backoff
30s/120s su 429/5xx (`Http::retry()` nativo di Laravel, che ritenta anche
sulle risposte non 2xx, non solo sulle eccezioni di trasporto).

Tutti e tre i servizi di supporto tengono lo stato in cache con lettura-e-
riscrittura semplice, non `Cache::lock`: lo stesso stile del marker di
debounce di `Replanner` (ADR precedente, mai formalizzato ma consolidato in
codice). Non serve un lock perché lo scraping di una singola testata non gira
mai in parallelo con se stesso — un solo job per il giro schedulato, un job
per testata nel full scrape, mai due job sulla STESSA testata insieme.

Il circuit breaker (`CircuitBreaker`) apre dopo 5 fallimenti consecutivi per
30 minuti (configurabile), si richiude da solo al primo controllo dopo il
cooldown, un successo azzera tutto (non solo il contatore). Lo stato non è
una colonna di `scrape_targets`: è per natura effimero, e persisterlo in DB
significherebbe scrivere e sincronizzare uno stato che si autocorregge da
solo — la stessa scelta già fatta per il marker di debounce del replan.

### 4. Il fetch del contenuto di un articolo scaricato passa dallo stesso varco

Decisione chirurgica sul codice esistente: `App\Jobs\ProcessSource` (Fase 1)
ora distingue `SourceType::ScrapedArticle` da `SourceType::Link`. Per un
articolo scaricato, il fetch del contenuto (che avviene quando la source
arriva in cima alla coda `ai`, non durante la discovery) passa da
`ScrapingHttpClient` — quindi rate limit, robots.txt e circuito si applicano
anche lì. Un link incollato a mano da Andrea resta sul fetch "nudo" di sempre,
invariato.

Il motivo è testuale nella spec: "il job dell'articolo ritenta con backoff
esponenziale... 429 conta come fallimento di circuito" tratta esplicitamente
il fetch del contenuto, non solo la fase di scoperta, come soggetto alle
stesse regole. Applicarle anche al link manuale sarebbe stato scorretto nella
direzione opposta: un singolo link che Andrea incolla deliberatamente non è
crawling e non deve essere rallentato da un rate limit pensato per una
scansione automatica ripetuta.

### 5. Tetto di spesa condiviso per giro, non per testata

`App\Scraping\Support\ExtractionGate` tiene un contatore atomico in cache
(`Cache::increment`, atomico su Redis) chiavato per `runId`: un uuid per il
giro schedulato o per un "scrape ora" singolo, l'id del batch Horizon per il
full scrape. La chiave per il batch è deliberata: i job del full scrape
girano uno per testata, potenzialmente in parallelo, e il tetto
(`max_extractions_per_scrape`, default 20) deve essere condiviso fra tutti,
non uno per testata — altrimenti 8 testate abilitate potrebbero produrre fino
a 160 run invece di 20. Oltre il tetto la source resta `queued` con un
`queue_note` leggibile e un bottone "processa comunque" in backoffice; il
tetto non tocca gli upload manuali, che non passano da `ExtractionGate`.

### 6. Scheduler sequenziale, full scrape a batch

Il giro schedulato (`App\Jobs\RunScheduledScrape`, nessun argomento) è un
solo job che scandisce tutte le testate abilitate in sequenza, con try/catch
per ciascuna. Non un job per testata: lo scraping schedulato non ha bisogno
del parallelismo del full scrape, e tenerlo sequenziale rende banale il tetto
di estrazioni (un contatore dentro un solo giro, senza coordinamento fra job
concorrenti). Il full scrape on demand usa invece `Bus::batch` — un job
`ScrapeTargetFull` per testata, `allowFailures()` così il fallimento di una
non cancella le altre, id del batch attivo tenuto in cache così la barra di
avanzamento in backoffice sopravvive a un refresh della pagina — perché la
spec lo richiede esplicitamente e perché lì il parallelismo fra testate è
un beneficio reale (domini diversi, nessuna contesa sul rate limit).

## Alternative considerate

- **`Cache::lock` per rate limit e circuito** — scartata: nessun precedente
  nel codice (`Replanner` usa lettura-scrittura semplice), e non serve perché
  una testata non gira mai in parallelo con se stessa.
- **Un job per testata anche nel giro schedulato** — scartata: avrebbe
  richiesto coordinare il tetto di estrazioni fra job concorrenti anche lì,
  complessità non giustificata da un bisogno reale di parallelismo (lo
  scraping schedulato non è sotto pressione di tempo come il full scrape).
- **Applicare rate limit/robots/circuito anche al link incollato a mano** —
  scartata: un singolo fetch deliberato dell'utente non è crawling; sarebbe
  stato un rallentamento ingiustificato dell'esperienza del drop zone.
- **Tetto di estrazioni per testata invece che per giro** — scartata:
  moltiplicherebbe il costo massimo per il numero di testate abilitate,
  vanificando lo scopo del tetto.
- **Stato del circuito su colonna di `scrape_targets`** — scartata: è uno
  stato che si autocorregge dopo il cooldown, scriverlo in DB significherebbe
  tenere sincronizzati due posti per un dato effimero per natura.

## Conseguenze

**Positive:**
- Il fallimento di una testata (feed rotto, sito giù, 500 persistenti) non
  può mai propagarsi alle altre: ogni livello (parser, `ScrapeRunner`,
  scheduler) cattura le proprie eccezioni.
- Un articolo ripreso da più testate con titolo quasi identico costa un solo
  run Claude, non uno a testata.
- Il tetto di spesa è verificabile e visibile: nessuna sorpresa in bolletta
  di sottoscrizione dopo una giornata di mercato movimentata.
- La pipeline di estrazione esistente (Fase 1) non è stata toccata: uno
  scraped article è, dal punto di vista di `RunClaudeTask` in poi, indistinguibile
  da un link incollato a mano.

**Negative / obblighi creati:**
- Il backoffice deve leggere lo stato del circuito dalla cache, non da una
  query SQL: un `cache:clear` in produzione azzera anche i circuiti aperti
  (accettabile — riparte da zero, non da uno stato scorretto).
- `parser_overrides` è un'infrastruttura pronta ma inutilizzata: va tenuta
  d'occhio perché non diventi dead code dimenticato se nessuna testata la
  userà mai.
- Il tetto di estrazioni è un compromesso di prodotto reale: in una giornata
  di mercato con più di 20 articoli nuovi, alcune source restano in coda
  finché Andrea non clicca "processa comunque" o arriva il prossimo giro
  schedulato con un tetto fresco. È una scelta esplicita, non un bug.
- `ProcessSource` ora dipende da `ScrapingHttpClient` per un ramo del suo
  comportamento: chi lo modifica in futuro deve sapere che il percorso
  `ScrapedArticle` porta con sé circuito, robots.txt e rate limit — non è più
  un fetch "nudo" come `Link`.

## Riferimenti

- `docs/superpowers/specs/2026-08-06-scraping-spec.md` (specifica vincolante
  di Fase 4)
- `briefing.md` §7.4 (scraping schedulato e completo), §9 Fase 4 (acceptance),
  §10 (rischio "scraping fragile")
- ADR [0002](0002-two-speed-architecture.md) — lo scraping gira interamente
  sul lato asincrono, non tocca mai il percorso a caldo della sala d'asta
- ADR [0003](0003-claude-code-headless-and-mcp.md) — `RunClaudeTask`/`ai_runs`
  riusati senza modifiche: uno scraped article entra nello stesso audit trail
  di qualunque altra source

# 0007. Strategia del tema scuro: attributo persistito, non preferenza di sistema

**Status**: Proposed
**Date**: 2026-08-07
**Deciders**: [da confermare — Claude come estensore, Andrea (PO) come decisore]

## Contesto

La Fase 5 (spec `docs/superpowers/specs/2026-08-07-phase5-polish.md`, §1) chiede
un tema scuro completo, con un vincolo esplicito: "toggle manuale nel layout,
persistito, default = prefers-color-scheme". La sala d'asta si usa la sera,
spesso con la luce spenta — è la superficie che più conta debba restare
leggibile, in particolare il `max_bid`, il numero su cui si decide in pochi
secondi.

Tailwind CSS 4 offre `dark:` pronto all'uso, ma legato di default a
`prefers-color-scheme`: la preferenza del sistema operativo, non scelta
dall'utente dentro l'app. Un toggle manuale persistito è per definizione
un'*eccezione* alla preferenza di sistema — chi ha il sistema in chiaro ma
vuole l'app scura durante l'asta deve poterlo fare, e la scelta deve
sopravvivere alla chiusura della scheda.

## Decisione

### 1. `dark:` legato a un attributo, non alla media query

`resources/css/app.css` ridefinisce la variante con la sintassi CSS-first di
Tailwind 4:

```css
@custom-variant dark (&:where([data-theme="dark"], [data-theme="dark"] *));
```

Ogni utility `dark:*` diventa condizionata dalla presenza di
`data-theme="dark"` su un antenato (tipicamente `<html>`), non più dalla
media query. La preferenza di sistema resta il *default*, non il canale
esclusivo.

### 2. Attributo su `<html>`, non classe su `<body>`

Si usa `data-theme="dark"|"light"` invece della convenzione più comune
`class="dark"`. Un attributo dedicato non collide con altre classi
eventualmente applicate a `<html>`/`<body>` in futuro (i18n, densità
dell'interfaccia) e rende lo stato del tema ispezionabile senza dover
distinguere quella classe dalle altre in `class`.

### 3. Niente lampo del tema sbagliato: script bloccante nel `<head>`

Alpine (via Livewire) si inizializza dopo il parsing del `<body>`. Se lo
stato del tema venisse letto solo lì, chi ha scelto "scuro" vedrebbe un
lampo bianco a ogni caricamento pagina — sgradevole sempre, francamente
fastidioso in una stanza buia la sera dell'asta. `layouts/app.blade.php`
inserisce quindi un `<script>` sincrono, non-modulo, primo elemento dopo
`<title>`, che legge `localStorage.theme` (con fallback a
`prefers-color-scheme`) e scrive l'attributo su `<html>` **prima** che
`@vite` carichi qualunque cosa. Costo: qualche riga JS inline, nessuna
richiesta di rete aggiuntiva.

### 4. Alpine possiede lo stato dopo il primo paint

```html
<body
    x-data="{ theme: document.documentElement.getAttribute('data-theme') }"
    x-init="$watch('theme', v => { localStorage.setItem('theme', v); document.documentElement.setAttribute('data-theme', v) })"
>
```

Lo script bloccante scrive l'attributo una volta, al caricamento; da lì in
poi il bottone nel layout (`@click="theme = theme === 'dark' ? 'light' : 'dark'"`)
lo cambia via Alpine, che scrive sia l'attributo sia `localStorage`. Nessuna
duplicazione della logica di scelta iniziale: Alpine legge lo stato che lo
script ha già impostato, non lo ricalcola.

`x-data` sta su `<body>` e non dentro il componente Livewire della pagina: è
fuori dalla porzione di DOM che Livewire fa il morph, quindi un re-render
del componente (poll, azione, navigazione SPA-like) non tocca mai lo stato
del tema.

### 5. Palette: stessi accenti semantici, tonalità invertite

Nessuna nuova palette. `slate` resta la base neutra (chiaro: fondi chiari,
testo scuro; scuro: fondi `slate-900`/`950`, testo `slate-100`/`300`);
`emerald` (successo/disponibile), `red` (errore/indisponibile), `amber`
(attenzione/in attesa) restano gli stessi accenti semantici, con tonalità
scelte per contrasto AA su fondo scuro (es. `emerald-400`/`emerald-900` al
posto di `emerald-600`/`emerald-50` in chiaro) invece di riusare le stesse
tonalità su un fondo invertito, che in molti casi fallirebbe il contrasto.

## Alternative considerate

- **`class="dark"` su `<html>`** — la convenzione più diffusa e quella
  documentata per prima nei sample di Tailwind 4. Scartata solo per
  preferenza di leggibilità dello stato (un attributo dedicato invece di una
  classe fra le altre); funzionalmente equivalente, non è una decisione che
  vincola nulla a valle.
- **Solo `prefers-color-scheme`, nessun toggle** — scartata: non soddisfa il
  vincolo esplicito della spec Fase 5 di un controllo manuale persistito,
  indipendente dalla preferenza di sistema.
- **`Alpine.store('theme', ...)` globale in `resources/js/app.js`** —
  scartata per ora: un solo pezzo di stato, usato in un solo punto del
  layout. Uno store globale è giustificato se un giorno più componenti
  avranno bisogno di leggere il tema in JS; oggi sarebbe indirection senza
  beneficio.
- **Persistenza lato server (colonna utente/sessione)** — non applicabile:
  il progetto non ha autenticazione multiutente (fuori scope, briefing §1);
  `localStorage` è sufficiente per un'app a singolo utilizzatore sulla
  stessa macchina.

## Conseguenze

**Positive:**
- La scelta dell'utente sopravvive alla preferenza di sistema e alla
  chiusura del browser, senza backend coinvolto.
- Nessun lampo di tema sbagliato al caricamento.
- Il toggle vive nel layout, fuori dalla porzione di DOM che Livewire
  gestisce: zero interazione con poll, morph o navigazione fra pagine.

**Negative / obblighi creati:**
- Ogni superficie va mappata a mano: non esistono componenti Blade condivisi
  in questo progetto (ogni pagina è un file Livewire monolitico), quindi
  ogni pagina porta le proprie classi `dark:`. Una nuova pagina che dimentica
  la mappatura resta bianca su sfondo scuro invece di sparire nell'interfaccia.
- Lo script bloccante nel `<head>` è JS scritto a mano, fuori dal bundle
  Vite: va tenuto in sincronia manualmente con la logica Alpine se la
  strategia di storage cambia (es. un giorno una preferenza lato server).

## Riferimenti

- `docs/superpowers/specs/2026-08-07-phase5-polish.md` §1 (dark mode)
- `briefing.md` §8.2 (sala d'asta, la schermata che decide il successo del progetto)
- ADR [0005](0005-auction-room-mechanics.md) (palette semantica esistente della sala d'asta)

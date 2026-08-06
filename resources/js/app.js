/**
 * Macchina a stati da tastiera della sala d'asta (briefing §8.2).
 *
 * Obiettivo: registrare un'aggiudicazione in meno di tre secondi senza toccare
 * il mouse. Il flusso è
 *
 *     searching → selected → pricing → assigning → confirmed
 *
 * e l'unica cosa che il client decide è quale tasto significa cosa: chi è il
 * giocatore, quanto costa e a chi va lo stabilisce il server in una sola
 * chiamata (`record`). Niente logica di dominio qui dentro.
 *
 * Sul confine fra prezzo e squadra
 * --------------------------------
 * Prezzo e squadre parlano lo stesso alfabeto — sono entrambi cifre — quindi
 * un `3` battuto dopo `4` può voler dire «43 crediti» o «alla squadra 3». La
 * separazione è la barra spaziatrice: è il martello del banditore, non è una
 * cifra, e sta sotto il pollice. Digitato il prezzo:
 *
 *   - INVIO           → aggiudicato a me (il percorso più corto: due tasti);
 *   - SPAZIO poi 1-9  → aggiudicato a quell'avversario, che conferma da solo;
 *   - SPAZIO poi 0    → aggiudicato a me.
 *
 * `U` annulla l'ultima registrazione, ma solo a ricerca vuota: mentre si
 * scrive un nome la `u` è una lettera come le altre.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('auctionRoom', (config = {}) => ({
        /** Id dei risultati correnti, nell'ordine in cui li vede l'utente. */
        resultIds: config.resultIds ?? [],

        /** Mappa tasto → id squadra: '0' sono io, '1'-'9' gli avversari. */
        hotkeys: config.hotkeys ?? {},

        /** L'asta accetta registrazioni. */
        live: config.live ?? false,

        highlight: 0,
        price: '',
        assigning: false,

        init() {
            // Ogni volta che il server risponde, il fuoco torna dove serve:
            // sulla search se non c'è nulla di selezionato, e la scheda
            // decisione riparte pulita.
            this.$watch('selectedId', () => {
                this.price = '';
                this.assigning = false;
                this.highlight = 0;
                this.$nextTick(() => this.focusSearch());
            });

            this.$nextTick(() => this.focusSearch());
        },

        /** Lo stato della macchina, derivato: non esiste una copia da tenere allineata. */
        get state() {
            if (this.selectedId === null) {
                return 'searching';
            }

            if (this.assigning) {
                return 'assigning';
            }

            return this.price === '' ? 'selected' : 'pricing';
        },

        get selectedId() {
            return this.$wire.selectedId ?? null;
        },

        focusSearch() {
            const input = this.$refs.search;

            if (input && this.selectedId === null) {
                input.focus();
                input.setSelectionRange(input.value.length, input.value.length);
            }
        },

        onKey(event) {
            if (event.metaKey || event.ctrlKey || event.altKey) {
                return;
            }

            const key = event.key;

            if (key === 'Escape') {
                event.preventDefault();

                if (this.assigning) {
                    this.assigning = false;
                } else if (this.selectedId !== null) {
                    this.$wire.clearSelection();
                } else {
                    this.$wire.set('search', '');
                }

                return;
            }

            if (this.selectedId === null) {
                this.searchingKey(event, key);

                return;
            }

            this.pricingKey(event, key);
        },

        searchingKey(event, key) {
            if (key === 'ArrowDown' || key === 'ArrowUp') {
                event.preventDefault();

                const total = this.resultIds.length;

                if (total === 0) {
                    return;
                }

                this.highlight = key === 'ArrowDown'
                    ? (this.highlight + 1) % total
                    : (this.highlight - 1 + total) % total;

                return;
            }

            if (key === 'Enter') {
                event.preventDefault();

                const id = this.resultIds[this.highlight];

                if (id !== undefined) {
                    this.$wire.select(id);
                }

                return;
            }

            // `U` annulla, ma solo quando la search è vuota: altrimenti è una
            // lettera del nome che si sta scrivendo.
            if ((key === 'u' || key === 'U') && (this.$refs.search?.value ?? '') === '') {
                event.preventDefault();
                this.$wire.undo();
            }
        },

        pricingKey(event, key) {
            if (!this.live) {
                return;
            }

            if (this.assigning) {
                if (key === 'Enter') {
                    event.preventDefault();
                    this.assign('0');

                    return;
                }

                if (/^[0-9]$/.test(key)) {
                    event.preventDefault();
                    this.assign(key);
                }

                return;
            }

            if (/^[0-9]$/.test(key)) {
                event.preventDefault();
                this.price = (this.price + key).replace(/^0+(?=\d)/, '').slice(0, 3);

                return;
            }

            if (key === 'Backspace') {
                event.preventDefault();
                this.price = this.price.slice(0, -1);

                return;
            }

            // Invio: è mio. La strada più corta, perché è quella che si
            // percorre con le mani che tremano.
            if (key === 'Enter') {
                event.preventDefault();
                this.assign('0');

                return;
            }

            // Il martello: da qui in poi le cifre sono squadre, non crediti.
            if (key === ' ') {
                event.preventDefault();

                if (this.price !== '') {
                    this.assigning = true;
                }
            }
        },

        assign(hotkey) {
            const teamId = this.hotkeys[hotkey];

            if (teamId === undefined || this.price === '' || Number(this.price) < 1) {
                return;
            }

            this.assigning = false;
            this.$wire.set('price', this.price, false);
            this.$wire.record(teamId);
        },
    }));
});

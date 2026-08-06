<div class="space-y-6" wire:poll.3s>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Testate</h1>
            <p class="mt-1 text-sm text-slate-500">
                Le fonti che lo scraping automatico controlla ogni {{ config('fanta.scraping.schedule_interval_minutes') }} minuti.
            </p>
        </div>

        <div class="flex gap-2 text-sm">
            <a href="{{ route('conoscenza.index') }}" class="rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-100">
                Conoscenza
            </a>
        </div>
    </div>

    @if (session('conoscenza'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('conoscenza') }}
        </div>
    @endif

    {{-- Full scrape --}}
    <div class="rounded-lg border border-slate-200 bg-white p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="font-medium text-slate-900">Full scrape</h2>
                <p class="mt-0.5 text-sm text-slate-500">
                    Ripassa tutte le testate abilitate in profondità (finestra {{ config('fanta.scraping.full_scrape.window_days') }} giorni). Non blocca il resto dell'app.
                </p>
            </div>

            @if ($batch && $batch['active'])
                <button type="button" wire:click="cancelFullScrape" wire:confirm="Annullare il full scrape in corso?" class="rounded-md border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">
                    Annulla
                </button>
            @else
                <button type="button" wire:click="fullScrape" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700" wire:loading.attr="disabled" wire:target="fullScrape">
                    Avvia full scrape
                </button>
            @endif
        </div>

        @if ($batch)
            <div class="mt-4">
                <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                    <div class="h-2 rounded-full bg-slate-900 transition-all" style="width: {{ $batch['percent'] }}%"></div>
                </div>
                <p class="mt-2 text-xs text-slate-500">
                    {{ $batch['processed'] }} / {{ $batch['total'] }} testate ·
                    @if ($batch['cancelled'])
                        <span class="font-medium text-red-600">annullato</span>
                    @elseif ($batch['finished'])
                        <span class="font-medium text-emerald-700">completato</span>
                        @if ($batch['failed'] > 0)
                            · {{ $batch['failed'] }} con errori
                        @endif
                    @else
                        <span class="font-medium text-blue-700">in corso…</span>
                    @endif
                </p>
            </div>
        @endif
    </div>

    {{-- Aggiungi / modifica testata --}}
    <form wire:submit="save" class="rounded-lg border border-slate-200 bg-white p-5 space-y-4">
        <h2 class="font-medium text-slate-900">{{ $editingId ? 'Modifica testata' : 'Nuova testata' }}</h2>

        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label for="name" class="block text-sm font-medium text-slate-700">Nome</label>
                <input id="name" type="text" wire:model="name" class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
                @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-end gap-2 pb-1">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="enabled" class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
                    Abilitata
                </label>
            </div>

            <div>
                <label for="url" class="block text-sm font-medium text-slate-700">URL sezione news</label>
                <input id="url" type="text" wire:model="url" placeholder="https://www.esempio.it/news" class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
                @error('url') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="rssUrl" class="block text-sm font-medium text-slate-700">URL feed RSS <span class="font-normal text-slate-400">(facoltativo)</span></label>
                <input id="rssUrl" type="text" wire:model="rssUrl" placeholder="https://www.esempio.it/feed/" class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
                @error('rssUrl') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="flex gap-2">
            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                {{ $editingId ? 'Salva modifiche' : 'Aggiungi testata' }}
            </button>
            @if ($editingId)
                <button type="button" wire:click="startCreate" class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                    Annulla
                </button>
            @endif
        </div>
    </form>

    {{-- Elenco testate --}}
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-2">Testata</th>
                    <th class="px-4 py-2">Feed</th>
                    <th class="px-4 py-2">Circuito</th>
                    <th class="px-4 py-2">Ultimo scrape</th>
                    <th class="px-4 py-2 text-right">Articoli ultimo run</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($targets as $row)
                    @php [$target, $circuit] = [$row['target'], $row['circuit']]; @endphp
                    <tr wire:key="target-{{ $target->id }}" class="align-top">
                        <td class="px-4 py-3">
                            <p class="font-medium text-slate-900">{{ $target->name }}</p>
                            <a href="{{ $target->url }}" target="_blank" rel="noopener" class="mt-0.5 block truncate text-xs text-slate-400 hover:text-slate-600">{{ $target->url }}</a>
                            @unless ($target->enabled)
                                <span class="mt-1 inline-block rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">disabilitata</span>
                            @endunless
                        </td>

                        <td class="px-4 py-3 text-slate-500">
                            {{ $target->rss_url ? 'RSS' : 'Crawl HTML' }}
                        </td>

                        <td class="px-4 py-3">
                            @if ($circuit['open'])
                                <span class="inline-block rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">
                                    Aperto · riprova {{ $circuit['opened_until']?->diffForHumans() }}
                                </span>
                            @else
                                <span class="inline-block rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800">Chiuso</span>
                            @endif
                            @if ($circuit['failures'] > 0)
                                <p class="mt-1 text-xs text-slate-400">{{ $circuit['failures'] }} fallimenti recenti</p>
                            @endif
                        </td>

                        <td class="px-4 py-3 text-slate-500">
                            {{ $target->last_scraped_at?->diffForHumans() ?? 'mai' }}
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums text-slate-700">
                            {{ $target->last_run_articles_found ?? '—' }}
                        </td>

                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <button type="button" wire:click="scrapeNow({{ $target->id }})" class="text-xs font-medium text-slate-600 hover:text-slate-900 hover:underline">Scrape ora</button>
                            <button type="button" wire:click="edit({{ $target->id }})" class="ml-2 text-xs font-medium text-slate-600 hover:text-slate-900 hover:underline">Modifica</button>
                            <button
                                type="button"
                                wire:click="delete({{ $target->id }})"
                                wire:confirm="Eliminare questa testata?"
                                class="ml-2 text-xs font-medium text-red-600 hover:underline"
                            >Elimina</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">Nessuna testata configurata.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

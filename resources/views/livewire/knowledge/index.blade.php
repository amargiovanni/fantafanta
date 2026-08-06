<div class="space-y-6" wire:poll.5s>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Conoscenza</h1>
            <p class="mt-1 text-sm text-slate-500">
                Tutto quello che entra qui diventa segnali sui giocatori. Trascina un PDF, incolla un link o scrivi una nota.
            </p>
        </div>

        <div class="flex gap-2 text-sm">
            <a href="{{ route('conoscenza.revisione') }}" class="rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-100">
                Da rivedere
                @if ($counters['da_rivedere'] > 0)
                    <span class="ml-1 rounded-full bg-amber-500 px-1.5 py-0.5 text-xs font-semibold text-white">{{ $counters['da_rivedere'] }}</span>
                @endif
            </a>
            <a href="{{ route('conoscenza.segnali') }}" class="rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-100">
                Segnali
            </a>
            <a href="{{ route('conoscenza.testate') }}" class="rounded-md border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-100">
                Testate
            </a>
        </div>
    </div>

    @if (session('conoscenza'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('conoscenza') }}
        </div>
    @endif

    {{-- Drop zone universale --}}
    <form wire:submit="submit" class="rounded-lg border border-slate-200 bg-white p-5 space-y-4">
        <div
            x-data="{ dragging: false }"
            x-on:dragover.prevent="dragging = true"
            x-on:dragleave.prevent="dragging = false"
            x-on:drop.prevent="dragging = false; $refs.file.files = $event.dataTransfer.files; $refs.file.dispatchEvent(new Event('change'))"
            :class="dragging ? 'border-slate-900 bg-slate-50' : 'border-slate-300'"
            class="rounded-lg border-2 border-dashed px-4 py-6 text-center transition"
        >
            <input type="file" wire:model="file" x-ref="file" class="hidden" id="file-conoscenza">

            <label for="file-conoscenza" class="cursor-pointer text-sm text-slate-600">
                <span class="font-medium text-slate-900">Trascina qui un file</span>
                o clicca per sceglierlo — PDF, txt, md (max 20 MB)
            </label>

            <div wire:loading wire:target="file" class="mt-2 text-sm text-slate-500">Caricamento in corso…</div>

            @if ($file)
                <p class="mt-2 text-sm font-medium text-slate-900">{{ $file->getClientOriginalName() }}</p>
            @endif

            @error('file') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="contenuto" class="block text-sm font-medium text-slate-700">Oppure incolla un link o del testo</label>
            <textarea
                id="contenuto"
                wire:model="content"
                rows="4"
                placeholder="https://www.fantamaster.it/... — oppure scrivi qui una nota, un messaggio sentito in radio, un pezzo di articolo"
                class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500"
            ></textarea>
            @error('content') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-64">
                <label for="titolo" class="block text-sm font-medium text-slate-700">Titolo <span class="font-normal text-slate-400">(facoltativo)</span></label>
                <input id="titolo" type="text" wire:model="title" class="mt-1 block w-full rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
            </div>

            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="submit">Aggiungi alla conoscenza</span>
                <span wire:loading wire:target="submit">Invio…</span>
            </button>
        </div>
    </form>

    {{-- Contatori pipeline --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([
            ['In coda', $counters['in_coda'], 'text-slate-900'],
            ['Processate', $counters['processate'], 'text-emerald-700'],
            ['Da rivedere', $counters['da_rivedere'], 'text-amber-600'],
            ['In errore', $counters['in_errore'], 'text-red-600'],
        ] as [$etichetta, $valore, $colore])
            <div class="rounded-lg border border-slate-200 bg-white px-4 py-3">
                <p class="text-xs uppercase tracking-wide text-slate-500">{{ $etichetta }}</p>
                <p class="mt-1 text-2xl font-semibold {{ $colore }}">{{ $valore }}</p>
            </div>
        @endforeach
    </div>

    {{-- Elenco fonti --}}
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                <tr>
                    <th class="px-4 py-2">Fonte</th>
                    <th class="px-4 py-2">Tipo</th>
                    <th class="px-4 py-2">Stato</th>
                    <th class="px-4 py-2 text-right">Segnali</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($sources as $source)
                    <tr wire:key="source-{{ $source->id }}" class="align-top">
                        <td class="px-4 py-3">
                            <button type="button" wire:click="toggle({{ $source->id }})" class="text-left font-medium text-slate-900 hover:underline">
                                {{ $source->title }}
                            </button>
                            @if ($source->url)
                                <a href="{{ $source->url }}" target="_blank" rel="noopener" class="mt-0.5 block truncate text-xs text-slate-400 hover:text-slate-600">{{ $source->url }}</a>
                            @endif
                            <p class="mt-0.5 text-xs text-slate-400">{{ $source->created_at->diffForHumans() }}</p>
                        </td>

                        <td class="px-4 py-3 text-slate-500">
                            {{ $source->type->label() }}
                            @if ($source->origin->value !== 'manual')
                                <span class="ml-1 inline-block rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">{{ $source->origin->label() }}</span>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            @php
                                $classiStato = match ($source->status->value) {
                                    'processed' => 'bg-emerald-100 text-emerald-800',
                                    'needs_review' => 'bg-amber-100 text-amber-800',
                                    'failed' => 'bg-red-100 text-red-800',
                                    'duplicate' => 'bg-slate-100 text-slate-500',
                                    'processing' => 'bg-blue-100 text-blue-800',
                                    default => 'bg-slate-100 text-slate-700',
                                };
                            @endphp
                            <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium {{ $classiStato }}">
                                {{ $source->status->label() }}
                            </span>
                            @if ($source->error)
                                <p class="mt-1 max-w-md text-xs text-red-600">{{ $source->error }}</p>
                            @endif
                            @if ($source->queue_note)
                                <p class="mt-1 max-w-md text-xs text-amber-600">{{ $source->queue_note }}</p>
                            @endif
                        </td>

                        <td class="px-4 py-3 text-right tabular-nums text-slate-700">{{ $source->signals_count }}</td>

                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            @if (in_array($source->status->value, ['failed', 'duplicate'], true))
                                <button type="button" wire:click="retry({{ $source->id }})" class="text-xs font-medium text-slate-600 hover:text-slate-900 hover:underline">Riprova</button>
                            @endif
                            @if ($source->queue_note)
                                <button type="button" wire:click="processAnyway({{ $source->id }})" class="text-xs font-medium text-slate-600 hover:text-slate-900 hover:underline">Processa comunque</button>
                            @endif
                            <button
                                type="button"
                                wire:click="delete({{ $source->id }})"
                                wire:confirm="Eliminare questa fonte e i segnali che ne derivano?"
                                class="ml-2 text-xs font-medium text-red-600 hover:underline"
                            >Elimina</button>
                        </td>
                    </tr>

                    @if ($expandedSource === $source->id)
                        <tr wire:key="source-detail-{{ $source->id }}" class="bg-slate-50">
                            <td colspan="5" class="px-4 py-4">
                                @if ($source->signals->isEmpty())
                                    <p class="text-sm text-slate-500">Nessun segnale estratto da questa fonte.</p>
                                @else
                                    <ul class="space-y-2">
                                        @foreach ($source->signals as $signal)
                                            <li class="flex flex-wrap items-center gap-2 text-sm">
                                                <span class="rounded bg-slate-200 px-1.5 py-0.5 text-xs font-medium text-slate-700">{{ $signal->type->label() }}</span>
                                                <span class="font-medium text-slate-900">
                                                    {{ $signal->player?->name ?? ($signal->raw_name.' — da assegnare') }}
                                                </span>
                                                <span class="text-xs text-slate-500">
                                                    impatto {{ $signal->impact > 0 ? '+' : '' }}{{ $signal->impact }} ·
                                                    confidenza {{ number_format($signal->confidence * 100, 0) }}%
                                                    @if ($signal->event_date) · {{ $signal->event_date->format('d/m/Y') }} @endif
                                                </span>
                                                @if ($signal->needs_review)
                                                    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800">da rivedere</span>
                                                @endif
                                                @if ($signal->superseded_by)
                                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">superato</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($source->raw_content)
                                    <details class="mt-3">
                                        <summary class="cursor-pointer text-xs font-medium text-slate-500 hover:text-slate-700">Testo estratto</summary>
                                        <p class="mt-2 max-h-64 overflow-y-auto whitespace-pre-wrap text-xs text-slate-600">{{ $source->raw_content }}</p>
                                    </details>
                                @endif
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                            Ancora nessuna fonte. Incolla un link o trascina un PDF qui sopra per iniziare.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $sources->links() }}
</div>

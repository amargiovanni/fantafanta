<div class="space-y-6">
    <div>
        <div class="flex items-center gap-3">
            <a href="{{ route('conoscenza.index') }}" class="text-sm text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-slate-100">← Conoscenza</a>
        </div>
        <h1 class="mt-2 text-2xl font-semibold text-slate-900 dark:text-slate-100">Da rivedere</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Segnali il cui nome non è stato risolto con certezza. Assegnando il giocatore giusto crei un alias:
            la stessa forma non tornerà mai più in questa coda.
        </p>
    </div>

    @if (session('revisione'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-400">
            {{ session('revisione') }}
        </div>
    @endif

    @forelse ($signals as $signal)
        <div wire:key="revisione-{{ $signal->id }}" class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">«{{ $signal->raw_name }}»</p>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        <span class="rounded bg-slate-200 px-1.5 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-700 dark:text-slate-300">{{ $signal->type->label() }}</span>
                        impatto {{ $signal->impact > 0 ? '+' : '' }}{{ $signal->impact }} ·
                        confidenza {{ number_format($signal->confidence * 100, 0) }}%
                        @if ($signal->event_date) · {{ $signal->event_date->format('d/m/Y') }} @endif
                    </p>
                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">
                        da: {{ $signal->source->title }}
                        @if ($signal->source->url)
                            — <a href="{{ $signal->source->url }}" target="_blank" rel="noopener" class="underline">apri la fonte</a>
                        @endif
                    </p>
                </div>

                <button
                    type="button"
                    wire:click="discard({{ $signal->id }})"
                    wire:confirm="Eliminare questo segnale?"
                    class="text-xs font-medium text-red-600 hover:underline dark:text-red-400"
                >Non è un segnale valido — elimina</button>
            </div>

            @if ($signal->payload)
                <p class="mt-2 rounded bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-400">
                    {{ json_encode($signal->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}
                </p>
            @endif

            <div class="mt-4 border-t border-slate-100 pt-4 dark:border-slate-800">
                <label for="ricerca-{{ $signal->id }}" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Cerca il giocatore</label>
                <input
                    id="ricerca-{{ $signal->id }}"
                    type="search"
                    wire:model.live.debounce.300ms="queries.{{ $signal->id }}"
                    class="mt-1 w-full max-w-sm rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
                >

                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse ($suggestions[$signal->id] ?? [] as $candidato)
                        <button
                            type="button"
                            wire:click="assign({{ $signal->id }}, {{ $candidato['player_id'] }})"
                            class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:border-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:hover:border-slate-400 dark:hover:bg-slate-800"
                        >
                            <span class="font-medium text-slate-900 dark:text-slate-100">{{ $candidato['name'] }}</span>
                            <span class="ml-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ $candidato['role'] }} · {{ $candidato['real_team'] }} · {{ number_format($candidato['similarity'] * 100, 0) }}%
                            </span>
                        </button>
                    @empty
                        <p class="text-sm text-slate-500 dark:text-slate-400">Nessun candidato per questa ricerca.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @empty
        <div class="rounded-lg border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-400">
            Nessun segnale in attesa di revisione.
        </div>
    @endforelse
</div>

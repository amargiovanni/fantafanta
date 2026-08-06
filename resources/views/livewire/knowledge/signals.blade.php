<div class="space-y-6">
    <div>
        <a href="{{ route('conoscenza.index') }}" class="text-sm text-slate-500 hover:text-slate-900">← Conoscenza</a>
        <h1 class="mt-2 text-2xl font-semibold text-slate-900">Segnali</h1>
        <p class="mt-1 text-sm text-slate-500">
            Quello che l'AI ha capito dalle fonti, giocatore per giocatore. Correggi o elimina quello che è sbagliato.
        </p>
    </div>

    @if (session('segnali'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('segnali') }}
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Cerca giocatore..."
            class="w-64 rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500"
        >

        <select wire:model.live="typeFilter" class="rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
            <option value="">Tutti i tipi</option>
            @foreach ($types as $type)
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endforeach
        </select>

        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" wire:model.live="onlyActive" class="rounded border-slate-300 text-slate-900 focus:ring-slate-500">
            Solo segnali attivi
        </label>
    </div>

    @forelse ($grouped as $gruppo)
        <div wire:key="gruppo-{{ $gruppo['player']?->id ?? 'da-assegnare' }}" class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div class="flex items-baseline justify-between border-b border-slate-100 bg-slate-50 px-4 py-2">
                <p class="font-semibold text-slate-900">
                    {{ $gruppo['player']?->name ?? 'Nomi non ancora assegnati' }}
                </p>
                @if ($gruppo['player'])
                    <p class="text-xs text-slate-500">{{ $gruppo['player']->role->label() }} · {{ $gruppo['player']->real_team }}</p>
                @endif
            </div>

            <ul class="divide-y divide-slate-100">
                @foreach ($gruppo['signals'] as $signal)
                    <li wire:key="segnale-{{ $signal->id }}" class="px-4 py-3">
                        @if ($editing === $signal->id)
                            <form wire:submit="save" class="flex flex-wrap items-end gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-slate-600">Tipo</label>
                                    <select wire:model="form.type" class="mt-1 rounded-md border-slate-300 text-sm">
                                        @foreach ($types as $type)
                                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-slate-600">Impatto</label>
                                    <select wire:model="form.impact" class="mt-1 rounded-md border-slate-300 text-sm">
                                        @foreach ([-2, -1, 0, 1, 2] as $valore)
                                            <option value="{{ $valore }}">{{ $valore > 0 ? '+' : '' }}{{ $valore }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-slate-600">Confidenza</label>
                                    <input type="number" step="0.05" min="0" max="1" wire:model="form.confidence" class="mt-1 w-24 rounded-md border-slate-300 text-sm">
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-slate-600">Data evento</label>
                                    <input type="date" wire:model="form.event_date" class="mt-1 rounded-md border-slate-300 text-sm">
                                </div>

                                <button type="submit" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700">Salva</button>
                                <button type="button" wire:click="cancelEdit" class="text-sm text-slate-500 hover:underline">Annulla</button>

                                @error('form.confidence') <p class="w-full text-sm text-red-600">{{ $message }}</p> @enderror
                                @error('form.impact') <p class="w-full text-sm text-red-600">{{ $message }}</p> @enderror
                            </form>
                        @else
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="flex flex-wrap items-center gap-2 text-sm">
                                    <span class="rounded bg-slate-200 px-1.5 py-0.5 text-xs font-medium text-slate-700">{{ $signal->type->label() }}</span>

                                    <span class="font-medium {{ $signal->impact < 0 ? 'text-red-600' : ($signal->impact > 0 ? 'text-emerald-700' : 'text-slate-500') }}">
                                        {{ $signal->impact > 0 ? '+' : '' }}{{ $signal->impact }}
                                    </span>

                                    <span class="text-xs text-slate-500">confidenza {{ number_format($signal->confidence * 100, 0) }}%</span>

                                    @if ($signal->event_date)
                                        <span class="text-xs text-slate-500">{{ $signal->event_date->format('d/m/Y') }}</span>
                                    @endif

                                    @if ($signal->needs_review)
                                        <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800">«{{ $signal->raw_name }}» da assegnare</span>
                                    @endif

                                    @if ($signal->superseded_by)
                                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">superato dal #{{ $signal->superseded_by }}</span>
                                    @endif

                                    <span class="text-xs text-slate-400">— {{ $signal->source->title }}</span>
                                </div>

                                <div class="flex items-center gap-3 whitespace-nowrap">
                                    @if ($signal->superseded_by)
                                        <button type="button" wire:click="reactivate({{ $signal->id }})" class="text-xs font-medium text-slate-600 hover:underline">Riattiva</button>
                                    @endif
                                    <button type="button" wire:click="edit({{ $signal->id }})" class="text-xs font-medium text-slate-600 hover:underline">Correggi</button>
                                    <button
                                        type="button"
                                        wire:click="delete({{ $signal->id }})"
                                        wire:confirm="Eliminare questo segnale?"
                                        class="text-xs font-medium text-red-600 hover:underline"
                                    >Elimina</button>
                                </div>
                            </div>

                            @if ($signal->payload)
                                <p class="mt-1 text-xs text-slate-500">{{ json_encode($signal->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</p>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @empty
        <div class="rounded-lg border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">
            Nessun segnale. Aggiungi fonti dalla pagina <a href="{{ route('conoscenza.index') }}" class="underline">Conoscenza</a>.
        </div>
    @endforelse
</div>

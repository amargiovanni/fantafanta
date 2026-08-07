<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">Import listone</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            Carica il listone ufficiale (.xlsx) esportato da fantacalcio.it, sezione "Quotazioni" (è supportato anche il vecchio formato .csv). Il mapping delle colonne va confermato ad ogni import: il formato può cambiare stagione per stagione.
        </p>
    </div>

    @if ($summary)
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-400">
            Import completato: {{ $summary['created'] }} creati, {{ $summary['updated'] }} aggiornati,
            {{ $summary['removed'] }} rimossi dal listone, {{ $summary['skipped'] }} righe saltate
            ({{ $summary['aliases_created'] }} alias generati).
        </div>
    @endif

    @if ($playersCount === 0 && $headers === [] && ! $summary)
        <div class="rounded-lg border border-dashed border-slate-300 bg-white px-4 py-6 text-center dark:border-slate-700 dark:bg-slate-900">
            <p class="text-sm font-medium text-slate-700 dark:text-slate-300">Il listone è ancora vuoto.</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Scegli il file "Quotazioni" (.xlsx, o .csv per il vecchio formato) esportato da fantacalcio.it qui sotto: dopo la scelta comparirà l'anteprima delle colonne da confermare.
            </p>
        </div>
    @endif

    <div class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">File del listone (.xlsx o .csv)</label>
        <input type="file" wire:model="file" accept=".xlsx,.csv,.txt" class="mt-2 block w-full text-sm dark:text-slate-300">
        @error('file') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
        <div wire:loading wire:target="file" class="mt-2 text-sm text-slate-500 dark:text-slate-400">Lettura del file in corso...</div>
    </div>

    @if ($headers !== [])
        <div class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">Mapping colonne</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Colonne rilevate nel CSV: {{ implode(', ', $headers) }}</p>

            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                @foreach ([
                    'name' => 'Nome',
                    'role' => 'Ruolo (R)',
                    'real_team' => 'Squadra',
                    'quotazione' => 'Quotazione (Qt.A)',
                    'fvm' => 'FVM',
                ] as $field => $label)
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">{{ $label }}</label>
                        <select wire:model="mapping.{{ $field }}" class="mt-1 w-full rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                            <option value="">-- seleziona colonna --</option>
                            @foreach ($headers as $header)
                                <option value="{{ $header }}">{{ $header }}</option>
                            @endforeach
                        </select>
                        @error('mapping.'.$field) <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>

            <div class="mt-4">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">Statistiche da salvare (season_stats)</label>
                <div class="mt-2 flex flex-wrap gap-3">
                    @foreach ($headers as $header)
                        <label class="flex items-center gap-1.5 text-sm text-slate-600 dark:text-slate-400">
                            <input type="checkbox" value="{{ $header }}" wire:model="statsColumns" class="rounded border-slate-300 dark:border-slate-700 dark:bg-slate-800">
                            {{ $header }}
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-base font-semibold text-slate-900 dark:text-slate-100">Anteprima (prime {{ count($previewRows) }} righe)</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-xs dark:divide-slate-800">
                    <thead class="bg-slate-50 text-left uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                        <tr>
                            @foreach ($headers as $header)
                                <th class="whitespace-nowrap px-3 py-2">{{ $header }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($previewRows as $row)
                            <tr>
                                @foreach ($headers as $header)
                                    <td class="whitespace-nowrap px-3 py-2 text-slate-700 dark:text-slate-300">{{ $row[$header] ?? '' }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <button
                    type="button"
                    wire:click="confirmImport"
                    wire:loading.attr="disabled"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300"
                >
                    Conferma import
                </button>
                <button
                    type="button"
                    wire:click="cancelImport"
                    wire:loading.attr="disabled"
                    class="rounded-md border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                >
                    Annulla
                </button>
            </div>
        </div>
    @endif
</div>

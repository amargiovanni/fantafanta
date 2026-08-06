<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">Listone</h1>
        <a href="{{ route('listone.import') }}" class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-300">
            Importa CSV
        </a>
    </div>

    <div class="flex flex-wrap gap-3">
        <input
            type="search"
            wire:model.live.debounce.300ms="search"
            placeholder="Cerca giocatore..."
            class="w-64 rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder-slate-500"
        >

        <select wire:model.live="roleFilter" class="rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
            <option value="">Tutti i ruoli</option>
            @foreach ($roles as $role)
                <option value="{{ $role->value }}">{{ $role->label() }}</option>
            @endforeach
        </select>
    </div>

    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-800">
            <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                <tr>
                    <th class="px-4 py-2">Ruolo</th>
                    <th class="px-4 py-2">Nome</th>
                    <th class="px-4 py-2">Squadra</th>
                    <th class="px-4 py-2 text-right">Quotazione</th>
                    <th class="px-4 py-2 text-right">FVM</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($players as $player)
                    <tr wire:key="player-{{ $player->id }}">
                        <td class="px-4 py-2 font-medium text-slate-500 dark:text-slate-400">{{ $player->role->value }}</td>
                        <td class="px-4 py-2 text-slate-900 dark:text-slate-100">{{ $player->name }}</td>
                        <td class="px-4 py-2 text-slate-500 dark:text-slate-400">{{ $player->real_team }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $player->quotazione }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ $player->fvm }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">
                            Nessun giocatore trovato. Importa il listone da <a href="{{ route('listone.import') }}" class="underline">Import</a>.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $players->links() }}
</div>

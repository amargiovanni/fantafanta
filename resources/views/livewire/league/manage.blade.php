<div class="space-y-8">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Lega</h1>
        <p class="mt-1 text-sm text-slate-500">Configurazione della lega e squadre partecipanti (regolamento Classic).</p>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h2 class="text-base font-semibold text-slate-900">Configurazione</h2>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <label class="block text-sm font-medium text-slate-700">Crediti iniziali</label>
                <input type="number" wire:model="totalCredits" class="mt-1 w-full rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
                @error('totalCredits') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Numero squadre</label>
                <input type="number" wire:model="teamsCount" class="mt-1 w-full rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
                @error('teamsCount') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <label class="block text-sm font-medium text-slate-700">Slot P</label>
                <input type="number" wire:model="slotP" class="mt-1 w-full rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Slot D</label>
                <input type="number" wire:model="slotD" class="mt-1 w-full rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Slot C</label>
                <input type="number" wire:model="slotC" class="mt-1 w-full rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Slot A</label>
                <input type="number" wire:model="slotA" class="mt-1 w-full rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
            </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-6">
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" wire:model="modifierDefense" class="rounded border-slate-300">
                Modificatore difesa
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" wire:model="modifierFairplay" class="rounded border-slate-300">
                Modificatore fairplay
            </label>
        </div>

        <button
            type="button"
            wire:click="saveConfig"
            class="mt-5 rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700"
        >
            Salva configurazione
        </button>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-5">
        <h2 class="text-base font-semibold text-slate-900">Squadre</h2>

        <div class="mt-4 overflow-hidden rounded-md border border-slate-200">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-2">Nome</th>
                        <th class="px-4 py-2">Mia squadra</th>
                        <th class="px-4 py-2 text-right">Crediti</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($teams as $team)
                        <tr wire:key="team-{{ $team->id }}">
                            @if ($editingTeamId === $team->id)
                                <td class="px-4 py-2">
                                    <input type="text" wire:model="editName" class="w-full rounded-md border-slate-300 text-sm">
                                    @error('editName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-4 py-2">
                                    <input type="checkbox" wire:model="editIsMine" class="rounded border-slate-300">
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <input type="number" wire:model="editCredits" class="w-24 rounded-md border-slate-300 text-right text-sm">
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <button type="button" wire:click="saveEdit" class="text-sm font-medium text-slate-900 hover:underline">Salva</button>
                                    <button type="button" wire:click="cancelEdit" class="ml-2 text-sm text-slate-500 hover:underline">Annulla</button>
                                </td>
                            @else
                                <td class="px-4 py-2 text-slate-900">{{ $team->name }}</td>
                                <td class="px-4 py-2">
                                    @if ($team->is_mine)
                                        <span class="rounded-full bg-slate-900 px-2 py-0.5 text-xs font-medium text-white">Sì</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $team->credits_total }}</td>
                                <td class="px-4 py-2 text-right">
                                    <button type="button" wire:click="startEdit({{ $team->id }})" class="text-sm font-medium text-slate-900 hover:underline">Modifica</button>
                                    <button type="button" wire:click="deleteTeam({{ $team->id }})" wire:confirm="Eliminare questa squadra?" class="ml-2 text-sm text-red-600 hover:underline">Elimina</button>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-slate-500">Nessuna squadra registrata.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-4 sm:items-end">
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-slate-700">Nuova squadra</label>
                <input type="text" wire:model="newTeamName" placeholder="Nome squadra" class="mt-1 w-full rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
                @error('newTeamName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">Crediti</label>
                <input type="number" wire:model="newTeamCredits" class="mt-1 w-full rounded-md border-slate-300 text-sm focus:border-slate-500 focus:ring-slate-500">
            </div>
            <div class="flex items-center gap-2">
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="newTeamIsMine" class="rounded border-slate-300">
                    È la mia squadra
                </label>
            </div>
        </div>

        <button
            type="button"
            wire:click="addTeam"
            class="mt-4 rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700"
        >
            Aggiungi squadra
        </button>
    </section>
</div>

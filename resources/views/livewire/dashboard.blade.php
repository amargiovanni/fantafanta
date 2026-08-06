<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Dashboard</h1>
            <p class="mt-1 text-sm text-slate-500">
                Il piano d'acquisto, la conoscenza raccolta e la salute della pipeline, prima che l'asta cominci.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if (! $auction)
                <button
                    type="button"
                    wire:click="openAuction"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700"
                >
                    Apri sessione d'asta
                </button>
            @else
                <button
                    type="button"
                    wire:click="generatePlan"
                    wire:loading.attr="disabled"
                    wire:target="generatePlan"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="generatePlan">Genera piano</span>
                    <span wire:loading wire:target="generatePlan">Avvio…</span>
                </button>
            @endif

            <button
                type="button"
                wire:click="recomputeValuations"
                wire:loading.attr="disabled"
                wire:target="recomputeValuations"
                class="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="recomputeValuations">Ricalcola valutazioni</span>
                <span wire:loading wire:target="recomputeValuations">In coda…</span>
            </button>
        </div>
    </div>

    @if (session('dashboard'))
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('dashboard') }}
        </div>
    @endif

    @if (session('dashboard-error'))
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ session('dashboard-error') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-500">Giocatori nel listone</p>
            <p class="mt-1 text-3xl font-semibold text-slate-900">{{ $playersCount }}</p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-500">Squadre registrate</p>
            <p class="mt-1 text-3xl font-semibold text-slate-900">{{ $teamsCount }} / {{ $config->teams_count }}</p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-500">{{ $myTeam?->name ?? 'La mia squadra: non impostata' }}</p>
            <p class="mt-1 text-3xl font-semibold text-slate-900">{{ $state->myTeam()['credits_remaining'] }}</p>
            <p class="mt-1 text-xs text-slate-500">crediti per {{ $state->myTeam()['open_slots_total'] }} slot da riempire</p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-500">Valutazioni calcolate</p>
            <p class="mt-1 text-3xl font-semibold {{ $valuationsCount === 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ $valuationsCount }}</p>
            <p class="mt-1 text-xs text-slate-500">
                {{ $valuationsComputedAt ? 'aggiornate '.\Illuminate\Support\Carbon::parse($valuationsComputedAt)->diffForHumans() : 'mai calcolate' }}
            </p>
        </div>
    </div>

    {{-- Il piano: la ragione per cui questa pagina esiste. --}}
    <div class="rounded-lg border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
            <div class="flex items-center gap-3">
                <h2 class="text-sm font-semibold text-slate-900">Piano d'acquisto</h2>

                @if ($plan)
                    <span class="inline-block rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                        versione {{ $plan->version }} · {{ $plan->trigger->label() }}
                    </span>
                @endif

                @if ($planGenerating)
                    <span class="inline-block rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700">
                        ricalcolo in corso
                    </span>
                @endif
            </div>

            @if ($plan)
                <span class="text-xs text-slate-500">generato {{ $plan->created_at->diffForHumans() }}</span>
            @endif
        </div>

        @if (! $plan)
            <div class="px-5 py-10 text-center text-sm text-slate-500">
                @if (! $auction)
                    Nessuna sessione d'asta aperta. Aprine una per poter generare il piano.
                @elseif ($playersCount === 0)
                    Il listone è vuoto: importa il CSV delle quotazioni prima di generare il piano.
                @else
                    Nessun piano ancora. Premi <span class="font-medium text-slate-900">Genera piano</span>: Claude lo costruisce in un paio di minuti e comparirà qui.
                @endif
            </div>
        @else
            @if ($plan->strategy_notes)
                <div class="border-b border-slate-100 bg-slate-50 px-5 py-3 text-sm text-slate-700">
                    {!! nl2br(e($plan->strategy_notes)) !!}
                </div>
            @endif

            @if ($plan->budget_summary)
                <div class="grid grid-cols-2 gap-3 border-b border-slate-100 px-5 py-3 sm:grid-cols-4">
                    @foreach ($plan->budget_summary as $reparto => $riepilogo)
                        <div class="text-sm">
                            <span class="font-medium text-slate-900">{{ $reparto }}</span>
                            <span class="text-slate-500">
                                {{ $riepilogo['allocated'] }} crediti allocati
                                @if (($riepilogo['spent'] ?? 0) > 0)
                                    · {{ $riepilogo['spent'] }} già spesi
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="divide-y divide-slate-100">
                @foreach (['P' => 'Portieri', 'D' => 'Difensori', 'C' => 'Centrocampisti', 'A' => 'Attaccanti'] as $ruolo => $etichetta)
                    @if (! empty($planSlots[$ruolo]))
                        <div class="px-5 py-4">
                            <h3 class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $etichetta }}</h3>

                            <div class="mt-3 overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead class="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                                        <tr>
                                            <th class="w-10 py-2 pr-3">#</th>
                                            <th class="py-2 pr-3">Titolare</th>
                                            <th class="py-2 pr-3">Target</th>
                                            <th class="py-2 pr-3">Max</th>
                                            <th class="py-2 pr-3">Alternative</th>
                                            <th class="py-2">Stato</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($planSlots[$ruolo] as $riga)
                                            @php
                                                $slot = $riga['slot'];
                                                $classiStato = match ($slot->slot_status->value) {
                                                    'acquired' => 'bg-emerald-100 text-emerald-700',
                                                    'lost' => 'bg-red-100 text-red-700',
                                                    default => 'bg-slate-100 text-slate-600',
                                                };
                                            @endphp
                                            <tr wire:key="slot-{{ $slot->id }}">
                                                <td class="py-2 pr-3 text-slate-400">{{ $slot->slot_index }}</td>
                                                <td class="py-2 pr-3 font-medium text-slate-900">
                                                    {{ $riga['player_name'] ?? 'nessuno disponibile' }}
                                                </td>
                                                <td class="py-2 pr-3 text-slate-700">{{ $slot->target_price }}</td>
                                                <td class="py-2 pr-3 text-slate-500">{{ $slot->max_price }}</td>
                                                <td class="py-2 pr-3 text-slate-500">
                                                    @forelse ($riga['alternatives'] as $alternativa)
                                                        <span class="whitespace-nowrap">{{ $alternativa['name'] }} ({{ $alternativa['target_price'] }})</span>@if (! $loop->last), @endif
                                                    @empty
                                                        —
                                                    @endforelse
                                                </td>
                                                <td class="py-2">
                                                    <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium {{ $classiStato }}">
                                                        {{ $slot->slot_status->label() }}
                                                    </span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Segnali recenti: cosa è cambiato da quando ho guardato l'ultima volta. --}}
        <div class="rounded-lg border border-slate-200 bg-white">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h2 class="text-sm font-semibold text-slate-900">Segnali recenti</h2>
                <a href="{{ route('conoscenza.segnali') }}" class="text-xs font-medium text-slate-600 hover:text-slate-900 hover:underline">
                    Tutti i segnali
                </a>
            </div>

            <ul class="divide-y divide-slate-100">
                @forelse ($recentSignals as $segnale)
                    @php
                        $classiImpatto = $segnale->impact > 0
                            ? 'bg-emerald-100 text-emerald-700'
                            : ($segnale->impact < 0 ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600');
                    @endphp
                    <li class="flex items-center justify-between gap-3 px-5 py-3 text-sm" wire:key="segnale-{{ $segnale->id }}">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-slate-900">{{ $segnale->player->name }}</p>
                            <p class="text-xs text-slate-500">
                                {{ $segnale->type->label() }}
                                @if ($segnale->event_date) · {{ $segnale->event_date->format('d/m') }} @endif
                                · confidenza {{ number_format((float) $segnale->confidence, 2) }}
                            </p>
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium {{ $classiImpatto }}">
                            {{ $segnale->impact > 0 ? '+' : '' }}{{ $segnale->impact }}
                        </span>
                    </li>
                @empty
                    <li class="px-4 py-10 text-center text-sm text-slate-500">
                        Nessun segnale ancora. Carica una fonte da <a href="{{ route('conoscenza.index') }}" class="font-medium text-slate-900 underline">Conoscenza</a>.
                    </li>
                @endforelse
            </ul>
        </div>

        {{-- Salute della pipeline: il rischio operativo numero uno è che la sera
             dell'asta uno di questi servizi sia semplicemente spento. --}}
        <div class="rounded-lg border border-slate-200 bg-white">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h2 class="text-sm font-semibold text-slate-900">Salute della pipeline</h2>
                <button type="button" wire:click="refreshHealth" class="text-xs font-medium text-slate-500 hover:text-slate-900 hover:underline">
                    <span wire:loading.remove wire:target="refreshHealth">Ricontrolla</span>
                    <span wire:loading wire:target="refreshHealth">Verifico…</span>
                </button>
            </div>

            <ul class="divide-y divide-slate-100">
                @foreach ($health as $servizio)
                    <li class="flex items-start gap-2 px-5 py-3 text-sm">
                        <span class="mt-1 inline-block h-2.5 w-2.5 shrink-0 rounded-full {{ $servizio['ok'] ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                        <span>
                            <span class="font-medium text-slate-900">{{ $servizio['name'] }}</span>
                            <span class="block text-xs {{ $servizio['ok'] ? 'text-slate-500' : 'text-red-600' }}">{{ $servizio['detail'] }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>

            <div class="grid grid-cols-3 gap-2 border-t border-slate-200 px-5 py-4 text-center">
                <div>
                    <p class="text-xs text-slate-500">Fonti in coda</p>
                    <p class="mt-1 text-xl font-semibold {{ $sourcesQueued > 0 ? 'text-blue-600' : 'text-slate-900' }}">{{ $sourcesQueued }}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500">Segnali attivi</p>
                    <p class="mt-1 text-xl font-semibold text-slate-900">{{ $signalsCount }}</p>
                </div>
                <a href="{{ route('conoscenza.revisione') }}" class="rounded-md hover:bg-slate-50">
                    <p class="text-xs text-slate-500">Da rivedere</p>
                    <p class="mt-1 text-xl font-semibold {{ $signalsToReview > 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ $signalsToReview }}</p>
                </a>
            </div>
        </div>
    </div>

    @if ($playersCount === 0)
        <div class="rounded-lg border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
            Il listone è vuoto. Vai su <a href="{{ route('listone.import') }}" class="font-medium text-slate-900 underline">Import</a> per caricare il CSV quotazioni di fantacalcio.it.
        </div>
    @endif

    @if (! $myTeam)
        <div class="rounded-lg border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-600">
            Nessuna squadra è ancora segnata come "mia squadra". Vai su <a href="{{ route('lega.manage') }}" class="font-medium text-slate-900 underline">Lega</a> per configurarla.
        </div>
    @endif
</div>

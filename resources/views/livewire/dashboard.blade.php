<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Dashboard</h1>
        <p class="mt-1 text-sm text-slate-500">
            Stato essenziale della lega. Il piano d'acquisto, i segnali e la salute della pipeline arrivano nelle fasi successive.
        </p>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-500">Giocatori nel listone</p>
            <p class="mt-1 text-3xl font-semibold text-slate-900">{{ $playersCount }}</p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-500">Squadre registrate</p>
            <p class="mt-1 text-3xl font-semibold text-slate-900">{{ $teamsCount }} / {{ $config->teams_count }}</p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-500">La mia squadra</p>
            <p class="mt-1 text-3xl font-semibold text-slate-900">
                {{ $myTeam?->name ?? 'Non impostata' }}
            </p>
        </div>
    </div>

    {{-- Salute dei servizi: il rischio operativo numero uno è che la sera
         dell'asta uno di questi sia semplicemente spento. --}}
    <div class="rounded-lg border border-slate-200 bg-white p-5">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-900">Stato dei servizi</h2>
            <button type="button" wire:click="refreshHealth" class="text-xs font-medium text-slate-500 hover:text-slate-900 hover:underline">
                <span wire:loading.remove wire:target="refreshHealth">Ricontrolla</span>
                <span wire:loading wire:target="refreshHealth">Verifico…</span>
            </button>
        </div>

        <ul class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
            @foreach ($health as $servizio)
                <li class="flex items-start gap-2 text-sm">
                    <span class="mt-0.5 inline-block h-2.5 w-2.5 shrink-0 rounded-full {{ $servizio['ok'] ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                    <span>
                        <span class="font-medium text-slate-900">{{ $servizio['name'] }}</span>
                        <span class="block text-xs {{ $servizio['ok'] ? 'text-slate-500' : 'text-red-600' }}">{{ $servizio['detail'] }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- Pipeline di conoscenza --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-500">Fonti in coda</p>
            <p class="mt-1 text-3xl font-semibold text-slate-900">{{ $sourcesQueued }}</p>
        </div>

        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-500">Segnali attivi</p>
            <p class="mt-1 text-3xl font-semibold text-slate-900">{{ $signalsCount }}</p>
        </div>

        <a href="{{ route('conoscenza.revisione') }}" class="rounded-lg border border-slate-200 bg-white p-5 hover:border-slate-400">
            <p class="text-sm text-slate-500">Da rivedere</p>
            <p class="mt-1 text-3xl font-semibold {{ $signalsToReview > 0 ? 'text-amber-600' : 'text-slate-900' }}">{{ $signalsToReview }}</p>
        </a>
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

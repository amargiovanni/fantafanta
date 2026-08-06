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

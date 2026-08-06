@php
    use App\Enums\SlotStatus;

    // Mappa tasto → id squadra, l'unica cosa di dominio che il client conosce.
    $hotkeys = collect($teams)->mapWithKeys(fn (array $team) => [$team['hotkey'] => $team['id']])->all();

    $roleLabels = ['P' => 'Portieri', 'D' => 'Difensori', 'C' => 'Centrocampisti', 'A' => 'Attaccanti'];
@endphp

<div
    x-data="auctionRoom({
        resultIds: @js(array_column($results, 'id')),
        hotkeys: @js($hotkeys),
        live: @js($isLive),
    })"
    @keydown.window="onKey($event)"
    class="space-y-4"
>
    {{--
        Il battito leggero: tre aggregati ogni tre secondi. Se l'impronta dello
        stato non cambia il componente non si ridisegna nemmeno (Room::syncState).
    --}}
    <div wire:poll.3s="syncState" class="hidden"></div>

    {{-- ── Toast: l'esito dell'ultimo gesto, con l'undo a portata di dito ── --}}
    @if ($toast)
        <div
            wire:key="toast-{{ md5($toast['message']) }}"
            class="flex flex-wrap items-center gap-3 rounded-lg border px-4 py-3 text-sm font-medium
                @class([
                    'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-200' => $toast['tone'] === 'mine',
                    'border-slate-300 bg-slate-100 text-slate-900 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100' => $toast['tone'] === 'opponent',
                    'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200' => $toast['tone'] === 'undo',
                    'border-red-300 bg-red-50 text-red-900 dark:border-red-700 dark:bg-red-950 dark:text-red-200' => $toast['tone'] === 'error',
                    'border-slate-200 bg-white text-slate-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300' => $toast['tone'] === 'info',
                ])"
        >
            <span>{{ $toast['message'] }}</span>

            @if ($lastAcquisitionId)
                <button
                    type="button"
                    wire:click="undo"
                    class="ml-auto inline-flex items-center gap-2 rounded-md border border-current/30 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide hover:bg-white/60 dark:hover:bg-white/10"
                >
                    Annulla
                    <kbd class="rounded border border-current/40 px-1 font-sans text-[10px]">U</kbd>
                </button>
            @endif
        </div>
    @endif

    {{-- ── Testata: stato dell'asta, versione del piano, azioni ── --}}
    <div class="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center gap-2">
            <span class="relative flex h-2.5 w-2.5">
                @if ($isLive)
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                @endif
                <span class="relative inline-flex h-2.5 w-2.5 rounded-full {{ $isLive ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-700' }}"></span>
            </span>

            <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                {{ $auction?->name ?? 'Nessuna sessione d\'asta' }}
            </span>

            <span class="rounded-full px-2 py-0.5 text-xs font-semibold uppercase tracking-wide
                {{ $isLive ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400' }}">
                {{ $auction?->status->value ?? '—' }}
            </span>
        </div>

        <div class="flex items-center gap-2">
            @if ($plan)
                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-700 tabular-nums dark:bg-slate-800 dark:text-slate-300">
                    Piano v{{ $plan->version }}
                </span>
            @else
                <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900 dark:text-amber-300">
                    Nessun piano
                </span>
            @endif

            @if ($planGenerating)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900 dark:text-amber-300">
                    <svg class="h-3 w-3 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
                    </svg>
                    ricalcolo in corso
                </span>
            @endif
        </div>

        <div class="ml-auto flex flex-wrap items-center gap-2">
            <button
                type="button"
                wire:click="recomputeNow"
                wire:loading.attr="disabled"
                wire:target="recomputeNow"
                class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
            >
                Ricalcola ora
            </button>

            @if ($auction && ! $isLive && $auction->status !== \App\Enums\AuctionStatus::Closed)
                <button
                    type="button"
                    wire:click="startAuction"
                    class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-500"
                >
                    Avvia asta
                </button>
            @endif

            @if ($isLive)
                <button
                    type="button"
                    wire:click="closeAuction"
                    wire:confirm="Chiudere l'asta? La sala smette di accettare registrazioni."
                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400 dark:hover:bg-slate-800"
                >
                    Chiudi asta
                </button>
            @endif
        </div>
    </div>

    {{-- ── Barra mia squadra: budget, reparti, slot riempiti ── --}}
    @php
        $slotsPerRole = $state->slots();
        $summary = $plan?->budget_summary ?? [];
    @endphp

    <div class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-slate-200 bg-slate-200 sm:grid-cols-3 lg:grid-cols-6 dark:border-slate-800 dark:bg-slate-800">
        <div class="bg-emerald-50 px-4 py-3 dark:bg-emerald-950">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-400">Crediti residui</p>
            <p class="mt-0.5 text-3xl font-semibold tabular-nums text-emerald-900 dark:text-emerald-200">{{ $me['credits_remaining'] }}</p>
            <p class="text-[11px] text-emerald-700 tabular-nums dark:text-emerald-400">
                {{ $me['open_slots_total'] }} slot da riempire · max {{ max(0, $me['credits_remaining'] - $me['open_slots_total'] + 1) }}
            </p>
        </div>

        <div class="bg-white px-4 py-3 dark:bg-slate-900">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Rosa</p>
            <p class="mt-0.5 text-3xl font-semibold tabular-nums text-slate-900 dark:text-slate-100">
                {{ $me['acquired_total'] }}<span class="text-lg text-slate-400 dark:text-slate-600">/{{ $state->totalSlots() }}</span>
            </p>
            <p class="text-[11px] text-slate-500 tabular-nums dark:text-slate-400">{{ $me['credits_spent'] }} crediti spesi</p>
        </div>

        @foreach ($roles as $role)
            @php
                $allocated = (int) ($summary[$role]['allocated'] ?? 0);
                $spent = (int) ($summary[$role]['spent'] ?? 0);
                $filled = (int) ($me['acquired_by_role'][$role] ?? 0);
                $total = (int) ($slotsPerRole[$role] ?? 0);
                $over = $allocated > 0 && $spent > $allocated;
            @endphp

            <div class="bg-white px-4 py-3 dark:bg-slate-900">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    {{ $roleLabels[$role] }}
                </p>
                <p class="mt-0.5 text-xl font-semibold tabular-nums text-slate-900 dark:text-slate-100">
                    {{ $filled }}<span class="text-sm text-slate-400 dark:text-slate-600">/{{ $total }}</span>
                </p>
                <p class="text-[11px] tabular-nums {{ $over ? 'font-semibold text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400' }}">
                    {{ $spent }} / {{ $allocated ?: '—' }} crediti
                </p>
            </div>
        @endforeach
    </div>

    {{-- ── Tab: sotto lg le tre colonne diventano una alla volta ── --}}
    <div class="flex gap-1 rounded-lg border border-slate-200 bg-white p-1 lg:hidden dark:border-slate-800 dark:bg-slate-900">
        @foreach (['piano' => 'Piano', 'asta' => 'Asta', 'lega' => 'Lega'] as $key => $label)
            <button
                type="button"
                wire:click="$set('tab', '{{ $key }}')"
                class="flex-1 rounded-md px-3 py-2 text-sm font-semibold transition
                    {{ $tab === $key ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800' }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-12">

        {{-- ══════════════ COLONNA SINISTRA — PIANO VIVO ══════════════ --}}
        <section class="lg:col-span-3 {{ $tab === 'piano' ? '' : 'hidden lg:block' }}">
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2 dark:border-slate-800">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Piano vivo</h2>
                    @if ($plan)
                        <span class="text-[11px] tabular-nums text-slate-500 dark:text-slate-400">
                            {{ collect($planByRole)->flatten(1)->sum('target_price') }} allocati
                        </span>
                    @endif
                </div>

                @if (! $plan)
                    <p class="px-3 py-8 text-center text-xs text-slate-500 dark:text-slate-400">
                        Nessun piano pronto. Generane uno dalla dashboard: la sala funziona lo stesso, ma senza rete di sicurezza.
                    </p>
                @else
                    <div class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($roles as $role)
                            <div class="px-3 py-2">
                                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">
                                    {{ $roleLabels[$role] }}
                                </p>

                                <ul class="space-y-0.5">
                                    @foreach ($planByRole[$role] ?? [] as $slot)
                                        <li wire:key="slot-{{ $slot['id'] }}" class="text-[13px] leading-tight">
                                            <div class="flex items-baseline gap-1.5">
                                                <span class="w-5 shrink-0 text-[10px] tabular-nums text-slate-400 dark:text-slate-600">
                                                    {{ $role }}{{ $slot['index'] }}
                                                </span>

                                                @if ($slot['status'] === SlotStatus::Acquired)
                                                    <span class="truncate font-semibold text-emerald-700 dark:text-emerald-400">{{ $slot['name'] ?? '—' }}</span>
                                                    <span class="ml-auto shrink-0 font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">{{ $slot['target_price'] }}</span>
                                                @elseif ($slot['status'] === SlotStatus::Lost)
                                                    <span class="truncate text-red-600 line-through decoration-red-400 dark:text-red-400 dark:decoration-red-500/60">
                                                        {{ $slot['lost_name'] ?? $slot['name'] ?? '—' }}
                                                    </span>
                                                    <span class="ml-auto shrink-0 text-[11px] tabular-nums text-slate-400 dark:text-slate-500">perso</span>
                                                @else
                                                    <span class="truncate text-slate-800 dark:text-slate-200">{{ $slot['name'] ?? '—' }}</span>
                                                    <span class="ml-auto shrink-0 tabular-nums text-slate-500 dark:text-slate-400">{{ $slot['target_price'] }}</span>
                                                @endif
                                            </div>

                                            @if ($slot['status'] === SlotStatus::Lost)
                                                <div class="flex items-baseline gap-1.5 pl-6.5">
                                                    <span class="text-amber-600 dark:text-amber-400">↳</span>
                                                    <span class="truncate text-slate-800 dark:text-slate-200">{{ $slot['name'] ?? 'nessuna alternativa' }}</span>
                                                    @if ($slot['name'])
                                                        <span class="ml-auto shrink-0 tabular-nums text-slate-500 dark:text-slate-400">{{ $slot['target_price'] }}</span>
                                                    @endif
                                                </div>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        {{-- ══════════════ COLONNA CENTRALE — IL FLUSSO CALDO ══════════════ --}}
        <section class="lg:col-span-6 {{ $tab === 'asta' ? '' : 'hidden lg:block' }}">
            <div class="space-y-4">

                {{-- Search: sempre a fuoco, è il punto d'ingresso di tutto --}}
                <div class="rounded-lg border-2 border-slate-900 bg-white shadow-sm dark:border-slate-100 dark:bg-slate-900">
                    <div class="flex items-center gap-3 px-4 py-3">
                        <svg class="h-5 w-5 shrink-0 text-slate-400 dark:text-slate-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z" />
                        </svg>

                        <input
                            x-ref="search"
                            type="text"
                            autofocus
                            autocomplete="off"
                            spellcheck="false"
                            wire:model.live.debounce.250ms="search"
                            placeholder="Chi è uscito?"
                            aria-label="Cerca un giocatore"
                            class="w-full border-0 bg-transparent p-0 text-2xl font-semibold text-slate-900 placeholder:font-normal placeholder:text-slate-300 focus:outline-none dark:text-slate-100 dark:placeholder:text-slate-600"
                        />

                        <div wire:loading wire:target="search" class="shrink-0 text-xs text-slate-400 dark:text-slate-500">…</div>
                    </div>

                    @if ($results)
                        <ul class="border-t border-slate-100 dark:border-slate-800">
                            @foreach ($results as $index => $result)
                                <li wire:key="res-{{ $result['id'] }}">
                                    <button
                                        type="button"
                                        wire:click="select({{ $result['id'] }})"
                                        @mouseenter="highlight = {{ $index }}"
                                        :class="highlight === {{ $index }} ? 'bg-slate-900 text-white dark:bg-slate-700' : 'text-slate-800 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800'"
                                        class="flex w-full items-center gap-3 px-4 py-2 text-left"
                                    >
                                        <span class="w-5 shrink-0 text-center text-[11px] font-bold"
                                              :class="highlight === {{ $index }} ? 'text-white/60' : 'text-slate-400 dark:text-slate-500'">
                                            {{ $result['role'] }}
                                        </span>

                                        <span class="truncate font-semibold">{{ $result['name'] }}</span>

                                        <span class="truncate text-xs"
                                              :class="highlight === {{ $index }} ? 'text-white/60' : 'text-slate-400 dark:text-slate-500'">
                                            {{ $result['real_team'] }}
                                        </span>

                                        @if ($result['status'] !== 'available')
                                            <span class="ml-auto shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase"
                                                  :class="highlight === {{ $index }} ? 'bg-white/20 text-white' : 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300'">
                                                preso
                                            </span>
                                        @else
                                            <span class="ml-auto shrink-0 text-lg font-semibold tabular-nums">{{ $result['max_bid'] }}</span>
                                        @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>

                        <div class="flex items-center gap-3 border-t border-slate-100 px-4 py-1.5 text-[11px] text-slate-400 dark:border-slate-800 dark:text-slate-500">
                            <span><kbd class="rounded border border-slate-300 px-1 dark:border-slate-700">↑</kbd><kbd class="ml-0.5 rounded border border-slate-300 px-1 dark:border-slate-700">↓</kbd> naviga</span>
                            <span><kbd class="rounded border border-slate-300 px-1 dark:border-slate-700">Invio</kbd> seleziona</span>
                            <span><kbd class="rounded border border-slate-300 px-1 dark:border-slate-700">Esc</kbd> svuota</span>
                        </div>
                    @elseif (mb_strlen(trim($search)) >= 2)
                        <p class="border-t border-slate-100 px-4 py-3 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">
                            Nessun giocatore per «{{ $search }}».
                        </p>
                    @endif
                </div>

                {{-- Scheda decisione --}}
                @if ($card)
                    @php
                        $player = $card['player'];
                        $valuation = $card['valuation'];
                        $taken = $card['owner'] !== null;
                    @endphp

                    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                            <span class="rounded bg-slate-900 px-1.5 py-0.5 text-xs font-bold text-white dark:bg-slate-100 dark:text-slate-900">{{ $player->role->value }}</span>
                            <h2 class="text-xl font-semibold leading-tight text-slate-900 dark:text-slate-100">{{ $player->name }}</h2>
                            <span class="text-sm text-slate-500 dark:text-slate-400">{{ $player->real_team }}</span>

                            @if ($valuation?->tier)
                                @php
                                    $tierTone = [
                                        1 => 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900',
                                        2 => 'bg-slate-700 text-white dark:bg-slate-300 dark:text-slate-900',
                                        3 => 'bg-slate-400 text-white dark:bg-slate-500 dark:text-white',
                                        4 => 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200',
                                        5 => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400',
                                    ];
                                @endphp
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-bold uppercase tracking-wide {{ $tierTone[$valuation->tier] ?? 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}">
                                    tier {{ $valuation->tier }}
                                </span>
                            @endif

                            @if ($player->is_rigorista)
                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-900 dark:text-amber-300">🎯 rigorista</span>
                            @endif

                            {{-- Segnali attivi: una riga di icone, il conteggio se sono tanti --}}
                            @if ($card['signals']->isNotEmpty())
                                <span class="ml-auto flex items-center gap-1">
                                    @foreach ($card['signals']->take(4) as $signal)
                                        <span title="{{ $signal->type->label() }}: {{ $signal->summary }}" class="text-base leading-none">
                                            {{ $signal->type->icon() }}
                                        </span>
                                    @endforeach

                                    @if ($card['signals']->count() > 4)
                                        <span class="text-[11px] font-semibold text-slate-500 dark:text-slate-400">+{{ $card['signals']->count() - 4 }}</span>
                                    @endif
                                </span>
                            @endif
                        </div>

                        @if ($taken)
                            <div class="border-b border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-300">
                                Già aggiudicato a {{ $card['owner']['team'] }} per {{ $card['owner']['price'] }} crediti.
                            </div>
                        @endif

                        {{-- IL NUMERO. Deve leggersi in piedi, a due metri dallo schermo. --}}
                        <div class="flex flex-wrap items-center gap-x-8 gap-y-4 px-4 py-5">
                            <div class="shrink-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500">Max bid</p>
                                <p class="-mt-1 text-[clamp(4rem,11vw,8rem)] font-semibold leading-[0.85] tracking-tight tabular-nums {{ $taken ? 'text-slate-300 dark:text-slate-700' : 'text-slate-900 dark:text-white' }}">
                                    {{ $card['max_bid'] }}
                                </p>
                                <p class="mt-1 text-[11px] tabular-nums text-slate-400 dark:text-slate-500">
                                    tetto aritmetico {{ $card['ceiling'] }} · crediti − slot aperti + 1
                                </p>
                            </div>

                            <dl class="grid flex-1 grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                                @foreach ([
                                    'Valore' => $valuation ? number_format((float) $valuation->adjusted_value, 1, ',', '') : '—',
                                    'Quotazione' => $player->quotazione,
                                    'FVM' => $player->fvm,
                                    'Scarsità' => $valuation ? number_format((float) $valuation->scarcity_index, 2, ',', '') : '—',
                                ] as $label => $value)
                                    <div>
                                        <dt class="text-[11px] uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ $label }}</dt>
                                        <dd class="text-lg font-semibold tabular-nums text-slate-800 dark:text-slate-200">{{ $value }}</dd>
                                    </div>
                                @endforeach

                                @foreach ([
                                    'Fantamedia' => $card['stats']['fantamedia'],
                                    'Media voto' => $card['stats']['media_voto'],
                                    'Presenze' => $card['stats']['presenze'],
                                    'Ammonizioni' => $card['stats']['ammonizioni'],
                                ] as $label => $value)
                                    <div>
                                        <dt class="text-[11px] uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ $label }}</dt>
                                        <dd class="text-lg font-semibold tabular-nums text-slate-600 dark:text-slate-400">{{ $value ?? '—' }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>

                        {{-- Il piano su questo nome --}}
                        <div class="border-t border-slate-100 px-4 py-2.5 text-sm dark:border-slate-800">
                            @if ($card['plan'] === null)
                                <span class="text-slate-500 dark:text-slate-400">Non nel piano.</span>
                            @else
                                <span class="font-semibold text-slate-900 dark:text-slate-100">{{ $card['plan']['label'] }}</span>
                                <span class="text-slate-600 dark:text-slate-400">
                                    · target <span class="font-semibold tabular-nums">{{ $card['plan']['target_price'] }}</span>
                                    @isset($card['plan']['max_price'])
                                        · max <span class="font-semibold tabular-nums">{{ $card['plan']['max_price'] }}</span>
                                    @endisset
                                </span>

                                @if ($card['plan']['is_starter'] && $card['plan']['successor'])
                                    <span class="text-slate-500 dark:text-slate-400">— se sfuma subentra <span class="font-medium text-slate-700 dark:text-slate-300">{{ $card['plan']['successor'] }}</span></span>
                                @elseif ($card['plan']['is_starter'])
                                    <span class="text-amber-700 dark:text-amber-400">— nessuna alternativa disponibile</span>
                                @endif
                            @endif
                        </div>

                        {{-- Registrazione: prezzo e assegnazione, tutto da tastiera --}}
                        @if (! $taken && $isLive)
                            <div class="border-t border-slate-200 bg-slate-50 px-4 py-4 dark:border-slate-800 dark:bg-slate-800/50">
                                <div class="flex flex-wrap items-end gap-6">
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-400 dark:text-slate-500">Prezzo</p>
                                        <p class="text-6xl font-semibold leading-none tabular-nums text-slate-900 dark:text-white">
                                            <span x-text="price === '' ? '—' : price"></span>
                                        </p>
                                    </div>

                                    <div class="flex-1 text-sm">
                                        <template x-if="state === 'selected'">
                                            <p class="text-slate-500 dark:text-slate-400">Digita il prezzo battuto.</p>
                                        </template>

                                        <template x-if="state === 'pricing'">
                                            <p class="text-slate-700 dark:text-slate-300">
                                                <kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-xs font-semibold dark:border-slate-600 dark:bg-slate-900">Invio</kbd>
                                                è mio ·
                                                <kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-xs font-semibold dark:border-slate-600 dark:bg-slate-900">Spazio</kbd>
                                                poi il numero della squadra
                                            </p>
                                        </template>

                                        <template x-if="state === 'assigning'">
                                            <p class="font-semibold text-emerald-700 dark:text-emerald-400">
                                                A chi? Premi il tasto della squadra — <kbd class="rounded border border-emerald-300 bg-white px-1.5 py-0.5 text-xs dark:border-emerald-700 dark:bg-slate-900">0</kbd> sono io.
                                            </p>
                                        </template>
                                    </div>
                                </div>

                                {{-- Fallback col mouse: gli stessi tasti, cliccabili --}}
                                <div class="mt-3 flex flex-wrap gap-1.5">
                                    @foreach ($teams as $team)
                                        <button
                                            type="button"
                                            @click="assign('{{ $team['hotkey'] }}')"
                                            :disabled="price === '' || Number(price) < 1"
                                            class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-semibold disabled:opacity-40
                                                {{ $team['is_mine']
                                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-800 hover:bg-emerald-100 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-300 dark:hover:bg-emerald-900'
                                                    : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800' }}"
                                        >
                                            <kbd class="rounded border border-current/30 px-1 tabular-nums">{{ $team['hotkey'] }}</kbd>
                                            <span class="max-w-32 truncate">{{ $team['name'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @elseif (! $isLive)
                            <div class="border-t border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                                L'asta non è in corso: consultazione soltanto.
                            </div>
                        @endif
                    </div>
                @else
                    <div class="rounded-lg border border-dashed border-slate-300 px-4 py-12 text-center dark:border-slate-700">
                        <p class="text-sm text-slate-500 dark:text-slate-400">
                            Scrivi un nome e premi <kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-xs font-semibold dark:border-slate-700 dark:bg-slate-900">Invio</kbd>.
                        </p>
                        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">
                            Il resto della serata si fa senza mouse.
                        </p>
                    </div>
                @endif
            </div>
        </section>

        {{-- ══════════════ COLONNA DESTRA — LA LEGA ══════════════ --}}
        <section class="lg:col-span-3 {{ $tab === 'lega' ? '' : 'hidden lg:block' }}">
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b border-slate-200 px-3 py-2 dark:border-slate-800">
                    <h2 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Lega</h2>
                </div>

                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($teams as $team)
                        @php
                            // Chi ha molti crediti per slot rilancerà: è l'unica
                            // informazione che serve per decidere se insistere.
                            $rich = $team['open_slots_total'] > 0 && $team['credits_per_open_slot'] >= 15;
                        @endphp

                        <li wire:key="team-{{ $team['id'] }}" class="px-3 py-2 {{ $team['is_mine'] ? 'bg-emerald-50 dark:bg-emerald-950/40' : '' }}">
                            <div class="flex items-baseline gap-2">
                                <kbd class="shrink-0 rounded border px-1 text-[11px] font-bold tabular-nums
                                    {{ $team['is_mine'] ? 'border-emerald-400 text-emerald-800 dark:border-emerald-700 dark:text-emerald-400' : 'border-slate-300 text-slate-600 dark:border-slate-700 dark:text-slate-400' }}">
                                    {{ $team['hotkey'] }}
                                </kbd>

                                <span class="truncate text-[13px] font-semibold {{ $team['is_mine'] ? 'text-emerald-900 dark:text-emerald-300' : 'text-slate-800 dark:text-slate-200' }}">
                                    {{ $team['name'] }}
                                </span>

                                <span class="ml-auto shrink-0 text-base font-semibold tabular-nums {{ $rich ? 'text-amber-700 dark:text-amber-400' : 'text-slate-700 dark:text-slate-300' }}">
                                    {{ $team['credits_remaining'] }}
                                </span>
                            </div>

                            <div class="mt-0.5 flex items-center gap-2 pl-6 text-[11px] tabular-nums text-slate-500 dark:text-slate-400">
                                @foreach ($roles as $role)
                                    <span class="{{ ($team['open_slots_by_role'][$role] ?? 0) === 0 ? 'text-slate-300 dark:text-slate-600' : '' }}">
                                        {{ $role }}<span class="font-semibold">{{ $team['open_slots_by_role'][$role] ?? 0 }}</span>
                                    </span>
                                @endforeach

                                <span class="ml-auto {{ $rich ? 'font-semibold text-amber-700 dark:text-amber-400' : '' }}">
                                    max {{ $team['max_bid'] }}
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <div class="border-t border-slate-100 bg-slate-50 px-3 py-2 text-[11px] leading-relaxed text-slate-500 dark:border-slate-800 dark:bg-slate-800/50 dark:text-slate-400">
                    <span class="font-semibold text-slate-600 dark:text-slate-300">Tasti:</span>
                    prezzo → <kbd class="rounded border border-slate-300 bg-white px-1 dark:border-slate-700 dark:bg-slate-900">Invio</kbd> è mio,
                    <kbd class="rounded border border-slate-300 bg-white px-1 dark:border-slate-700 dark:bg-slate-900">Spazio</kbd> +
                    <kbd class="rounded border border-slate-300 bg-white px-1 dark:border-slate-700 dark:bg-slate-900">1</kbd>–<kbd class="rounded border border-slate-300 bg-white px-1 dark:border-slate-700 dark:bg-slate-900">9</kbd> avversario,
                    <kbd class="rounded border border-slate-300 bg-white px-1 dark:border-slate-700 dark:bg-slate-900">U</kbd> annulla,
                    <kbd class="rounded border border-slate-300 bg-white px-1 dark:border-slate-700 dark:bg-slate-900">Esc</kbd> indietro.
                </div>
            </div>
        </section>
    </div>
</div>

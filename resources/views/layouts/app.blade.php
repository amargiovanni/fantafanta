<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fanta Asta AI</title>

    {{-- Tema applicato PRIMA di qualunque paint: senza questo script bloccante
         nel <head>, chi ha scelto "scuro" vedrebbe un lampo chiaro a ogni
         caricamento finché Alpine non si inizializza (ADR 0007). --}}
    <script>
        (function () {
            var stored = localStorage.getItem('theme');
            var theme = (stored === 'dark' || stored === 'light')
                ? stored
                : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body
    x-data="{ theme: document.documentElement.getAttribute('data-theme') }"
    x-init="$watch('theme', (value) => { localStorage.setItem('theme', value); document.documentElement.setAttribute('data-theme', value) })"
    class="min-h-screen bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100"
>
    <div class="min-h-screen flex flex-col">
        <header class="border-b border-slate-200 bg-white print:hidden dark:border-slate-800 dark:bg-slate-900">
            <nav class="mx-auto flex w-full items-center gap-1 px-4 py-3 {{ request()->routeIs('asta') ? 'max-w-[110rem]' : 'max-w-6xl' }}">
                <span class="mr-4 text-lg font-semibold text-slate-900 dark:text-slate-100">Fanta Asta AI</span>

                @php
                    $navItems = [
                        ['route' => 'dashboard', 'label' => 'Dashboard'],
                        ['route' => 'asta', 'label' => 'Sala d\'asta'],
                        ['route' => 'conoscenza.index', 'label' => 'Conoscenza'],
                        ['route' => 'listone.index', 'label' => 'Listone'],
                        ['route' => 'listone.import', 'label' => 'Import'],
                        ['route' => 'lega.manage', 'label' => 'Lega'],
                    ];
                @endphp

                @foreach ($navItems as $item)
                    <a
                        href="{{ route($item['route']) }}"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ request()->routeIs($item['route']) ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800' }}"
                    >
                        {{ $item['label'] }}
                    </a>
                @endforeach

                <button
                    type="button"
                    @click="theme = (theme === 'dark' ? 'light' : 'dark')"
                    class="ml-auto flex items-center gap-1.5 rounded-md border border-slate-200 px-2.5 py-1.5 text-sm font-medium text-slate-600 transition hover:bg-slate-100 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                    :aria-label="theme === 'dark' ? 'Passa al tema chiaro' : 'Passa al tema scuro'"
                >
                    <svg x-show="theme !== 'dark'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-4"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
                    <svg x-show="theme === 'dark'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-4"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z"/></svg>
                    <span x-text="theme === 'dark' ? 'Scuro' : 'Chiaro'"></span>
                </button>
            </nav>
        </header>

        {{-- La sala d'asta lavora a tre colonne dense: 6xl stringerebbe la
             colonna lega fino a renderla illeggibile. --}}
        <main class="mx-auto w-full flex-1 px-4 print:max-w-none print:px-0 print:py-0 {{ request()->routeIs('asta') ? 'max-w-[110rem] py-4' : 'max-w-6xl py-8' }}">
            {{ $slot }}
        </main>
    </div>
</body>
</html>

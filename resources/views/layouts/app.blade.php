<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fanta Asta AI</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <div class="min-h-screen flex flex-col">
        <header class="border-b border-slate-200 bg-white">
            <nav class="mx-auto flex w-full items-center gap-1 px-4 py-3 {{ request()->routeIs('asta') ? 'max-w-[110rem]' : 'max-w-6xl' }}">
                <span class="mr-4 text-lg font-semibold text-slate-900">Fanta Asta AI</span>

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
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition {{ request()->routeIs($item['route']) ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100' }}"
                    >
                        {{ $item['label'] }}
                    </a>
                @endforeach
            </nav>
        </header>

        {{-- La sala d'asta lavora a tre colonne dense: 6xl stringerebbe la
             colonna lega fino a renderla illeggibile. --}}
        <main class="mx-auto w-full flex-1 px-4 {{ request()->routeIs('asta') ? 'max-w-[110rem] py-4' : 'max-w-6xl py-8' }}">
            {{ $slot }}
        </main>
    </div>
</body>
</html>

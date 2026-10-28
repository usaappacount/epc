<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'لوحة التحكم')</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="ledger-shell">
    <div class="flex min-h-screen">
    <button id="sidebar-backdrop" type="button" aria-label="إغلاق القائمة" class="fixed inset-0 z-40 hidden bg-slate-950/45 md:hidden"></button>

    <aside id="app-sidebar" class="ledger-sidebar fixed right-0 top-0 z-50 translate-x-full transition-transform duration-200 md:sticky md:translate-x-0 md:z-auto" aria-label="القائمة الرئيسية">
        <div class="ledger-brand">
            <div class="ledger-brand-mark" aria-hidden="true">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
            </div>
            <div class="min-w-0">
                <h1 class="text-base font-bold text-white">نظام المخزون الذكي</h1>
                <p class="mt-1 truncate text-xs text-slate-400">{{ auth()->user()->name ?? 'المستخدم' }} · {{ auth()->check() && auth()->user()->role === 'admin' ? 'مدير النظام' : 'عميل / تاجر' }}</p>
            </div>
        </div>

        <nav class="flex-1 space-y-1.5 p-3">
            <!-- المخزون (الأساسي للعميل والمدير) -->
            <a href="{{ route('inventory.index') }}"
               class="ledger-nav-link {{ request()->routeIs('inventory.*') ? 'ledger-nav-link-active' : '' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                <span>{{ (auth()->check() && auth()->user()->role === 'admin') ? 'المخزون ' : 'المخزون' }}</span>
            </a>

            <!-- الرد الآلي والذكاء الاصطناعي -->
            <a href="{{ route('pages.second') }}"
               class="ledger-nav-link {{ request()->routeIs('pages.second') ? 'ledger-nav-link-active' : '' }}">
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a8 8 0 01-8 8H6l-4 2 2-4a8 8 0 010-12 8 8 0 0111-1"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01"/></svg>
                <span>بوت الواتساب</span>
            </a>

            @if(auth()->check() && auth()->user()->role === 'admin')
                <div class="px-3 pb-1 pt-4 text-[11px] font-bold text-slate-500">إدارة النظام</div>

                <!-- العملاء -->
                <a href="{{ route('clients.index') }}"
                   class="ledger-nav-link {{ request()->routeIs('clients.*') ? 'ledger-nav-link-active' : '' }}">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zm13 10v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                    <span>العملاء</span>
                </a>

                <!-- المخزون ( خاص ) -->
                <a href="{{ route('master.catalog') }}"
                   class="ledger-nav-link {{ request()->routeIs('master.*') ? 'ledger-nav-link-active' : '' }}">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 12h18M12 3a15 15 0 010 18M12 3a15 15 0 000 18"/></svg>
                    <span> المخزون ( خاص )</span>
                </a>

                <!-- إعدادات الربط API -->
                <a href="{{ route('pages.third') }}"
                   class="ledger-nav-link {{ request()->routeIs('pages.third') ? 'ledger-nav-link-active' : '' }}">
                    <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.4 15a1.7 1.7 0 00.34 1.88l.06.06-1.8 1.8-.06-.06a1.7 1.7 0 00-1.88-.34 1.7 1.7 0 00-1 1.56V20h-2.55v-.1a1.7 1.7 0 00-1-1.56 1.7 1.7 0 00-1.88.34l-.06.06-1.8-1.8.06-.06A1.7 1.7 0 007.6 15a1.7 1.7 0 00-1.56-1H6v-2.55h.04a1.7 1.7 0 001.56-1 1.7 1.7 0 00-.34-1.88l-.06-.06 1.8-1.8.06.06A1.7 1.7 0 0010.94 7a1.7 1.7 0 001-1.56V5h2.55v.44a1.7 1.7 0 001 1.56 1.7 1.7 0 001.88-.34l.06-.06 1.8 1.8-.06.06A1.7 1.7 0 0018 10.34a1.7 1.7 0 001.56 1H20v2.55h-.44A1.7 1.7 0 0019.4 15z"/></svg>
                    <span>إعدادات الربط API</span>
                </a>
            @endif
        </nav>

        <div class="border-t border-slate-800 p-3">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                    class="flex w-full items-center gap-3 rounded-lg px-3.5 py-3 text-right text-sm font-semibold text-rose-300 transition hover:bg-slate-800 hover:text-rose-200">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14 8V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2h7a2 2 0 002-2v-3M10 12h11m0 0-3-3m3 3-3 3"/></svg>
                    تسجيل الخروج
                </button>
            </form>
        </div>
    </aside>

    <div class="min-w-0 flex-1">
        <header class="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-slate-200/80 bg-white/95 px-4 backdrop-blur md:hidden">
            <button id="sidebar-open" type="button" class="ledger-button-secondary !min-h-10 !px-3" aria-controls="app-sidebar" aria-expanded="false" aria-label="فتح القائمة">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <div class="text-sm font-bold text-slate-800">نظام المخزون الذكي</div>
        </header>
    <main class="ledger-main">
        @if(session('success'))
            <div class="ledger-alert-success mb-5">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="ledger-alert-danger mb-5">
                {{ session('error') }}
            </div>
        @endif

        @if($errors->any())
            <div class="ledger-alert-danger mb-5">
                <ul class="list-disc list-inside text-sm">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
    </div>
    </div>
    <script>
        const sidebar = document.getElementById('app-sidebar');
        const sidebarOpen = document.getElementById('sidebar-open');
        const sidebarBackdrop = document.getElementById('sidebar-backdrop');
        function setSidebar(open) {
            sidebar?.classList.toggle('translate-x-full', !open);
            sidebar?.classList.toggle('sidebar-open', open);
            sidebarOpen?.setAttribute('aria-expanded', open ? 'true' : 'false');
            sidebarBackdrop?.classList.toggle('hidden', !open);
            document.body.classList.toggle('overflow-hidden', open);
        }
        sidebarOpen?.addEventListener('click', () => setSidebar(true));
        sidebarBackdrop?.addEventListener('click', () => setSidebar(false));
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 768) setSidebar(false);
        });
    </script>

</body>
</html>
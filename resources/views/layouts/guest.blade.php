<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="auth-shell font-sans text-slate-800 antialiased">
        <div class="auth-shell-grid">
            <aside class="auth-story">
                <a href="/" class="flex items-center gap-3 text-white">
                    <span class="ledger-brand-mark"><x-application-logo class="h-6 w-auto fill-current text-emerald-400" /></span>
                    <span class="text-lg font-bold">نظام المخزون الذكي</span>
                </a>
                <div>
                    <h1 class="max-w-xl text-4xl font-bold leading-tight">إدارة المخزون بوضوح وسرعة</h1>
                    <p class="mt-4 max-w-lg text-base leading-7 text-slate-300">صنوفك، عملاؤك، وحالة الاستيراد والتكاملات في مساحة عمل عربية واحدة.</p>
                </div>
                <p class="text-xs text-slate-400">Operational Ledger · Protected workspace</p>
            </aside>
            <main class="flex items-center justify-center">
                <div class="auth-card">
                    {{ $slot }}
                </div>
            </main>
        </div>
    </body>
</html>

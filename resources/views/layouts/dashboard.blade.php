
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'لوحة التحكم')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Cairo', sans-serif;
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen flex">

    <aside class="w-64 bg-slate-900 text-white flex flex-col shrink-0">
        <div class="p-6 border-b border-slate-800">
            <h1 class="text-xl font-bold text-emerald-400">نظام المخزون الذكي</h1>
            <p class="text-xs text-slate-400 mt-2">
                مرحبًا، {{ auth()->user()->name }}
                ({{ auth()->user()->role === 'admin' ? 'مدير' : 'موظف' }})
            </p>
        </div>

        <nav class="flex-1 p-4 space-y-2">
            <a href="{{ route('inventory.index') }}"
               class="flex items-center gap-3 px-4 py-3 rounded-lg transition {{ request()->routeIs('inventory.*') ? 'bg-emerald-600 text-white font-semibold' : 'text-slate-300 hover:bg-slate-800' }}">
                📦
                <span>المخزون</span>
            </a>

            <a href="{{ route('pages.second') }}"
               class="flex items-center gap-3 px-4 py-3 rounded-lg transition {{ request()->routeIs('pages.second') ? 'bg-emerald-600 text-white font-semibold' : 'text-slate-300 hover:bg-slate-800' }}">
                🤖
                <span>الذكاء الاصطناعي والواتساب</span>
            </a>
        </nav>

        <div class="p-4 border-t border-slate-800">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                    class="w-full text-right px-4 py-2 text-sm text-rose-400 hover:bg-slate-800 rounded-lg transition">
                    🚪 تسجيل الخروج
                </button>
            </form>
        </div>
    </aside>

    <main class="flex-1 p-8 overflow-y-auto">
        @if(session('success'))
            <div class="mb-6 p-4 bg-emerald-100 border border-emerald-300 text-emerald-800 rounded-lg">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="mb-6 p-4 bg-rose-100 border border-rose-300 text-rose-800 rounded-lg">
                <ul class="list-disc list-inside text-sm">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>

</body>
</html>

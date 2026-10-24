<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-gray-100 text-gray-900">
        <div class="min-h-screen flex">

            <!-- الشريط الجانبي (Sidebar) على اليمين -->
            <aside class="w-64 bg-white border-l border-gray-200 hidden md:flex flex-col justify-between shrink-0 min-h-screen">
                <div>
                    <!-- الشعار / العنوان -->
                    <div class="h-16 flex items-center justify-center border-b border-gray-100 px-4">
                        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-bold text-lg text-gray-800">
                            <x-application-logo class="block h-8 w-auto fill-current text-gray-800" />
                            <span>لوحة التحكم</span>
                        </a>
                    </div>

                    <!-- روابط القائمة الجانبية -->
                    <nav class="p-4 space-y-2">
                        <!-- رابط المخزون واللوحة -->
                        <a href="{{ route('dashboard') }}" 
                           class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('dashboard') || request()->routeIs('inventory.*') ? 'bg-blue-50 text-blue-700 font-bold' : 'text-gray-700 hover:bg-gray-50' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                            <span>المخزون واللوحة</span>
                        </a>

                        <!-- رابط مساعد الواتساب (الصفحة الثانية) -->
                        <a href="{{ route('pages.second') }}" 
                           class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('pages.second') ? 'bg-blue-50 text-blue-700 font-bold' : 'text-gray-700 hover:bg-gray-50' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                            <span>مساعد الواتساب</span>
                        </a>

                        <!-- رابط إعدادات الربط API (الصفحة الثالثة) -->
                        <a href="{{ route('pages.third') }}" 
                           class="flex items-center gap-3 px-4 py-3 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('pages.third') ? 'bg-blue-600 text-white font-bold' : 'text-gray-700 hover:bg-gray-50' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <span>إعدادات الربط API</span>
                        </a>
                    </nav>
                </div>

                <!-- معلومات المستخدم وتسجيل الخروج -->
                <div class="p-4 border-t border-gray-100">
                    <div class="mb-3 px-2">
                        <p class="text-sm font-bold text-gray-800">{{ Auth::user()->name ?? '' }}</p>
                        <p class="text-xs text-gray-500">{{ Auth::user()->email ?? '' }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full text-right flex items-center gap-2 px-3 py-2 text-sm text-red-600 rounded-md hover:bg-red-50 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                            <span>تسجيل الخروج</span>
                        </button>
                    </form>
                </div>
            </aside>

            <!-- محتوى الصفحة الرئيسي على اليسار -->
            <div class="flex-1 flex flex-col min-w-0">
                @include('layouts.navigation')

                <!-- رأس الصفحة -->
                @isset($header)
                    <header class="bg-white shadow">
                        <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                            {{ $header }}
                        </div>
                    </header>
                @endisset

                <!-- محتوى الصفحة -->
                <main class="flex-1 p-6">
                    {{ $slot }}
                </main>
            </div>

        </div>
    </body>
</html>
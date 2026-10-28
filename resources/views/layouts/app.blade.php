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
    <body class="font-sans antialiased bg-slate-100 text-slate-800">
        <div class="min-h-screen flex">

            <!-- الشريط الجانبي (Sidebar) على اليمين -->
            <aside class="ledger-sidebar hidden border-l border-slate-800 md:flex">
                <div>
                    <!-- الشعار / العنوان -->
                    <div class="ledger-brand">
                        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 text-lg font-bold text-white">
                            <span class="ledger-brand-mark"><x-application-logo class="block h-5 w-auto fill-current text-emerald-400" /></span>
                            <span>لوحة التحكم</span>
                        </a>
                    </div>

                    <!-- روابط القائمة الجانبية -->
                    <nav class="space-y-1 p-3">
                        <!-- رابط المخزون واللوحة -->
                        <a href="{{ route('dashboard') }}" 
                           class="ledger-nav-link {{ request()->routeIs('dashboard') || request()->routeIs('inventory.*') ? 'ledger-nav-link-active' : '' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                            <span>المخزون واللوحة</span>
                        </a>

                        <!-- رابط مساعد الواتساب (الصفحة الثانية) -->
                        <a href="{{ route('pages.second') }}" 
                           class="ledger-nav-link {{ request()->routeIs('pages.second') ? 'ledger-nav-link-active' : '' }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                            <span>مساعد الواتساب</span>
                        </a>

                        <!-- رابط إعدادات الربط API (الصفحة الثالثة) -->
                        <a href="{{ route('pages.third') }}" 
                           class="ledger-nav-link {{ request()->routeIs('pages.third') ? 'ledger-nav-link-active' : '' }}">
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
                <main class="ledger-main">
                    {{ $slot }}
                </main>
            </div>

        </div>

        <!-- ودجت عائم عابر للصفحات لمتابعة استيراد الإكسل في الخلفية واسترجاع النسخة الفورية -->
        @auth
        <div id="global-bg-import-widget" class="hidden fixed bottom-5 left-5 z-50 w-80 sm:w-96 bg-white rounded-2xl shadow-2xl border border-slate-200 p-4 transition-all duration-300">
            <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-2">
                    <span id="global-import-spinner" class="inline-block w-3 h-3 rounded-full bg-indigo-600 animate-ping"></span>
                    <h4 id="global-import-title" class="text-sm font-bold text-slate-800">جاري استيراد الإكسل في الخلفية...</h4>
                </div>
                <button type="button" onclick="dismissGlobalImportWidget()" class="text-slate-400 hover:text-slate-600 text-lg leading-none">&times;</button>
            </div>

            <p id="global-import-filename" class="text-xs text-slate-500 truncate mb-2"></p>

            <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden mb-2">
                <div id="global-import-bar" class="bg-indigo-600 h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
            </div>

            <div class="flex items-center justify-between text-xs text-slate-600 font-semibold">
                <span id="global-import-stats">0 / 0 صنف</span>
                <span id="global-import-percent" class="text-indigo-600 font-bold">0%</span>
            </div>

            <div id="global-import-details" class="mt-2 pt-2 border-t border-slate-100 flex items-center justify-between text-[11px] text-slate-500">
                <span>جديد: <strong id="global-import-new" class="text-emerald-600">0</strong> | محدث: <strong id="global-import-updated" class="text-blue-600">0</strong></span>
                <button type="button" id="global-rollback-btn" onclick="triggerGlobalRollback()" class="hidden text-rose-600 hover:text-rose-700 font-bold underline">
                    ↩️ تراجع فوري
                </button>
            </div>
        </div>

        <script>
            (function () {
                if (window.__bgImportTrackerInitialized) return;
                window.__bgImportTrackerInitialized = true;

                let pollTimer = null;
                let lastCompletedJobId = sessionStorage.getItem('dismissed_import_job_id');

                async function checkBackgroundImportStatus() {
                    try {
                        const res = await fetch("{{ route('inventory.import.status') }}", {
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        if (!res.ok) return;
                        const data = await res.json();
                        const job = data.job;

                        if (!job) return;

                        const widget = document.getElementById('global-bg-import-widget');
                        const titleEl = document.getElementById('global-import-title');
                        const fileEl = document.getElementById('global-import-filename');
                        const barEl = document.getElementById('global-import-bar');
                        const statsEl = document.getElementById('global-import-stats');
                        const percentEl = document.getElementById('global-import-percent');
                        const newEl = document.getElementById('global-import-new');
                        const updEl = document.getElementById('global-import-updated');
                        const spinnerEl = document.getElementById('global-import-spinner');
                        const rollbackBtn = document.getElementById('global-rollback-btn');

                        if (job.status === 'pending' || job.status === 'processing') {
                            sessionStorage.setItem('active_import_job_id', job.id);
                            widget.classList.remove('hidden');
                            spinnerEl.className = 'inline-block w-3 h-3 rounded-full bg-indigo-600 animate-ping';
                            titleEl.innerText = 'جاري استيراد الإكسل في الخلفية...';
                            fileEl.innerText = job.file_name || 'ملف مخزون';
                            barEl.style.width = (data.percentage || 0) + '%';
                            statsEl.innerText = `${job.processed_rows || 0} / ${job.total_rows || '?'} صنف`;
                            percentEl.innerText = (data.percentage || 0) + '%';
                            newEl.innerText = job.imported_count || 0;
                            updEl.innerText = job.updated_count || 0;
                            rollbackBtn.classList.add('hidden');

                            if (!pollTimer) {
                                pollTimer = setInterval(checkBackgroundImportStatus, 2000);
                            }
                        } else if (job.status === 'completed') {
                            const activeId = sessionStorage.getItem('active_import_job_id');
                            if (String(job.id) !== String(lastCompletedJobId) && String(job.id) === String(activeId)) {
                                widget.classList.remove('hidden');
                                spinnerEl.className = 'inline-block w-3 h-3 rounded-full bg-emerald-500';
                                titleEl.innerText = '✅ اكتمل الاستيراد في الخلفية!';
                                fileEl.innerText = job.file_name || '';
                                barEl.style.width = '100%';
                                barEl.className = 'bg-emerald-500 h-2.5 rounded-full transition-all duration-300';
                                statsEl.innerText = `اكتمل (${job.processed_rows} صنف)`;
                                percentEl.innerText = '100%';
                                newEl.innerText = job.imported_count || 0;
                                updEl.innerText = job.updated_count || 0;
                                if (job.snapshot_id) {
                                    rollbackBtn.classList.remove('hidden');
                                }

                                if (typeof window.refreshInventoryTableSilently === 'function') {
                                    window.refreshInventoryTableSilently();
                                }
                            }
                            if (pollTimer) {
                                clearInterval(pollTimer);
                                pollTimer = null;
                            }
                        } else if (job.status === 'failed') {
                            const activeId = sessionStorage.getItem('active_import_job_id');
                            if (String(job.id) === String(activeId)) {
                                widget.classList.remove('hidden');
                                spinnerEl.className = 'inline-block w-3 h-3 rounded-full bg-rose-600';
                                titleEl.innerText = '❌ تعذر استكمال الاستيراد';
                                fileEl.innerText = job.error_message || 'حدث خطأ أثناء قراءة الملف';
                            }
                            if (pollTimer) {
                                clearInterval(pollTimer);
                                pollTimer = null;
                            }
                        }
                    } catch (e) {
                        // تجاهل أخطاء الشبكة العابرة أثناء التنقل
                    }
                }

                window.dismissGlobalImportWidget = function () {
                    const activeId = sessionStorage.getItem('active_import_job_id');
                    if (activeId) {
                        sessionStorage.setItem('dismissed_import_job_id', activeId);
                        lastCompletedJobId = activeId;
                    }
                    document.getElementById('global-bg-import-widget').classList.add('hidden');
                };

                window.triggerGlobalRollback = async function () {
                    if (!confirm('هل أنت متأكد من التراجع عن هذا الاستيراد واستعادة حالة المخزون السابقة فوراً؟')) return;
                    try {
                        const res = await fetch("{{ route('inventory.import.rollback') }}", {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({})
                        });
                        const data = await res.json();
                        alert(data.message || 'تم استرجاع المخزون السابق.');
                        window.location.reload();
                    } catch (e) {
                        alert('حدث خطأ أثناء محاولة الاسترجاع.');
                    }
                };

                window.startGlobalImportPolling = function (jobId) {
                    if (jobId) {
                        sessionStorage.setItem('active_import_job_id', jobId);
                        sessionStorage.removeItem('dismissed_import_job_id');
                    }
                    checkBackgroundImportStatus();
                    if (!pollTimer) {
                        pollTimer = setInterval(checkBackgroundImportStatus, 1800);
                    }
                };

                document.addEventListener('DOMContentLoaded', checkBackgroundImportStatus);
            })();
        </script>
        @endauth
    </body>
</html>
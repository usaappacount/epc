<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إدارة الـ API والـ Webhooks - نظام المخزون </title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Cairo', sans-serif; }</style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen">

    <!-- الشريط العلوي -->
    <nav class="bg-slate-900 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <div class="bg-indigo-600 p-2 rounded-lg">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
                <h1 class="text-xl font-bold tracking-wide">نظام المخزون  - إعدادات الربط</h1>
            </div>
            <div class="flex items-center gap-4">
                <a href="{{ route('inventory.index') }}" class="text-sm bg-slate-800 hover:bg-slate-700 text-slate-200 px-4 py-2 rounded-lg transition">
                    العودة للمخزون
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- بطاقة رابط الـ Webhook الخاص بنظامك -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 mb-8">
            <h2 class="text-lg font-bold text-slate-800 mb-2">🔗 رابط استقبال الـ Webhook (Webhook Endpoint)</h2>
            <p class="text-sm text-slate-500 mb-4">
                قم بنسخ هذا الرابط وضعه في منصة واتساب أو أي نظام خارجي ترغب في ربطه، لكي يتم إرسال الأحداث ورسائل العملاء إليه فوراً:
            </p>
            <div class="flex items-center gap-2">
                <input type="text" readonly value="{{ url('/api/webhook/receiver') }}" 
                       class="w-full bg-slate-100 border border-slate-300 rounded-lg px-4 py-2.5 text-sm font-mono text-indigo-600 select-all" id="webhookUrl">
                <button onclick="navigator.clipboard.writeText(document.getElementById('webhookUrl').value); alert('تم نسخ الرابط بنجاح!');"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition whitespace-nowrap">
                    نسخ الرابط
                </button>
            </div>
        </div>

        <!-- جدول سجلات الأحداث الواردة -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="p-5 border-b border-slate-200 flex justify-between items-center">
                <h3 class="font-bold text-slate-800">📥 سجل أحداث الـ Webhook الواردة حديثاً</h3>
                <button onclick="location.reload();" class="text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-1.5 rounded-lg transition">
                    🔄 تحديث السجلات
                </button>
            </div>
            <table class="w-full text-right border-collapse">
                <thead class="bg-slate-100 text-slate-600 text-sm border-b border-slate-200">
                    <tr>
                        <th class="py-3 px-4">رقم الحدث</th>
                        <th class="py-3 px-4">نوع الحدث</th>
                        <th class="py-3 px-4">البيانات الواردة (Payload)</th>
                        <th class="py-3 px-4">وقت الاستلام</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 text-sm font-mono">
                    @forelse($webhookLogs as $log)
                        <tr class="hover:bg-slate-50 transition">
                            <td class="py-3 px-4 text-indigo-600 font-bold">#{{ $log->id }}</td>
                            <td class="py-3 px-4">
                                <span class="bg-emerald-100 text-emerald-800 text-xs font-semibold px-2.5 py-1 rounded-full font-sans">
                                    {{ $log->event_type }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-xs text-slate-600 max-w-md truncate" title="{{ $log->payload }}">
                                {{ $log->payload }}
                            </td>
                            <td class="py-3 px-4 text-xs text-slate-500 font-sans">{{ $log->created_at }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-8 text-center text-slate-400 font-sans">
                                لا توجد أحداث واردة حتى الآن. يمكنك تجربة إرسال طلب تجريبي لرابط الـ Webhook.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    </main>
</body>
</html>
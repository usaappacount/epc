@extends('layouts.dashboard')

@section('title', 'الرد الآلي عبر الواتساب (برقم الصنف)')

@section('content')
<div class="space-y-6">

    <!-- فحص الاستعلام المباشر برقم الصنف -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                    <span>🤖</span>
                    <span>الرد الآلي عبر الواتساب (الاستعلام برقم الصنف)</span>
                </h2>
                <p class="text-sm text-slate-500 mt-1">
                    يعتمد الرد الآلي في الواتساب على مطابقة رقم الصنف (Part Number) مباشرة مع مخزونك الخاص.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if(($botSettings['allow_group_replies'] ?? '0') === '1')
                    <span class="bg-blue-50 text-blue-800 border border-blue-200 text-xs font-semibold px-3 py-1.5 rounded-full inline-flex items-center gap-1.5">
                        <span>👥</span>
                        <span>رد القروبات: مفعل</span>
                    </span>
                @else
                    <span class="bg-emerald-50 text-emerald-800 border border-emerald-200 text-xs font-semibold px-3 py-1.5 rounded-full inline-flex items-center gap-1.5">
                        <span>🛡️</span>
                        <span>رد القروبات: معطل</span>
                    </span>
                @endif
            </div>
        </div>

        <form method="GET" action="{{ route('pages.second') }}" class="mt-4 flex flex-col sm:flex-row gap-2.5">
            <input
                type="text"
                name="message"
                value="{{ $customerMessage }}"
                placeholder="جرّب إدخال رقم الصنف لفحص التوفر (مثال: 04465-0K380 أو 90915-YZZD2)..."
                class="flex-1 border border-slate-300 rounded-lg px-4 py-2.5 text-sm font-mono focus:outline-none focus:border-emerald-600"
            >
            <button
                type="submit"
                class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2.5 rounded-lg text-sm font-semibold transition shadow-sm whitespace-nowrap">
                فحص رقم الصنف
            </button>
        </form>
    </div>

    <!-- نتائج الفحص التجريبي برقم الصنف -->
    @if($customerMessage)
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">
            <h3 class="font-bold text-slate-800">نتيجة البحث برقم الصنف:</h3>

            @forelse($matchedItems as$item)
                <div class="p-4 bg-emerald-50 border-emerald-200 border rounded-xl flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                    <div>
                        <p class="font-bold text-slate-800">
                            {{ $item->item_name }}
                            <span class="text-xs font-mono text-slate-500">({{ $item->item_number }})</span>
                        </p>
                        <p class="text-xs text-emerald-800 mt-1">
                            الكمية: {{ $item->quantity }} |
                            السعر: {{ number_format($item->price, 2) }} ر.س
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="text-xs px-3 py-1 rounded-full font-semibold bg-emerald-100 text-emerald-800">
                            ✅ متوفر بالمخزون
                        </span>
                    </div>
                </div>
            @empty
                <p class="text-sm text-rose-600 font-semibold py-2">
                    لم يُعثر على رقم الصنف المطلوب في مخزونك الخاص.
                </p>
            @endforelse
        </div>
    @endif

    <!-- دليل المتغيرات المتاحة في قوالب الرد -->
    <div class="bg-indigo-50/70 p-5 rounded-xl border border-indigo-200">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3">
            <h3 class="text-sm font-bold text-indigo-950 flex items-center gap-1.5">
                <span>📌</span>
                <span>المتغيرات المتاحة في قوالب الرد (اضغط على المتغير لنسخه):</span>
            </h3>
            <span class="text-xs text-indigo-700 font-medium">قابلة للاستخدام في قوالب الرد</span>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2 text-xs">
            <button type="button" onclick="copyTag('{item_number}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{item_number}</code>
                <span class="text-slate-600 text-[11px]">رقم الصنف</span>
            </button>
            <button type="button" onclick="copyTag('{item_name}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{item_name}</code>
                <span class="text-slate-600 text-[11px]">اسم الصنف</span>
            </button>
            <button type="button" onclick="copyTag('{price}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{price}</code>
                <span class="text-slate-600 text-[11px]">السعر</span>
            </button>
            <button type="button" onclick="copyTag('{quantity}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{quantity}</code>
                <span class="text-slate-600 text-[11px]">الكمية</span>
            </button>
            <button type="button" onclick="copyTag('{part}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{part}</code>
                <span class="text-slate-600 text-[11px]">الرقم المستعلم عنه</span>
            </button>
            <button type="button" onclick="copyTag('{client_name}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{client_name}</code>
                <span class="text-slate-600 text-[11px]">اسم المتجر</span>
            </button>
        </div>
    </div>

    <!-- إعدادات الواتساب والردود -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5 border-b border-slate-100 pb-4">
            <div>
                <h3 class="font-bold text-slate-800">إعدادات الاتصال وقوالب الرد الآلي</h3>
                <p class="text-xs text-slate-500 mt-1">الحقول الفارغة تعني عدم قيام البوت بإرسال رد في تلك الحالة.</p>
            </div>
            <div class="text-xs bg-slate-50 border border-slate-200 px-3 py-1.5 rounded-lg font-mono text-slate-700 self-start md:self-auto">
                Webhook: {{ url('/api/webhook/receiver') }}?user_id={{ auth()->id() }}
            </div>
        </div>

        @if(session('success'))
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-2.5 rounded-lg mb-4 text-sm font-semibold">
                ✅ {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="bg-rose-50 border border-rose-200 text-rose-800 px-4 py-2.5 rounded-lg mb-4 text-sm font-semibold">
                ❌ {{ session('error') }}
            </div>
        @endif

        <form action="{{ route('pages.second.settings') }}" method="POST" class="space-y-6">
            @csrf

            <!-- بيانات اتصال API -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 p-4 bg-slate-50 rounded-xl border border-slate-200">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">معرف الجلسة (Session ID)</label>
                    <input type="text" name="whatsapp_session_id" value="{{ $botSettings['whatsapp_session_id'] ?? '' }}" placeholder="أدخل session_id" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-600 font-mono">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">رابط الإرسال (API URL)</label>
                    <input type="text" name="whatsapp_api_url" value="{{ $botSettings['whatsapp_api_url'] ?? '' }}" placeholder="https://api.greatwhats.com/send" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-600 font-mono">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">مفتاح الربط (API Token)</label>
                    <input type="text" name="whatsapp_api_token" value="{{ $botSettings['whatsapp_api_token'] ?? '' }}" placeholder="Bearer Token" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-600 font-mono">
                </div>
            </div>

            <!-- خيار المجموعات -->
            <div class="p-4 bg-emerald-50/50 rounded-xl border border-emerald-200 flex items-center justify-between gap-4">
                <div>
                    <label for="allow_group_replies" class="font-bold text-slate-800 text-sm block cursor-pointer">
                        👥 تفعيل التفاعل في المجموعات (القروبات)
                    </label>
                    <p class="text-xs text-slate-600 mt-0.5">عند تعطيل الخيار، يتجاهل البوت أي رسائل ترد داخل المجموعات بشكل كامل.</p>
                </div>
                <div class="flex items-center">
                    <input type="hidden" name="allow_group_replies" value="0">
                    <input type="checkbox" id="allow_group_replies" name="allow_group_replies" value="1"
                           {{ ($botSettings['allow_group_replies'] ?? '0') === '1' ? 'checked' : '' }}
                           class="w-6 h-6 text-emerald-600 rounded border-slate-300 focus:ring-emerald-500 cursor-pointer">
                </div>
            </div>

            <!-- حد الاستعلامات اليومي -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 bg-amber-50/50 rounded-xl border border-amber-200">
                <div>
                    <label class="block text-xs font-bold text-slate-800 mb-1">حد الاستعلامات لكل رقم (0 = غير محدود)</label>
                    <input type="number" min="0" name="max_queries_per_phone" value="{{ $botSettings['max_queries_per_phone'] ?? '0' }}" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-600">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-800 mb-1">رسالة تجاوز الحد المسموح</label>
                    <textarea name="reply_quota_exceeded" rows="2" placeholder="اتركها فارغة للتوقف بصمت دون إرسال تنبيه" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-600">{{ $botSettings['reply_quota_exceeded'] ?? '' }}</textarea>
                </div>
            </div>

            <!-- قوالب الرد الأساسية برقم الصنف -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">1. رد توفر رقم الصنف ✅</label>
                    <textarea name="reply_available" rows="3" placeholder="متوفر {item_name} (رقم: {item_number}) بسعر {price} ر.س..." class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-600">{{ $botSettings['reply_available'] ?? '' }}</textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">2. رد عدم توفر رقم الصنف ❌</label>
                    <textarea name="reply_unavailable" rows="3" placeholder="عذراً، رقم الصنف المطلوب ({part}) غير متوفر حالياً..." class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-600">{{ $botSettings['reply_unavailable'] ?? '' }}</textarea>
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2.5 px-6 rounded-lg text-sm transition shadow-sm">
                    💾 حفظ الإعدادات وقوالب الرد
                </button>
            </div>
        </form>
    </div>

    <!-- قسم الاستثناء وسجل الاستعلامات -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- الأرقام المستثناة -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">
            <div>
                <h3 class="font-bold text-slate-800">⭐ الأرقام المستثناة (استعلام دائم)</h3>
                <p class="text-xs text-slate-500 mt-0.5">أرقام مميزة لا ينطبق عليها الحد الأقصى للاستعلامات.</p>
            </div>

            <form action="{{ route('pages.second.whitelist.add') }}" method="POST" class="flex flex-col sm:flex-row gap-2">
                @csrf
                <input type="text" name="phone" required placeholder="رقم الجوال (050...)" class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:border-emerald-600">
                <input type="text" name="label" placeholder="الاسم / ملاحظة" class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-emerald-600">
                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition whitespace-nowrap">
                    + إضافة
                </button>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-right border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-slate-600 text-xs">
                            <th class="p-2.5">الرقم</th>
                            <th class="p-2.5">الوصف</th>
                            <th class="p-2.5 text-center w-20">إجراء</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($whitelistPhones ?? [] as $wp)
                            <tr class="hover:bg-slate-50">
                                <td class="p-2.5 font-mono text-emerald-700 font-bold text-xs">{{ $wp->phone }}</td>
                                <td class="p-2.5 text-slate-600 text-xs">{{ $wp->label ?: 'مستثنى' }}</td>
                                <td class="p-2.5 text-center">
                                    <form action="{{ route('pages.second.whitelist.delete', $wp->id) }}" method="POST" class="inline-block m-0">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-rose-600 hover:text-rose-800 text-xs font-semibold">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="p-4 text-center text-slate-400 text-xs">لا توجد أرقام مستثناة.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- سجل استهلاك الاستعلامات -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-slate-800">📊 استهلاك استعلامات العملاء</h3>
                    <p class="text-xs text-slate-500 mt-0.5">عدد مرات الاستعلام المنفذة لكل رقم.</p>
                </div>

                <form action="{{ route('pages.second.quotas.reset') }}" method="POST" class="m-0">
                    @csrf
                    <button type="submit" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-1.5 rounded-lg text-xs font-semibold border border-slate-200 transition">
                        🔄 تصفير الكل
                    </button>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-right border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-slate-600 text-xs">
                            <th class="p-2.5">رقم العميل</th>
                            <th class="p-2.5">الاستعلامات</th>
                            <th class="p-2.5 text-center w-24">إجراء</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($customerQuotas ?? [] as $cq)
                            <tr class="hover:bg-slate-50">
                                <td class="p-2.5 font-mono text-xs">{{ $cq->phone }}</td>
                                <td class="p-2.5">
                                    <span class="bg-indigo-50 text-indigo-700 border border-indigo-200 px-2.5 py-0.5 rounded-full text-xs font-semibold">
                                        {{ $cq->queries_count }}
                                    </span>
                                </td>
                                <td class="p-2.5 text-center">
                                    <form action="{{ route('pages.second.quotas.reset') }}" method="POST" class="inline-block m-0">
                                        @csrf
                                        <input type="hidden" name="quota_id" value="{{ $cq->id }}" />
                                        <button type="submit" class="text-indigo-600 hover:text-indigo-800 text-xs font-semibold">تصفير</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="p-4 text-center text-slate-400 text-xs">لا توجد استعلامات مسجلة.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
function copyTag(tag) {
    navigator.clipboard.writeText(tag);
}
</script>
@endsection

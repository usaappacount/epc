@extends('layouts.dashboard')

@section('title', 'مساعد الذكاء الاصطناعي والواتساب')

@section('content')
<div class="space-y-6">

    <!-- قسم تجربة التعرف على الصنف من مخزون المستخدم الحالي -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <h2 class="text-2xl font-bold text-slate-800">
            🤖 مساعد الذكاء الاصطناعي والواتساب
        </h2>

        <p class="text-sm text-slate-500 mt-1">
            جرب كتابة رسالة كما يرسلها الزبون (مثال: بواجي كامري 25) لاختبار استخراج (ترجمة الصنف + نوع السيارة + سنة الصنع) من مخزونك الخاص.
        </p>

        <form method="GET" action="{{ route('pages.second') }}" class="mt-4 flex gap-3">
            <input
                type="text"
                name="message"
                value="{{ $customerMessage }}"
                placeholder="اسأل عن أي قطعة في مخزونك (مثال: بواجي كامري 25 أو رقم الصنف)..."
                class="flex-1 border border-slate-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-emerald-500"
            >

            <button
                type="submit"
                class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2 rounded-lg text-sm font-semibold">
                تجربة التعرف على الصنف
            </button>
        </form>
    </div>

    @if($customerMessage)
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">

            <h3 class="font-bold text-slate-700">
                نتيجة تعرف النظام على رسالة الزبون من مخزونك:
            </h3>

            @forelse($matchedItems as $item)
                <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-lg flex justify-between items-center gap-4">
                    <div>
                        <p class="font-bold text-slate-800">
                            {{ $item->item_name }}
                            <span class="text-xs font-mono text-slate-500">
                                ({{ $item->item_number }})
                            </span>
                        </p>

                        <p class="text-xs text-emerald-700 mt-1">
                            السيارة: {{ $item->detected_car_name ?? 'غير محدد' }}
                            |
                            الموديل: {{ $item->detected_years ?? 'غير محدد' }}
                            |
                            الكمية: {{ $item->quantity }}
                            |
                            السعر: {{ number_format($item->price, 2) }} ر.س
                        </p>
                    </div>

                    <span class="text-xs bg-emerald-600 text-white px-3 py-1 rounded-full">
                        تم التعرف بنجاح
                    </span>
                </div>
            @empty
                <p class="text-sm text-rose-600">
                    لم يتم العثور على صنف مطابق لهذه الكلمات في مخزونك الخاص.
                </p>
            @endforelse

        </div>
    @endif

    <!-- قائمة الدوال الموحدة التي تعمل في جميع الخانات -->
    <div class="bg-indigo-50 p-5 rounded-xl border border-indigo-200">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-indigo-900">
                📌 قائمة الدوال الذكية الموحدة (اضغط على أي دالة لنسخها واستخدامها في أي خانة بالأسفل):
            </h3>
            <span class="text-xs text-indigo-700 font-semibold">تعمل في جميع الخانات بدون استثناء</span>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2.5 text-xs">
            <button type="button" onclick="copyTag('{item_name}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{item_name}</code>
                <span class="text-slate-600">اسم الصنف (ترجمة العميل)</span>
            </button>

            <button type="button" onclick="copyTag('{item_number}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{item_number}</code>
                <span class="text-slate-600">رقم الصنف</span>
            </button>

            <button type="button" onclick="copyTag('{price}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{price}</code>
                <span class="text-slate-600">سعر الصنف</span>
            </button>

            <button type="button" onclick="copyTag('{quantity}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{quantity}</code>
                <span class="text-slate-600">الكمية المتوفرة</span>
            </button>

            <button type="button" onclick="copyTag('{car}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{car}</code>
                <span class="text-slate-600">نوع / اسم السيارة</span>
            </button>

            <button type="button" onclick="copyTag('{year}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{year}</code>
                <span class="text-slate-600">سنة الصنع (الموديل)</span>
            </button>

            <button type="button" onclick="copyTag('{part}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{part}</code>
                <span class="text-slate-600">اسم القطعة بطلب الزبون</span>
            </button>

            <button type="button" onclick="copyTag('{category}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{category}</code>
                <span class="text-slate-600">فئة / شركة السيارة</span>
            </button>

            <button type="button" onclick="copyTag('{client_name}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{client_name}</code>
                <span class="text-slate-600">اسم المتجر / العميل</span>
            </button>

            <button type="button" onclick="copyTag('{items}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{items}</code>
                <span class="text-slate-600">قائمة الأصناف المطابقة</span>
            </button>
        </div>
    </div>

    <!-- إعدادات الربط وقوالب الرد الآلي الخاصة بحساب المستخدم المسجل دخوله -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 mb-4">
            <div>
                <h3 class="text-lg font-bold text-slate-800">
                    💬 إعدادات ربط الواتساب وقوالب الرد الآلي الخاصة بحسابك ({{ auth()->user()->name }})
                </h3>
                <p class="text-xs text-amber-700 font-semibold mt-1">
                    💡 ملاحظة هامة: إذا تركت أي خانة من خانات الردود الأربعة فارغة تماماً، فلن يقوم البوت بالرد في تلك الحالة نهائياً.
                </p>
            </div>

            <div class="text-xs bg-slate-100 px-3 py-2 rounded-lg font-mono text-slate-700">
                Webhook URL: {{ url('/api/webhook/receiver') }}?user_id={{ auth()->id() }}
            </div>
        </div>

        @if(session('success'))
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg mb-4 text-sm font-semibold">
                {{ session('success') }}
            </div>
        @endif

        <form action="{{ route('pages.second.settings') }}" method="POST" class="space-y-5">
            @csrf

            <!-- إعدادات الربط الخاصة بهذا العميل (Session ID + API URL + Token) -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 p-4 bg-slate-50 rounded-xl border border-slate-200">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">معرف الجلسة (session_id) :</label>
                    <input type="text" name="whatsapp_session_id" value="{{ $botSettings['whatsapp_session_id'] ?? '' }}" placeholder="أدخل session_id الخاص بواتسابك" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-500 font-mono" />
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">رابط إرسال رسائل الواتساب (API Send URL) :</label>
                    <input type="text" name="whatsapp_api_url" value="{{ $botSettings['whatsapp_api_url'] ?? '' }}" placeholder="مثال: https://api.greatwhats.com/send" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-500 font-mono" />
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">مفتاح الربط (API Token / Key) :</label>
                    <input type="text" name="whatsapp_api_token" value="{{ $botSettings['whatsapp_api_token'] ?? '' }}" placeholder="أدخل التوكن الخاص بحسابك" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-500 font-mono" />
                </div>
            </div>

            <!-- خانات الردود الأربعة -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- خانة الرد في حال التوفر -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">1. رد البوت في حال توفر القطعة ✅ (اتركها فارغة لعدم الرد)</label>
                    <textarea name="reply_available" rows="4" placeholder="إذا تركت هذه الخانة فارغة لن يرد البوت عند توفر القطعة" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-500">{{ $botSettings['reply_available'] ?? '' }}</textarea>
                </div>

                <!-- خانة الرد في حال عدم التوفر -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">2. رد البوت في حال عدم توفر القطعة ❌ (اتركها فارغة لعدم الرد)</label>
                    <textarea name="reply_unavailable" rows="4" placeholder="إذا تركت هذه الخانة فارغة لن يرد البوت عند عدم توفر القطعة" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-500">{{ $botSettings['reply_unavailable'] ?? '' }}</textarea>
                </div>

                <!-- خانة السؤال عند عدم معرفة نوع السيارة -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">3. رد البوت عند عدم التعرف على نوع/اسم السيارة 🚗 (اتركها فارغة لعدم الرد)</label>
                    <textarea name="reply_ask_car" rows="2" placeholder="إذا تركت هذه الخانة فارغة لن يسأل البوت عن نوع السيارة" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-500">{{ $botSettings['reply_ask_car'] ?? '' }}</textarea>
                </div>

                <!-- خانة السؤال عند عدم معرفة سنة الصنع -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">4. رد البوت عند عدم التعرف على سنة الصنع 📅 (اتركها فارغة لعدم الرد)</label>
                    <textarea name="reply_ask_year" rows="2" placeholder="إذا تركت هذه الخانة فارغة لن يسأل البوت عن سنة الصنع" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-500">{{ $botSettings['reply_ask_year'] ?? '' }}</textarea>
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2.5 px-6 rounded-lg text-sm transition">
                    💾 حفظ إعدادات الرد الآلي والربط لحسابك
                </button>
            </div>
        </form>
    </div>

    <!-- جدول ذاكرة المحادثات النشطة الخاصة بهذا الحساب -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <h3 class="text-base font-bold text-slate-800 mb-3">
            🧠 ذاكرة المحادثات الجارية لحسابك (حفظ وترتيب كلام الزبون حتى يكتمل: القطعة + السيارة + سنة الصنع)
        </h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-right border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-700">
                        <th class="p-3">رقم الزبون</th>
                        <th class="p-3">السيارة المكتشفة</th>
                        <th class="p-3">سنة الصنع</th>
                        <th class="p-3">القطعة المطلوبة</th>
                        <th class="p-3">إجمالي المحادثة المحفوظة</th>
                        <th class="p-3">إجراء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse($activeSessions ?? [] as $sess)
                        <tr class="hover:bg-slate-50">
                            <td class="p-3 font-mono">{{ $sess->phone }}</td>
                            <td class="p-3">
                                @if($sess->detected_car)
                                    <span class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded text-xs font-bold">{{ $sess->detected_car }}</span>
                                @else
                                    <span class="bg-amber-100 text-amber-800 px-2 py-0.5 rounded text-xs">بانتظار نوع السيارة</span>
                                @endif
                            </td>
                            <td class="p-3">
                                @if($sess->detected_year)
                                    <span class="bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded text-xs font-bold">{{ $sess->detected_year }}</span>
                                @else
                                    <span class="bg-amber-100 text-amber-800 px-2 py-0.5 rounded text-xs">بانتظار الموديل</span>
                                @endif
                            </td>
                            <td class="p-3 font-semibold">{{ $sess->part_query }}</td>
                            <td class="p-3 text-slate-500 text-xs">{{ $sess->full_conversation }}</td>
                            <td class="p-3">
                                <form action="{{ route('pages.second.session.delete', $sess->id) }}" method="POST">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-rose-600 hover:underline text-xs font-bold">مسح الذاكرة</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-4 text-center text-slate-400">لا توجد محادثات معلقة في الذاكرة حالياً.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
function copyTag(tag) {
    navigator.clipboard.writeText(tag);
}
</script>
@endsection

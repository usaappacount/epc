@extends('layouts.dashboard')

@section('title', 'مساعد الذكاء الاصطناعي والواتساب')

@section('content')
<div class="space-y-6">

    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <h2 class="text-2xl font-bold text-slate-800">
            🤖 مساعد الذكاء الاصطناعي والواتساب
        </h2>

        <p class="text-sm text-slate-500 mt-1">
            هذه الصفحة مهيأة لربط WhatsApp API مع المخزون.
        </p>

        <form method="GET" action="{{ route('pages.second') }}" class="mt-4 flex gap-3">
            <input
                type="text"
                name="message"
                value="{{ $customerMessage }}"
                placeholder="اسال عن اي قطعة في مخزونك سواء برقم الصنف او اسم الصنف "
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
                نتيجة تعرف النظام على رسالة الزبون:
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
                            الكمية المتوفرة: {{ $item->quantity }}
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
                    لم يتم العثور على صنف مطابق لهذه الكلمات في المخزون.
                </p>
            @endforelse

        </div>
    @endif

    <!-- إعدادات قوالب الرد الآلي وذاكرة المحادثة للواتساب -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <h3 class="text-lg font-bold text-slate-800 mb-4">
            💬 إعدادات الرد الآلي الذكي والذاكرة (WhatsApp Webhook)
        </h3>

        @if(session('success'))
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg mb-4 text-sm font-semibold">
                {{ session('success') }}
            </div>
        @endif

        <form action="{{ route('pages.second.settings') }}" method="POST" class="space-y-4">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- خانة الرد في حال التوفر -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">رد البوت في حال توفر القطعة ✅</label>
                    <textarea name="reply_available" rows="3" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-500">{{ $botSettings['reply_available'] ?? "القطعة متوفرة لدينا ✅\nتفاصيل القطعة:\n{items}" }}</textarea>
                    <span class="text-xs text-slate-500">المتغيرات المتاحة: <code>{items}</code> لقائمة القطع والأسعار، <code>{car}</code> للسيارة، <code>{year}</code> للموديل</span>
                </div>

                <!-- خانة الرد في حال عدم التوفر -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">رد البوت في حال عدم توفر القطعة ❌</label>
                    <textarea name="reply_unavailable" rows="3" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-500">{{ $botSettings['reply_unavailable'] ?? "نعتذر منك، القطعة المطلوبة لسيارة {car} موديل {year} غير متوفرة حالياً في المخزون ❌" }}</textarea>
                    <span class="text-xs text-slate-500">المتغيرات المتاحة: <code>{car}</code> للسيارة، <code>{year}</code> للموديل، <code>{part}</code> للقطعة</span>
                </div>

                <!-- خانة السؤال عند عدم معرفة نوع السيارة -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">رد البوت عند عدم التعرف على نوع/اسم السيارة 🚗</label>
                    <textarea name="reply_ask_car" rows="2" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-500">{{ $botSettings['reply_ask_car'] ?? 'حياك الله، فضلاً ما هو نوع أو اسم السيارة؟' }}</textarea>
                </div>

                <!-- خانة السؤال عند عدم معرفة سنة الصنع -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">رد البوت عند عدم التعرف على سنة الصنع 📅</label>
                    <textarea name="reply_ask_year" rows="2" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-500">{{ $botSettings['reply_ask_year'] ?? 'فضلاً كم موديل (سنة صنع) السيارة {car}؟' }}</textarea>
                </div>
            </div>

            <!-- إعدادات رابط الإرسال للواتساب -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 border-t border-slate-100">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">رابط إرسال رسائل الواتساب (API Send URL)</label>
                    <input type="text" name="whatsapp_api_url" value="{{ $botSettings['whatsapp_api_url'] ?? '' }}" placeholder="مثال: https://api.greatwhats.com/send" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-500" />
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">مفتاح الربط (API Token / Key)</label>
                    <input type="text" name="whatsapp_api_token" value="{{ $botSettings['whatsapp_api_token'] ?? '' }}" placeholder="أدخل التوكن هنا" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-500" />
                </div>
            </div>

            <div class="pt-2">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2.5 px-6 rounded-lg text-sm transition">
                    حفظ إعدادات الرد الآلي
                </button>
            </div>
        </form>
    </div>

    <!-- جدول ذاكرة المحادثات النشطة -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <h3 class="text-base font-bold text-slate-800 mb-3">
            🧠 ذاكرة المحادثات الجارية (حفظ وترتيب كلام العميل حتى يكتمل المطلوب)
        </h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-right border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-700">
                        <th class="p-3">رقم العميل</th>
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
@endsection

@extends('layouts.dashboard')

@section('title', 'مساعد الذكاء الاصطناعي والرد الآلي')

@section('content')
<div class="space-y-6">

    <!-- فحص التعرف المباشر على الأصناف -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                    <span>🤖</span>
                    <span>مساعد الذكاء الاصطناعي والرد الآلي</span>
                </h2>
                <p class="text-sm text-slate-500 mt-1">
                    يعتمد الرد على مطابقة الكلمات المفتاحية مع السيارة وسنة الصنع ومخزونك الخاص.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @if(($botSettings['enable_confidence_system'] ?? '1') === '1')
                    <span class="bg-indigo-50 text-indigo-800 border border-indigo-200 text-xs font-semibold px-3 py-1.5 rounded-full inline-flex items-center gap-1.5">
                        <span>🎯</span>
                        <span>حساسية التطابق: مفعلة</span>
                    </span>
                @else
                    <span class="bg-slate-100 text-slate-700 border border-slate-200 text-xs font-semibold px-3 py-1.5 rounded-full inline-flex items-center gap-1.5">
                        <span>⚡</span>
                        <span>حساسية التطابق: موقفة (رد مباشر)</span>
                    </span>
                @endif

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
                placeholder="جرّب رسالة العميل (مثال: فلتر زيت هايلكس 25 أو صدام امامي كامري 25)..."
                class="flex-1 border border-slate-300 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:border-emerald-600"
            >
            <button
                type="submit"
                class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2.5 rounded-lg text-sm font-semibold transition shadow-sm whitespace-nowrap">
                فحص المطابقة
            </button>
        </form>
    </div>

    <!-- نتائج الفحص التجريبي -->
    @if($customerMessage)
        @php
            $confidenceEnabled = ($botSettings['enable_confidence_system'] ?? '1') === '1';
            $directMin  = $confidenceEnabled ? (int) ($botSettings['confidence_direct_threshold'] ?? 80) : 1;
            $suggestMin = $confidenceEnabled ? (int) ($botSettings['confidence_suggest_threshold'] ?? 40) : 1;
        @endphp
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">
            <h3 class="font-bold text-slate-800">نتيجة الفحص ونسبة التطابق:</h3>

            @forelse($matchedItems as $item)
                @php
                    $score = (int) ($item->confidence_score ?? 100);
                    $isDirect = !$confidenceEnabled || $score >= $directMin;
                @endphp
                <div class="p-4 {{ $isDirect ? 'bg-emerald-50 border-emerald-200' : 'bg-amber-50 border-amber-200' }} border rounded-xl flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                    <div>
                        <p class="font-bold text-slate-800">
                            {{ $item->item_name }}
                            <span class="text-xs font-mono text-slate-500">({{ $item->item_number }})</span>
                        </p>
                        <p class="text-xs {{ $isDirect ? 'text-emerald-800' : 'text-amber-900' }} mt-1">
                            السيارة: {{ $item->detected_car_name ?? 'غير محدد' }} |
                            الموديل: {{ $item->detected_years ?? 'غير محدد' }} |
                            الكمية: {{ $item->quantity }} |
                            السعر: {{ number_format($item->price, 2) }} ر.س
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="text-xs font-bold px-3 py-1 rounded-full {{ $isDirect ? 'bg-emerald-600 text-white' : 'bg-amber-600 text-white' }}">
                            {{ $score }}% مطابقة
                        </span>
                        <span class="text-xs px-2.5 py-1 rounded-full font-semibold {{ $isDirect ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }}">
                            {{ $isDirect ? '✅ إجابة مباشرة بالتوفر' : '🤔 سؤال: هل تقصد؟' }}
                        </span>
                    </div>
                </div>
            @empty
                <p class="text-sm text-rose-600 font-semibold py-2">
                    لم يُعثر على صنف مطابق في الكلمات المفتاحية لمخزونك الخاص.
                </p>
            @endforelse
        </div>
    @endif

    <!-- دليل المتغيرات الذكية -->
    <div class="bg-indigo-50/70 p-5 rounded-xl border border-indigo-200">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3">
            <h3 class="text-sm font-bold text-indigo-950 flex items-center gap-1.5">
                <span>📌</span>
                <span>المتغيرات المتاحة في قوالب الرد (اضغط على المتغير لنسخه):</span>
            </h3>
            <span class="text-xs text-indigo-700 font-medium">قابلة للاستخدام في جميع القوالب</span>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-2 text-xs">
            <button type="button" onclick="copyTag('{item_name}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{item_name}</code>
                <span class="text-slate-600 text-[11px]">اسم الصنف</span>
            </button>
            <button type="button" onclick="copyTag('{item_number}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{item_number}</code>
                <span class="text-slate-600 text-[11px]">رقم الصنف</span>
            </button>
            <button type="button" onclick="copyTag('{price}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{price}</code>
                <span class="text-slate-600 text-[11px]">السعر</span>
            </button>
            <button type="button" onclick="copyTag('{quantity}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{quantity}</code>
                <span class="text-slate-600 text-[11px]">الكمية</span>
            </button>
            <button type="button" onclick="copyTag('{car}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{car}</code>
                <span class="text-slate-600 text-[11px]">اسم السيارة</span>
            </button>
            <button type="button" onclick="copyTag('{year}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{year}</code>
                <span class="text-slate-600 text-[11px]">سنة الصنع</span>
            </button>
            <button type="button" onclick="copyTag('{part}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{part}</code>
                <span class="text-slate-600 text-[11px]">القطعة المطلوبة</span>
            </button>
            <button type="button" onclick="copyTag('{category}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{category}</code>
                <span class="text-slate-600 text-[11px]">ماركة السيارة</span>
            </button>
            <button type="button" onclick="copyTag('{client_name}')" class="bg-white hover:bg-indigo-100 border border-indigo-200 rounded-lg p-2 text-right transition">
                <code class="font-bold text-indigo-700 block">{client_name}</code>
                <span class="text-slate-600 text-[11px]">اسم المتجر</span>
            </button>
            <button type="button" onclick="copyTag('{suggested_item}')" class="bg-amber-50 hover:bg-amber-100 border border-amber-300 rounded-lg p-2 text-right transition">
                <code class="font-bold text-amber-800 block">{suggested_item}</code>
                <span class="text-slate-700 text-[11px]">الصنف المقترح</span>
            </button>
            <button type="button" onclick="copyTag('{suggested_list}')" class="bg-amber-50 hover:bg-amber-100 border border-amber-300 rounded-lg p-2 text-right transition">
                <code class="font-bold text-amber-800 block">{suggested_list}</code>
                <span class="text-slate-700 text-[11px]">قائمة المقترحات</span>
            </button>
            <button type="button" onclick="copyTag('{confidence}')" class="bg-amber-50 hover:bg-amber-100 border border-amber-300 rounded-lg p-2 text-right transition">
                <code class="font-bold text-amber-800 block">{confidence}</code>
                <span class="text-slate-700 text-[11px]">نسبة التطابق (%)</span>
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

            <!-- إعدادات حساسية التطابق والشك مع زر التفعيل والإيقاف -->
            <div class="p-5 bg-indigo-50/40 rounded-xl border border-indigo-200 space-y-4">
                <div class="flex items-center justify-between gap-4 border-b border-indigo-100 pb-3">
                    <div>
                        <label for="enable_confidence_system" class="text-sm font-bold text-indigo-950 block cursor-pointer">
                            🎯 تفعيل نظام حساسية التطابق وسؤال "هل تقصد؟"
                        </label>
                        <p class="text-xs text-slate-600 mt-0.5">
                            عند <strong>التفعيل</strong> يعتمد البوت على النسب المئوية ويسأل "هل تقصد؟" عند الشك، وعند <strong>الإيقاف</strong> يرد مباشرة بالتوفر والسعر للأصناف المطابقة.
                        </p>
                    </div>
                    <div class="flex items-center">
                        <input type="hidden" name="enable_confidence_system" value="0">
                        <input
                            type="checkbox"
                            id="enable_confidence_system"
                            name="enable_confidence_system"
                            value="1"
                            onchange="document.getElementById('confidenceFieldsBox').style.opacity = this.checked ? '1' : '0.45';"
                            {{ ($botSettings['enable_confidence_system'] ?? '1') === '1' ? 'checked' : '' }}
                            class="w-6 h-6 text-indigo-600 rounded border-slate-300 focus:ring-indigo-500 cursor-pointer"
                        >
                    </div>
                </div>

                <div id="confidenceFieldsBox" class="space-y-4 transition-opacity" style="opacity: {{ ($botSettings['enable_confidence_system'] ?? '1') === '1' ? '1' : '0.45' }};">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">حد الإجابة المباشرة بالتوفر والسعر (%)</label>
                            <input type="number" min="1" max="100" name="confidence_direct_threshold"
                                   value="{{ $botSettings['confidence_direct_threshold'] ?? '80' }}"
                                   class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-indigo-600 font-bold text-emerald-700">
                            <span class="text-[11px] text-slate-500 mt-1 block">يرد البوت فوراً عند بلوغ هذا الحد (الافتراضي: 80%).</span>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الحد الأدنى لسؤال "هل تقصد؟" (%)</label>
                            <input type="number" min="1" max="100" name="confidence_suggest_threshold"
                                   value="{{ $botSettings['confidence_suggest_threshold'] ?? '40' }}"
                                   class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-indigo-600 font-bold text-amber-700">
                            <span class="text-[11px] text-slate-500 mt-1 block">يطرح الاقتراح إذا كانت الدقة بين هذا الحد والحد المباشر.</span>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-slate-700 mb-1">صيغة رسالة الاقتراح (هل تقصد؟)</label>
                        <textarea name="reply_did_you_mean" rows="2"
                                  placeholder="هل تقصد ({suggested_item}) لسيارة {car} موديل {year}؟ رد بـ (نعم) للتأكيد."
                                  class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-indigo-600">{{ $botSettings['reply_did_you_mean'] ?? '' }}</textarea>
                    </div>
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

            <!-- قوالب الرد الأساسية -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">1. رد توفر الصنف ✅</label>
                    <textarea name="reply_available" rows="3" placeholder="متوفر {item_name} بسعر {price} ر.س..." class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-600">{{ $botSettings['reply_available'] ?? '' }}</textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">2. رد عدم التوفر ❌</label>
                    <textarea name="reply_unavailable" rows="3" placeholder="عذراً، الصنف المطلوب غير متوفر حالياً..." class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-600">{{ $botSettings['reply_unavailable'] ?? '' }}</textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">3. السؤال عن نوع السيارة 🚗</label>
                    <textarea name="reply_ask_car" rows="2" placeholder="فضلاً، ما هو نوع السيارة؟" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-600">{{ $botSettings['reply_ask_car'] ?? '' }}</textarea>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">4. السؤال عن سنة الصنع 📅</label>
                    <textarea name="reply_ask_year" rows="2" placeholder="فضلاً، ما هي سنة الصنع (الموديل)؟" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-600">{{ $botSettings['reply_ask_year'] ?? '' }}</textarea>
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

    <!-- جلسات المحادثات الجارية -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-3">
        <h3 class="font-bold text-slate-800">
            🧠 ذاكرة المحادثات الجارية (الجلسات المعلقة)
        </h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-right border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-600 text-xs">
                        <th class="p-3">رقم العميل</th>
                        <th class="p-3">السيارة</th>
                        <th class="p-3">الموديل</th>
                        <th class="p-3">الصنف المطلوب</th>
                        <th class="p-3">الحالة</th>
                        <th class="p-3">نص المحادثة</th>
                        <th class="p-3 text-center w-24">إجراء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($activeSessions ?? [] as $sess)
                        <tr class="hover:bg-slate-50">
                            <td class="p-3 font-mono text-xs">{{ $sess->phone }}</td>
                            <td class="p-3 text-xs">
                                @if($sess->detected_car)
                                    <span class="bg-emerald-50 text-emerald-800 border border-emerald-200 px-2 py-0.5 rounded font-semibold">{{ $sess->detected_car }}</span>
                                @else
                                    <span class="text-amber-700 bg-amber-50 px-2 py-0.5 rounded text-[11px]">بانتظار السيارة</span>
                                @endif
                            </td>
                            <td class="p-3 text-xs">
                                @if($sess->detected_year)
                                    <span class="bg-emerald-50 text-emerald-800 border border-emerald-200 px-2 py-0.5 rounded font-semibold">{{ $sess->detected_year }}</span>
                                @else
                                    <span class="text-amber-700 bg-amber-50 px-2 py-0.5 rounded text-[11px]">بانتظار الموديل</span>
                                @endif
                            </td>
                            <td class="p-3 font-semibold text-xs">{{ $sess->part_query }}</td>
                            <td class="p-3 text-xs">
                                @if(!empty($sess->pending_suggestion_ids))
                                    <span class="bg-amber-100 text-amber-900 px-2 py-0.5 rounded text-[11px] font-semibold">بانتظار التأكيد</span>
                                @else
                                    <span class="bg-blue-50 text-blue-800 px-2 py-0.5 rounded text-[11px] font-semibold">استكمال بيانات</span>
                                @endif
                            </td>
                            <td class="p-3 text-slate-500 text-xs max-w-xs truncate">{{ $sess->full_conversation }}</td>
                            <td class="p-3 text-center">
                                <form action="{{ route('pages.second.session.delete', $sess->id) }}" method="POST" class="inline-block m-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-rose-600 hover:text-rose-800 text-xs font-semibold">مسح</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-4 text-center text-slate-400 text-xs">لا توجد محادثات جارية في الذاكرة حالياً.</td>
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
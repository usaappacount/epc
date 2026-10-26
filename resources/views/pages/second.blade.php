@extends('layouts.dashboard')

@section('title', 'مساعد الذكاء الاصطناعي والواتساب')

@section('content')
<div class="space-y-6">

    <!-- قسم تجربة التعرف على الصنف من مخزون المستخدم الحالي -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
            <div>
                <h2 class="text-2xl font-bold text-slate-800">
                    🤖 مساعد الذكاء الاصطناعي والواتساب
                </h2>
                <p class="text-sm text-slate-500 mt-1">
                    يعتمد البوت حصرياً على المصادر الثلاثة (الكلمات المفتاحية لأصناف مخزونك الخاص + اسم السيارة + سنة الصنع)، مع نظام نسبة التطابق الذكي واقتراح "هل تقصد؟".
                </p>
            </div>
            @if(($botSettings['allow_group_replies'] ?? '0') === '1')
                <span class="bg-blue-100 text-blue-800 text-xs font-bold px-3 py-1.5 rounded-full">
                    👥 قراءة والرد في القروبات: مفعل
                </span>
            @else
                <span class="bg-emerald-100 text-emerald-800 text-xs font-bold px-3 py-1.5 rounded-full">
                    🛡️ مانع القروبات والدردشة العشوائية: مفعل
                </span>
            @endif
        </div>

        <form method="GET" action="{{ route('pages.second') }}" class="mt-4 flex gap-3">
            <input
                type="text"
                name="message"
                value="{{ $customerMessage }}"
                placeholder="جرب البحث برقم الصنف أو اسم القطعة (مثال: فلتر زيت كامري 25 أو صدام امامي كامري 25)..."
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
        @php
            $directMin  = (int) ($botSettings['confidence_direct_threshold'] ?? 80);
            $suggestMin = (int) ($botSettings['confidence_suggest_threshold'] ?? 40);
        @endphp
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">

            <h3 class="font-bold text-slate-700">
                نتيجة تعرف النظام ونسبة التطابق الذكي من مخزونك:
            </h3>

            @forelse($matchedItems as $item)
                @php
                    $score = (int) ($item->confidence_score ?? 100);
                    $isDirect = $score >= $directMin;
                @endphp
                <div class="p-4 {{ $isDirect ? 'bg-emerald-50 border-emerald-200' : 'bg-amber-50 border-amber-200' }} border rounded-lg flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <p class="font-bold text-slate-800">
                            {{ $item->item_name }}
                            <span class="text-xs font-mono text-slate-500">
                                ({{ $item->item_number }})
                            </span>
                        </p>

                        <p class="text-xs {{ $isDirect ? 'text-emerald-700' : 'text-amber-800' }} mt-1">
                            السيارة: {{ $item->detected_car_name ?? 'غير محدد' }}
                            |
                            الموديل: {{ $item->detected_years ?? 'غير محدد' }}
                            |
                            الكمية: {{ $item->quantity }}
                            |
                            السعر: {{ number_format($item->price, 2) }} ر.س
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="text-xs font-bold px-3 py-1 rounded-full {{ $isDirect ? 'bg-emerald-600 text-white' : 'bg-amber-500 text-white' }}">
                            نسبة التطابق: {{ $score }}%
                        </span>
                        <span class="text-xs px-3 py-1 rounded-full font-semibold {{ $isDirect ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }}">
                            {{ $isDirect ? '✅ إجابة مباشرة بالتوفر' : '🤔 يرد بـ: هل تقصد؟' }}
                        </span>
                    </div>
                </div>
            @empty
                <p class="text-sm text-rose-600 font-semibold">
                    لم يتم العثور على صنف مطابق لهذه الكلمات في الكلمات المفتاحية لمخزونك الخاص.
                </p>
            @endforelse

        </div>
    @endif

    <!-- قائمة الدوال الموحدة التي تعمل في جميع الخانات -->
    <div class="bg-indigo-50 p-5 rounded-xl border border-indigo-200">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-indigo-900">
                📌 قائمة المتغيرات والدوال الذكية الموحدة (اضغط على أي دالة لنسخها واستخدامها في أي خانة بالأسفل):
            </h3>
            <span class="text-xs text-indigo-700 font-semibold">تعمل في جميع الخانات بما فيها خانة "هل تقصد؟"</span>
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
                <span class="text-slate-600">القطعة المطلوبة</span>
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

            <button type="button" onclick="copyTag('{suggested_item}')" class="bg-amber-50 hover:bg-amber-100 border border-amber-300 rounded-lg p-2 text-right transition">
                <code class="font-bold text-amber-800 block">{suggested_item}</code>
                <span class="text-slate-700">اسم الصنف المقترح (هل تقصد)</span>
            </button>

            <button type="button" onclick="copyTag('{suggested_list}')" class="bg-amber-50 hover:bg-amber-100 border border-amber-300 rounded-lg p-2 text-right transition">
                <code class="font-bold text-amber-800 block">{suggested_list}</code>
                <span class="text-slate-700">قائمة الأصناف المقترحة بالأسعار</span>
            </button>

            <button type="button" onclick="copyTag('{confidence}')" class="bg-amber-50 hover:bg-amber-100 border border-amber-300 rounded-lg p-2 text-right transition">
                <code class="font-bold text-amber-800 block">{confidence}</code>
                <span class="text-slate-700">نسبة التطابق المئوية (%)</span>
            </button>
        </div>
    </div>

    <!-- إعدادات الربط وقوالب الرد الآلي وحد الاستعلامات -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 mb-4">
            <div>
                <h3 class="text-lg font-bold text-slate-800">
                    💬 إعدادات ربط الواتساب ونسب الذكاء وقوالب الرد الآلي ({{ auth()->user()->name }})
                </h3>
                <p class="text-xs text-amber-700 font-semibold mt-1">
                    💡 ملاحظة: إذا تركت أي خانة فارغة تماماً فلن يقوم البوت بالرد في تلك الحالة. وعند توفر عدة أصناف في نفس الرسالة يكرر البوت قالب التوفر لكل صنف، بينما يرسل قالب عدم التوفر مرة واحدة فقط.
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

        @if(session('error'))
            <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-lg mb-4 text-sm font-semibold">
                {{ session('error') }}
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

            <!-- خيار تفعيل أو تعطيل قراءة والرد على رسائل المجموعات (القروبات) -->
            <div class="p-4 bg-emerald-50/60 rounded-xl border border-emerald-200 flex items-center justify-between gap-4">
                <div>
                    <label for="allow_group_replies" class="font-bold text-slate-800 text-sm block cursor-pointer">
                        👥 تفعيل قراءة والرد على الرسائل في المجموعات (القروبات)
                    </label>
                    <p class="text-xs text-slate-600 mt-1">
                        في حال <strong>عدم التفعيل</strong>، سيتجاهل البوت رسائل القروبات تماماً ولن يقرأها ولن يرد عليها لا في القروب ولا في الخاص للناس اللي تكتب في القروبات. وفي حال <strong>التفعيل</strong> سيقرأ الرسائل ويرد داخل القروب.
                    </p>
                </div>
                <div class="flex items-center">
                    <input type="hidden" name="allow_group_replies" value="0">
                    <input type="checkbox" id="allow_group_replies" name="allow_group_replies" value="1"
                           {{ ($botSettings['allow_group_replies'] ?? '0') === '1' ? 'checked' : '' }}
                           class="w-6 h-6 text-emerald-600 rounded border-slate-300 focus:ring-emerald-500 cursor-pointer">
                </div>
            </div>

            <!-- إعدادات نظام الذكاء ونسب التطابق المئوية (Confidence Thresholds) + خانة "هل تقصد؟" -->
            <div class="p-5 bg-indigo-50/50 rounded-xl border border-indigo-200 space-y-4">
                <div>
                    <h4 class="text-sm font-bold text-indigo-950">
                        🎯 نظام الذكاء ونسبة التطابق المئوية (تحديد متى يجيب البوت مباشرة ومتى يسأل "هل تقصد؟")
                    </h4>
                    <p class="text-xs text-slate-600 mt-1">
                        كلما تطابقت كلمات العميل واتجاه القطعة مع الكلمات المفتاحية لأصنافك ترتفع النسبة إلى 80% أو 100% فيجيب البوت فوراً، أما إذا كانت النسبة متوسطة (مثل 40% أو 60%) فيسأله البوت بالصيغة التي تضعها في خانة "هل تقصد؟".
                    </p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-slate-800 mb-1">
                            ✅ نسبة الإجابة المباشرة بالتوفر والسعر (من % إلى 100%) :
                        </label>
                        <input
                            type="number"
                            min="1"
                            max="100"
                            name="confidence_direct_threshold"
                            value="{{ $botSettings['confidence_direct_threshold'] ?? '80' }}"
                            class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-indigo-500 font-bold text-emerald-700"
                        />
                        <span class="text-xs text-slate-500 mt-1 block">
                            مثال: ضع <strong>80</strong> ليقوم البوت بالرد المباشر إذا وصلت دقة التعرف على القطعة إلى 80% أو 100%.
                        </span>
                    </div>

                    <div>
                        <label class="block text-sm font-bold text-slate-800 mb-1">
                            🤔 الحد الأدنى لنسبة الاقتراح وسؤال "هل تقصد؟" (%) :
                        </label>
                        <input
                            type="number"
                            min="1"
                            max="100"
                            name="confidence_suggest_threshold"
                            value="{{ $botSettings['confidence_suggest_threshold'] ?? '40' }}"
                            class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-indigo-500 font-bold text-amber-700"
                        />
                        <span class="text-xs text-slate-500 mt-1 block">
                            مثال: ضع <strong>40</strong> ليرسل البوت رسالة "هل تقصد؟" عندما تكون النسبة بين 40% وأقل من نسبة الإجابة المباشرة.
                        </span>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-1">
                        💡 5. رد البوت عند الشك أو التشابه الجزئي (رسالة "هل تقصد كذا وكذا؟") :
                    </label>
                    <textarea
                        name="reply_did_you_mean"
                        rows="3"
                        placeholder="مثال: هل تقصد ({suggested_item}) لسيارة {car} موديل {year}؟ يرجى الرد بـ (نعم) للتأكيد."
                        class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-indigo-500"
                    >{{ $botSettings['reply_did_you_mean'] ?? '' }}</textarea>
                    <span class="text-xs text-indigo-700 mt-1 block font-semibold">
                        يمكنك استخدام المتغيرات: <code>{suggested_item}</code> لاسم الصنف المقترح، أو <code>{suggested_list}</code> لقائمة المقترحات مع السعر، و<code>{confidence}</code> لنسبة التطابق، و<code>{part}</code> و<code>{car}</code> و<code>{year}</code>. وإذا رد الزبون بـ (نعم / ايوه / صح) سيرسل له البوت تفاصيل القطعة وسعرها تلقائياً!
                    </span>
                </div>
            </div>

            <!-- إعدادات الحد الأقصى للاستعلام لكل عميل يراسل البوت -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4 bg-amber-50/60 rounded-xl border border-amber-200">
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-1">🔢 عدد مرات الاستعلام المسموحة لكل رقم عميل :</label>
                    <input type="number" min="0" name="max_queries_per_phone" value="{{ $botSettings['max_queries_per_phone'] ?? '0' }}" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-500" />
                    <span class="text-xs text-slate-500 mt-1 block">ضع <strong>0</strong> إذا كنت تريد الاستعلام مفتوحاً للجميع بلا حدود، أو حدد رقماً (مثلاً: 5 استعلامات لكل رقم، ما عدا الأرقام المستثناة بالأسفل).</span>
                </div>

                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-1">🛑 رسالة البوت عند انتهاء رصيد استعلامات العميل (اتركها فارغة لعدم الرد) :</label>
                    <textarea name="reply_quota_exceeded" rows="2" placeholder="إذا تركتها فارغة سيتوقف البوت عن الرد على العميل بصمت بعد تجاوزه الحد المسموح" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-500">{{ $botSettings['reply_quota_exceeded'] ?? '' }}</textarea>
                </div>
            </div>

            <!-- خانات الردود الأربعة الأساسية -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- خانة الرد في حال التوفر -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">1. رد البوت في حال توفر القطعة ✅ (اتركها فارغة لعدم الرد)</label>
                    <textarea name="reply_available" rows="4" placeholder="إذا تركت هذه الخانة فارغة لن يرد البوت عند توفر القطعة" class="w-full border border-slate-300 rounded-lg text-sm p-2.5 focus:outline-none focus:border-emerald-500">{{ $botSettings['reply_available'] ?? '' }}</textarea>
                </div>

                <!-- خانة الرد في حال عدم التوفر -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">2. رد البوت في حال عدم توفر القطعة ❌ (يرسل مرة واحدة فقط دون تكرار)</label>
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
                    💾 حفظ جميع إعدادات البوت ونسب الذكاء والربط
                </button>
            </div>
        </form>
    </div>

    <!-- قسم الأرقام المستثناة (استعلام مفتوح بلا حدود) + سجل استهلاك الأرقام -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- قائمة الأرقام المستثناة (Whitelist) -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">
            <div>
                <h3 class="text-base font-bold text-slate-800">
                    ⭐ الأرقام المستثناة (حد استعلام مفتوح دائماً)
                </h3>
                <p class="text-xs text-slate-500 mt-1">
                    أضف أرقام العملاء المميزين أو الورش المتعاقدة معك ليكون الاستعلام لهم مفتوحاً بلا حدود حتى لو وضعت حداً أقصى للجميع.
                </p>
            </div>

            <form action="{{ route('pages.second.whitelist.add') }}" method="POST" class="flex flex-col sm:flex-row gap-2">
                @csrf
                <input type="text" name="phone" required placeholder="رقم الجوال (مثال: 0556666037)" class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:border-emerald-500" />
                <input type="text" name="label" placeholder="اسم العميل / ملاحظة (اختياري)" class="flex-1 border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-emerald-500" />
                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-semibold whitespace-nowrap">
                    + إضافة استثناء
                </button>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-right border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-slate-700">
                            <th class="p-2.5">الرقم المستثنى</th>
                            <th class="p-2.5">الاسم / الوصف</th>
                            <th class="p-2.5">إجراء</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse($whitelistPhones ?? [] as $wp)
                            <tr class="hover:bg-slate-50">
                                <td class="p-2.5 font-mono text-emerald-700 font-bold">{{ $wp->phone }}</td>
                                <td class="p-2.5 text-slate-600">{{ $wp->label ?: 'عميل مستثنى' }}</td>
                                <td class="p-2.5">
                                    <form action="{{ route('pages.second.whitelist.delete', $wp->id) }}" method="POST">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-rose-600 hover:underline text-xs font-bold">إزالة</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="p-4 text-center text-slate-400 text-xs">لا توجد أرقام مضافة في قائمة الاستثناء حالياً.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- عداد استهلاك الاستعلامات للأرقام -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-slate-800">
                        📊 سجل استهلاك الاستعلامات للأرقام
                    </h3>
                    <p class="text-xs text-slate-500 mt-1">
                        يوضح عدد الاستعلامات المكتملة لكل رقم واتساب، ويمكنك تصفير العداد لأي رقم بضغطة زر.
                    </p>
                </div>

                <form action="{{ route('pages.second.quotas.reset') }}" method="POST">
                    @csrf
                    <button type="submit" class="bg-slate-200 hover:bg-slate-300 text-slate-800 px-3 py-1.5 rounded-lg text-xs font-bold">
                        🔄 تصفير الكل
                    </button>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-right border-collapse">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-slate-700">
                            <th class="p-2.5">رقم الزبون</th>
                            <th class="p-2.5">عدد الاستعلامات</th>
                            <th class="p-2.5">إجراء</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse($customerQuotas ?? [] as $cq)
                            <tr class="hover:bg-slate-50">
                                <td class="p-2.5 font-mono">{{ $cq->phone }}</td>
                                <td class="p-2.5">
                                    <span class="bg-indigo-100 text-indigo-800 px-2.5 py-0.5 rounded-full text-xs font-bold">
                                        {{ $cq->queries_count }} استعلام
                                    </span>
                                </td>
                                <td class="p-2.5">
                                    <form action="{{ route('pages.second.quotas.reset') }}" method="POST">
                                        @csrf
                                        <input type="hidden" name="quota_id" value="{{ $cq->id }}" />
                                        <button type="submit" class="text-indigo-600 hover:underline text-xs font-bold">تصفير العداد</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="p-4 text-center text-slate-400 text-xs">لا توجد استعلامات مسجلة حتى الآن.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- جدول ذاكرة المحادثات النشطة الخاصة بهذا الحساب -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <h3 class="text-base font-bold text-slate-800 mb-3">
            🧠 ذاكرة المحادثات الجارية لحسابك (حفظ وترتيب طلبات الزبون المتعددة حتى تكتمل: القطع + السيارة + سنة الصنع)
        </h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-right border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-700">
                        <th class="p-3">رقم الزبون</th>
                        <th class="p-3">السيارة المكتشفة</th>
                        <th class="p-3">سنة الصنع</th>
                        <th class="p-3">الأصناف المطلوبة (المحفوظة)</th>
                        <th class="p-3">حالة الجلسة</th>
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
                            <td class="p-3">
                                @if(!empty($sess->pending_suggestion_ids))
                                    <span class="bg-amber-100 text-amber-900 px-2 py-0.5 rounded text-xs font-bold">بانتظار تأكيد (هل تقصد؟)</span>
                                @else
                                    <span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded text-xs">استكمال بيانات</span>
                                @endif
                            </td>
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
                            <td colspan="7" class="p-4 text-center text-slate-400">لا توجد محادثات معلقة في الذاكرة حالياً.</td>
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

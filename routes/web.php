<?php

use App\Http\Controllers\ItemController;
use App\Http\Controllers\ProfileController;
use App\Models\Item;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

// تهيئة جداول إعدادات البوت العامة وإعدادات كل عميل وذاكرة المحادثة تلقائياً
if (!Schema::hasTable('bot_settings')) {
    Schema::create('bot_settings', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->text('value')->nullable();
    });
}

if (!Schema::hasTable('user_bot_settings')) {
    Schema::create('user_bot_settings', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->index();
        $table->string('key');
        $table->text('value')->nullable();
        $table->unique(['user_id', 'key']);
    });
}

if (!Schema::hasTable('whatsapp_sessions')) {
    Schema::create('whatsapp_sessions', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->default(1)->index();
        $table->string('phone')->index();
        $table->string('detected_car')->nullable();
        $table->string('detected_year')->nullable();
        $table->text('part_query')->nullable();
        $table->text('full_conversation')->nullable();
        $table->timestamps();
    });
} elseif (!Schema::hasColumn('whatsapp_sessions', 'user_id')) {
    try {
        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->default(1)->index();
        });
    } catch (\Throwable $e) {}
}

Route::get('/', function () {
    return redirect()->route('inventory.index');
});

Route::get('/dashboard', function () {
    return redirect()->route('inventory.index');
})->middleware(['auth', 'verified'])->name('dashboard');

/**
 * دالة لتنسيق رقم الجوال بالصيغة الدولية الموحدة +966
 */
function formatSaudiPhoneIntl($phone) {
    $digits = preg_replace('/\D/', '', $phone);
    if (str_starts_with($digits, '966')) {
        $digits = substr($digits, 3);
    }
    if (str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) === 9) {
        return '+966' . $digits;
    }
    return $phone;
}

/**
 * دالة تحويل الأرقام العربية/الفارسية إلى إنجليزية لتسهيل التعرف على السنوات والأرقام
 */
function normalizeArabicDigits(string $str): string {
    $arabic = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $english = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    return str_replace($arabic, $english, $str);
}

/**
 * دالة التحقق مما إذا كانت سنة العميل (مثل 25 أو 2025) تقع داخل نطاق سنة الصنف (مثل 2018-2026 أو 18-25)
 */
function isYearMatchingItem(string $queryYear, ?string $itemYears, string $itemName): bool {
    $qNorm = normalizeArabicDigits(trim($queryYear));
    if (!preg_match('/^(\d{2}|\d{4})$/', $qNorm)) {
        return true;
    }
    $qFull = strlen($qNorm) === 2 ? ((int)$qNorm <= 40 ? 2000 + (int)$qNorm : 1900 + (int)$qNorm) : (int)$qNorm;
    $qShort = substr((string)$qFull, -2);

    $targetText = normalizeArabicDigits(($itemYears ?? '') . ' ' . $itemName);

    // 1. فحص النطاقات مثل 2018-2026 أو 18-26 أو 18/25
    if (preg_match_all('/(\d{2,4})\s*[\/-]\s*(\d{2,4})/u', $targetText, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $y1 = strlen($m[1]) === 2 ? ((int)$m[1] <= 40 ? 2000 + (int)$m[1] : 1900 + (int)$m[1]) : (int)$m[1];
            $y2 = strlen($m[2]) === 2 ? ((int)$m[2] <= 40 ? 2000 + (int)$m[2] : 1900 + (int)$m[2]) : (int)$m[2];
            $start = min($y1, $y2);
            $end   = max($y1, $y2);
            if ($qFull >= $start && $qFull <= $end) {
                return true;
            }
        }
    }

    // 2. فحص التطابق المباشر للسنة الكاملة أو المختصرة كرقم مستقل
    if (preg_match('/(?<!\d)(' . preg_quote((string)$qFull, '/') . '|' . preg_quote($qShort, '/') . ')(?!\d)/u', $targetText)) {
        return true;
    }

    return false;
}

/**
 * دالة تنظيف الكلمات المفتاحية القديمة من أسماء السيارات وسنوات الصنع
 */
function purgeCarAndYearKeywords(): void {
    $carsList = DB::table('cars')->get();
    $aliasesList = DB::table('car_aliases')->pluck('alias')->toArray();
    $patterns = DB::table('year_patterns')->pluck('pattern_example')->toArray();

    $forbiddenWords = [];
    foreach ($carsList as $c) {
        $forbiddenWords[] = trim($c->car_name);
        $forbiddenWords = array_merge($forbiddenWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $c->car_name, -1, PREG_SPLIT_NO_EMPTY));
    }
    foreach ($aliasesList as $a) {
        $forbiddenWords[] = trim($a);
        $forbiddenWords = array_merge($forbiddenWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $a, -1, PREG_SPLIT_NO_EMPTY));
    }
    foreach ($patterns as $p) {
        $forbiddenWords[] = trim($p);
        $forbiddenWords = array_merge($forbiddenWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $p, -1, PREG_SPLIT_NO_EMPTY));
    }

    $forbiddenWords = array_unique(array_filter(array_map('mb_strtolower', $forbiddenWords)));

    $allKeywords = DB::table('item_keywords')->get();
    foreach ($allKeywords as $kw) {
        $kwNorm = normalizeArabicDigits(mb_strtolower(trim($kw->keyword)));
        $isYear = preg_match('/^(?:19|20)\d{2}$/u', $kwNorm)
               || preg_match('/^(?:(?:19|20)?\d{2})[\/-](?:(?:19|20)?\d{2})$/u', $kwNorm)
               || preg_match('/^\d+$/u', $kwNorm);

        if ($isYear || in_array($kwNorm, $forbiddenWords)) {
            DB::table('item_keywords')->where('id', $kw->id)->delete();
        }
    }
}

/**
 * دالة التحليل والربط الذكية بقلب النظام والسيارات والسنوات
 */
function syncItemToMasterCore(string $itemNumber, string $itemName, ?int $userId = null): void {
    $cleanNumber = strtoupper(trim($itemNumber));
    $cleanName = normalizeArabicDigits(trim($itemName));

    if ($cleanNumber === '') return;

    $detectedCar = null;
    $detectedCategory = null;
    $detectedYears = null;

    $carsList = DB::table('cars')->get();
    $allCarWords = [];

    foreach ($carsList as $car) {
        $allCarWords = array_merge($allCarWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $car->car_name, -1, PREG_SPLIT_NO_EMPTY));
        if (!$detectedCar && mb_stripos($cleanName, $car->car_name) !== false) {
            $detectedCar = $car->car_name;
            $detectedCategory = $car->category;
        }
        $aliases = DB::table('car_aliases')->where('car_id', $car->id)->pluck('alias')->toArray();
        foreach ($aliases as $alias) {
            if ($alias !== '') {
                $allCarWords = array_merge($allCarWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $alias, -1, PREG_SPLIT_NO_EMPTY));
                if (!$detectedCar && mb_stripos($cleanName, $alias) !== false) {
                    $detectedCar = $car->car_name;
                    $detectedCategory = $car->category;
                }
            }
        }
    }

    $patterns = DB::table('year_patterns')->pluck('pattern_example')->toArray();
    $allYearWords = [];
    foreach ($patterns as $pattern) {
        $normP = normalizeArabicDigits($pattern);
        $allYearWords[] = $normP;
        $allYearWords = array_merge($allYearWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $normP, -1, PREG_SPLIT_NO_EMPTY));
        if (!$detectedYears && mb_stripos($cleanName, $normP) !== false) {
            $detectedYears = $normP;
        }
    }

    $nameForKeywords = $cleanName;
    foreach ($carsList as $car) {
        $nameForKeywords = str_ireplace($car->car_name, ' ', $nameForKeywords);
    }
    $allAliases = DB::table('car_aliases')->pluck('alias')->toArray();
    foreach ($allAliases as $al) {
        if ($al !== '') {
            $nameForKeywords = str_ireplace($al, ' ', $nameForKeywords);
        }
    }
    foreach ($patterns as $pattern) {
        if ($pattern !== '') {
            $nameForKeywords = str_ireplace(normalizeArabicDigits($pattern), ' ', $nameForKeywords);
        }
    }

    // استخراج السنوات من اسم الصنف (مثل 2015-2020 أو 18-25 أو 2024 أو 25)
    if (!$detectedYears) {
        if (preg_match('/(?:(?:19|20)?\d{2})\s*[\/-]\s*(?:(?:19|20)?\d{2})/u', $cleanName, $m)) {
            $detectedYears = str_replace(' ', '', $m[0]);
        } elseif (preg_match('/\b(?:19\d{2}|20\d{2})\b/u', $cleanName, $m)) {
            $detectedYears = $m[0];
        } elseif (preg_match('/(?<!\d)\d{2}(?!\d)/u', $nameForKeywords, $m)) {
            $detectedYears = $m[0];
        }
    }

    $nameForKeywords = preg_replace('/(?:(?:19|20)?\d{2})\s*[\/-]\s*(?:(?:19|20)?\d{2})/u', ' ', $nameForKeywords);
    $nameForKeywords = preg_replace('/\b(?:19|20)\d{2}\b/u', ' ', $nameForKeywords);
    $nameForKeywords = preg_replace('/(?<!\d)\d{2}(?!\d)/u', ' ', $nameForKeywords);

    DB::table('items')
        ->where('item_number', $cleanNumber)
        ->update([
            'detected_car_name' => $detectedCar,
            'detected_category' => $detectedCategory,
            'detected_years'    => $detectedYears,
        ]);

    $existingMaster = DB::table('master_items')->where('item_number', $cleanNumber)->first();
    if (!$existingMaster) {
        DB::table('master_items')->insert([
            'item_number'     => $cleanNumber,
            'first_item_name' => $cleanName,
            'first_user_id'   => $userId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    $skipWords = array_merge($allCarWords, $allYearWords);
    if ($detectedCar) {
        $skipWords = array_merge($skipWords, preg_split('/[\s,\-]+/u', $detectedCar));
    }
    if ($detectedYears) {
        $skipWords[] = $detectedYears;
        $skipWords = array_merge($skipWords, preg_split('/[\s,\-\/]+/u', $detectedYears));
    }
    $skipWords = array_unique(array_map('mb_strtolower', array_filter($skipWords)));

    $keywordsWords = preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $nameForKeywords, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($keywordsWords as $word) {
        $wordClean = trim($word);
        $wordLower = mb_strtolower($wordClean);

        $isYearPattern = preg_match('/^(?:19|20)\d{2}$/u', $wordClean)
                      || preg_match('/^(?:(?:19|20)?\d{2})[\/-](?:(?:19|20)?\d{2})$/u', $wordClean)
                      || preg_match('/^\d+$/u', $wordClean);

        if (mb_strlen($wordClean) >= 2 && !is_numeric($wordClean) && !$isYearPattern && !in_array($wordLower, $skipWords)) {
            DB::table('item_keywords')->insertOrIgnore([
                'item_number' => $cleanNumber,
                'keyword'     => $wordClean,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }
}

function syncAllExistingItemsToCore(): void {
    purgeCarAndYearKeywords();
    $allItems = DB::table('items')->orderBy('id', 'asc')->get();
    foreach ($allItems as $item) {
        syncItemToMasterCore($item->item_number, $item->item_name, $item->user_id);
    }
}

/**
 * دالة استخراج السيارة والسنة (بما في ذلك السنوات المكونة من رقمين مثل 25 أو 2025 أو النطاقات) واسم/رقم القطعة من رسالة الزبون
 */
function parseWhatsappText(string $text): array {
    $normText = normalizeArabicDigits($text);
    $detectedCar = null;
    $detectedCategory = null;
    $detectedYear = null;
    $remainingText = $normText;

    // 1. استخراج رقم القطعة إن وجد (مثل 90919-01289) لحمايته من الالتباس مع السنوات
    $extractedPartNumbers = [];
    if (preg_match_all('/\b[A-Za-z0-9]{4,}[\-][A-Za-z0-9\-]+\b/u', $remainingText, $pm)) {
        foreach ($pm[0] as $partNum) {
            $extractedPartNumbers[] = strtoupper($partNum);
            $remainingText = str_replace($partNum, ' ', $remainingText);
        }
    }

    // 2. البحث عن اسم السيارة أو مرادفاتها
    $carsList = DB::table('cars')->get();
    foreach ($carsList as $car) {
        if (mb_stripos($remainingText, $car->car_name) !== false) {
            $detectedCar = $car->car_name;
            $detectedCategory = $car->category;
            $remainingText = str_ireplace($car->car_name, ' ', $remainingText);
            break;
        }
        $aliases = DB::table('car_aliases')->where('car_id', $car->id)->pluck('alias')->toArray();
        foreach ($aliases as $alias) {
            if ($alias !== '' && mb_stripos($remainingText, $alias) !== false) {
                $detectedCar = $car->car_name;
                $detectedCategory = $car->category;
                $remainingText = str_ireplace($alias, ' ', $remainingText);
                break 2;
            }
        }
    }

    // 3. البحث في أنماط السنوات المضافة في الإعدادات (سواء تطابق مباشر أو إذا أرسل العميل "25" والنمط المسجل "2025" أو "20-26")
    $patterns = DB::table('year_patterns')->pluck('pattern_example')->toArray();
    foreach ($patterns as $pattern) {
        $normPattern = normalizeArabicDigits(trim($pattern));
        if ($normPattern !== '' && preg_match('/(?<!\d)' . preg_quote($normPattern, '/') . '(?!\d)/u', $remainingText)) {
            $detectedYear = $normPattern;
            $remainingText = preg_replace('/(?<!\d)' . preg_quote($normPattern, '/') . '(?!\d)/u', ' ', $remainingText, 1);
            break;
        }
    }

    // 4. التعرف الذكي على أي سنة صنع رباعية (1980-2035) أو ثنائية (مثل 25 أو 18 أو 99) أو نطاق (18-22)
    if (!$detectedYear) {
        if (preg_match('/(?<!\d)((?:19|20)?\d{2}\s*[\/-]\s*(?:19|20)?\d{2})(?!\d)/u', $remainingText, $matches)) {
            $detectedYear = str_replace(' ', '', $matches[1]);
            $remainingText = str_replace($matches[1], ' ', $remainingText);
        } elseif (preg_match('/(?<!\d)(19[7-9]\d|20[0-3]\d)(?!\d)/u', $remainingText, $matches)) {
            $detectedYear = $matches[1];
            $remainingText = preg_replace('/(?<!\d)' . preg_quote($matches[1], '/') . '(?!\d)/u', ' ', $remainingText, 1);
        } elseif (preg_match('/(?<!\d)(\d{2})(?!\d)/u', $remainingText, $matches)) {
            $twoDigit = (int) $matches[1];
            // يقبل الموديلات من 70 إلى 99 (1970-1999) ومن 00 إلى 35 (2000-2035 مثل 25 لموديل 2025)
            if ($twoDigit <= 35 || $twoDigit >= 70) {
                $detectedYear = $matches[1];
                $remainingText = preg_replace('/(?<!\d)' . preg_quote($matches[1], '/') . '(?!\d)/u', ' ', $remainingText, 1);
            }
        }
    }

    // 5. تنظيف الكلمات الدلالية العامة لاستخلاص اسم القطعة (ترجمة العميل)
    $stopWords = ['السلام', 'عليكم', 'ابغى', 'ابغا', 'ابي', 'عندكم', 'موجود', 'احصل', 'سيارة', 'سياره', 'موديل', 'سنة', 'سنه', 'لو', 'سمحت', 'ياغالي', 'بكم', 'هل', 'فيه', 'يتوفر', 'السيارة', 'السياره', 'من', 'فضلك', 'مطلوب'];
    $words = preg_split('/[\s,\+\/\(\)\*\.]+/u', trim($remainingText), -1, PREG_SPLIT_NO_EMPTY);
    $cleanWords = $extractedPartNumbers;
    foreach ($words as $w) {
        $wTrim = trim($w, '- ');
        if ($wTrim !== '' && !in_array(mb_strtolower($wTrim), $stopWords) && mb_strlen($wTrim) >= 2) {
            $cleanWords[] = $wTrim;
        }
    }

    return [
        'car'      => $detectedCar,
        'category' => $detectedCategory,
        'year'     => $detectedYear,
        'part'     => implode(' ', array_unique($cleanWords)),
    ];
}

/**
 * دالة جلب إعدادات البوت الخاصة بعميل معين (مع القيم الافتراضية عند عدم الحفظ المسبق)
 */
function getUserBotSettings(int $userId): array {
    $defaults = [
        'reply_available'    => "القطعة متوفرة لدينا ✅\nاسم الصنف: {item_name}\nرقم الصنف: {item_number}\nالسعر: {price} ريال",
        'reply_unavailable'  => "نعتذر منك، القطعة المطلوبة ({part}) لسيارة {car} موديل {year} غير متوفرة حالياً ❌",
        'reply_ask_car'      => "حياك الله، فضلاً ما هو نوع أو اسم السيارة؟",
        'reply_ask_year'     => "فضلاً كم موديل (سنة صنع) السيارة {car}؟",
        'whatsapp_api_url'   => "",
        'whatsapp_api_token' => "",
        'whatsapp_session_id'=> "",
    ];

    $saved = DB::table('user_bot_settings')
        ->where('user_id', $userId)
        ->pluck('value', 'key')
        ->toArray();

    // في حال لم يكن لدى المستخدم إعدادات محفوظة بعد وكان هو الأدمن، نقرأ من الجدول القديم bot_settings للتوافق
    if (empty($saved)) {
        $legacy = DB::table('bot_settings')->pluck('value', 'key')->toArray();
        if (!empty($legacy)) {
            $saved = $legacy;
        }
    }

    // ندمج بحيث إذا كان المفتاح محفوظاً (حتى لو تركه المستخدم فارغاً عن قصد '') نحتفظ بقيمته الفارغة لكي لا يرد البوت
    $result = $defaults;
    foreach ($saved as $k => $v) {
        $result[$k] = (string) $v;
    }

    return $result;
}

/**
 * دالة موحدة لاستبدال جميع الدوال والمتغيرات في أي خانة من خانات الرد الآلي
 */
function formatBotTemplate(string $template, array $data): string {
    if (trim($template) === '') {
        return '';
    }

    $replacements = [
        '{item_name}'   => $data['item_name'] ?? '',
        '{item_number}' => $data['item_number'] ?? '',
        '{price}'       => $data['price'] ?? '',
        '{quantity}'    => $data['quantity'] ?? '',
        '{car}'         => $data['car'] ?? '',
        '{category}'    => $data['category'] ?? '',
        '{year}'        => $data['year'] ?? '',
        '{part}'        => $data['part'] ?? '',
        '{client_name}' => $data['client_name'] ?? '',
        '{phone}'       => $data['phone'] ?? '',
        '{items}'       => $data['items'] ?? '',
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

/**
 * دالة تحديد صاحب حساب الواتساب (العميل أو الأدمن) بناءً على session_id أو الرابط أو التوكن القادم في الويب هوك
 */
function resolveWebhookOwnerUserId(Request $request, array $payload): int {
    // 1. إذا كان الرابط يحتوي على ?user_id=X
    if ($request->filled('user_id')) {
        return (int) $request->query('user_id');
    }

    // 2. البحث عن طريق session_id المرسل في الويب هوك
    $incomingSession = data_get($payload, 'session_id')
                    ?? data_get($payload, 'sessionId')
                    ?? data_get($payload, 'session')
                    ?? data_get($payload, 'instance_id')
                    ?? data_get($payload, 'instanceId')
                    ?? data_get($payload, 'tenant_id')
                    ?? data_get($payload, 'data.session_id')
                    ?? data_get($payload, 'data.sessionId')
                    ?? data_get($payload, 'data.session')
                    ?? data_get($payload, 'data.instance_id')
                    ?? $request->query('session_id');

    if (!empty($incomingSession)) {
        $match = DB::table('user_bot_settings')
            ->where('key', 'whatsapp_session_id')
            ->where('value', trim((string) $incomingSession))
            ->first();
        if ($match) {
            return (int) $match->user_id;
        }
    }

    // 3. في حال وجود مستخدم واحد فقط قام بضبط session_id أو api_url نستخدمه، وإلا نستخدم الأدمن الافتراضي
    $firstConfigured = DB::table('user_bot_settings')
        ->whereIn('key', ['whatsapp_session_id', 'whatsapp_api_url'])
        ->whereNotNull('value')
        ->where('value', '!=', '')
        ->first();

    if ($firstConfigured) {
        return (int) $firstConfigured->user_id;
    }

    $admin = User::where('role', 'admin')->first();
    return $admin ? $admin->id : 1;
}

function handleWebhook(Request $request) {
    $payload = $request->all();
    $eventType = $request->input('event', data_get($payload, 'event', 'unknown'));

    DB::table('webhook_logs')->insert([
        'event_type' => $eventType,
        'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($eventType === 'test.ping') {
        return response()->json(['status' => 'pong'], 200);
    }

    $fromMe = data_get($payload, 'data.fromMe')
           ?? data_get($payload, 'data.message.fromMe')
           ?? data_get($payload, 'data.is_from_me')
           ?? data_get($payload, 'fromMe', false);

    if ($fromMe === true || $fromMe === 'true' || $fromMe === 1 || $eventType === 'message.sent') {
        return response()->json(['status' => 'ignored_from_me'], 200);
    }

    $sender = data_get($payload, 'data.sender_phone')
           ?? data_get($payload, 'sender_phone')
           ?? data_get($payload, 'data.message.sender_phone')
           ?? data_get($payload, 'data.from')
           ?? data_get($payload, 'from')
           ?? data_get($payload, 'sender')
           ?? data_get($payload, 'phone')
           ?? data_get($payload, 'data.phone')
           ?? data_get($payload, 'data.sender')
           ?? data_get($payload, 'data.contact.phone')
           ?? data_get($payload, 'data.key.remoteJid');

    $messageText = data_get($payload, 'data.message_body')
                ?? data_get($payload, 'data.text')
                ?? data_get($payload, 'data.body')
                ?? data_get($payload, 'data.content')
                ?? data_get($payload, 'data.message')
                ?? data_get($payload, 'data.message.text')
                ?? data_get($payload, 'data.message.body')
                ?? data_get($payload, 'data.message.content')
                ?? data_get($payload, 'data.message.conversation')
                ?? data_get($payload, 'data.message.extendedTextMessage.text')
                ?? data_get($payload, 'message_body')
                ?? data_get($payload, 'body')
                ?? data_get($payload, 'message')
                ?? data_get($payload, 'text')
                ?? data_get($payload, 'content');

    if (is_array($messageText)) {
        $messageText = $messageText['text'] ?? $messageText['body'] ?? $messageText['content'] ?? $messageText['conversation'] ?? null;
    }

    if (!$sender || !$messageText || !is_string($messageText)) {
        return response()->json(['status' => 'no_message_detected'], 200);
    }

    $rawSender = (string) $sender;
    $cleanPhone = preg_replace('/@.*$/', '', $rawSender);
    $cleanPhone = preg_replace('/\D/', '', $cleanPhone);
    $messageText = trim($messageText);

    if ($cleanPhone === '' || $messageText === '') {
        return response()->json(['status' => 'empty'], 200);
    }

    // تحديد العميل (أو الأدمن) صاحب حساب الواتساب المستهدف
    $ownerUserId = resolveWebhookOwnerUserId($request, $payload);
    $ownerUser   = User::find($ownerUserId);
    $settings    = getUserBotSettings($ownerUserId);

    $msgAskCar      = trim($settings['reply_ask_car'] ?? '');
    $msgAskYear     = trim($settings['reply_ask_year'] ?? '');
    $msgAvailable   = trim($settings['reply_available'] ?? '');
    $msgUnavailable = trim($settings['reply_unavailable'] ?? '');

    // استرجاع ذاكرة المحادثة الخاصة بهذا الزبون لدى هذا العميل
    $session = DB::table('whatsapp_sessions')
        ->where('user_id', $ownerUserId)
        ->where('phone', $cleanPhone)
        ->first();

    if ($session && now()->diffInMinutes($session->updated_at) > 120) {
        DB::table('whatsapp_sessions')->where('id', $session->id)->delete();
        $session = null;
    }

    $parsed = parseWhatsappText($messageText);

    $car  = $parsed['car']  ?: ($session->detected_car ?? null);
    $year = $parsed['year'] ?: ($session->detected_year ?? null);
    $part = $parsed['part'] ?: ($session->part_query ?? null);

    if (!empty($session->part_query) && !empty($parsed['part']) && mb_stripos($session->part_query, $parsed['part']) === false) {
        $part = trim($session->part_query . ' ' . $parsed['part']);
    }

    $fullConv = trim(($session->full_conversation ?? '') . ' ' . $messageText);

    if ($session) {
        DB::table('whatsapp_sessions')->where('id', $session->id)->update([
            'detected_car'      => $car,
            'detected_year'     => $year,
            'part_query'        => $part ?: $messageText,
            'full_conversation' => $fullConv,
            'updated_at'        => now(),
        ]);
    } else {
        DB::table('whatsapp_sessions')->insert([
            'user_id'           => $ownerUserId,
            'phone'             => $cleanPhone,
            'detected_car'      => $car,
            'detected_year'     => $year,
            'part_query'        => $part ?: $messageText,
            'full_conversation' => $fullConv,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    // استخراج الفئة للسيارة إن وجدت
    $carCategory = $parsed['category'] ?? '';
    if (!$carCategory && $car) {
        $carRow = DB::table('cars')->where('car_name', $car)->first();
        $carCategory = $carRow->category ?? '';
    }

    $templateVars = [
        'item_name'   => '',
        'item_number' => '',
        'price'       => '',
        'quantity'    => '',
        'car'         => $car ?? '',
        'category'    => $carCategory,
        'year'        => $year ?? '',
        'part'        => $part ?: $messageText,
        'client_name' => $ownerUser->name ?? '',
        'phone'       => $cleanPhone,
        'items'       => '',
    ];

    $replyText = '';

    // 1. إذا لم يتم التعرف على السيارة
    if (!$car) {
        // إذا ترك العميل خانة طلب السيارة فارغة، لا يرد البوت
        if ($msgAskCar === '') {
            return response()->json(['status' => 'skipped_empty_ask_car_template'], 200);
        }
        $replyText = formatBotTemplate($msgAskCar, $templateVars);
    }
    // 2. إذا تم التعرف على السيارة ولم يتم التعرف على سنة الصنع
    elseif (!$year) {
        // إذا ترك العميل خانة طلب سنة الصنع فارغة، لا يرد البوت
        if ($msgAskYear === '') {
            return response()->json(['status' => 'skipped_empty_ask_year_template'], 200);
        }
        $replyText = formatBotTemplate($msgAskYear, $templateVars);
    }
    // 3. اكتملت (السيارة + سنة الصنع + اسم/ترجمة القطعة) -> البحث في مخزون العميل
    else {
        $searchPart = trim($part ?: '');
        $partWords = array_values(array_filter(explode(' ', $searchPart), fn($w) => mb_strlen($w) >= 2));

        // البحث في مخزون العميل صاحب الواتساب (ترجمة العميل `item_name` + نوع السيارة + سنة الصنع)
        $matchedNumbers = [];
        if (!empty($partWords)) {
            $kwQuery = DB::table('item_keywords');
            foreach ($partWords as $pw) {
                $kwQuery->orWhere('keyword', 'like', "%{$pw}%");
            }
            $matchedNumbers = $kwQuery->pluck('item_number')->toArray();
        }

        // جلب أصناف العميل المتوفرة للسيارة المطلوبة
        $candidates = Item::where('user_id', $ownerUserId)
            ->where('quantity', '>', 0)
            ->where(function ($q) use ($car) {
                $q->where('detected_car_name', 'like', "%{$car}%")
                  ->orWhere('item_name', 'like', "%{$car}%");
            })
            ->where(function ($q) use ($partWords, $matchedNumbers) {
                if (!empty($matchedNumbers)) {
                    $q->orWhereIn('item_number', $matchedNumbers);
                }
                foreach ($partWords as $pw) {
                    $q->orWhere('item_name', 'like', "%{$pw}%")
                      ->orWhere('item_number', 'like', "%{$pw}%");
                }
            })
            ->get();

        // تصفية الأصناف بحسب مطابقة سنة الصنع (سواء كتب 25 أو 2025 أو كانت داخل نطاق مثل 2020-2026)
        $foundItems = $candidates->filter(function ($item) use ($year) {
            return isYearMatchingItem($year, $item->detected_years, $item->item_name);
        })->take(5)->values();

        // مسح الذاكرة بعد اكتمال الطلب
        DB::table('whatsapp_sessions')
            ->where('user_id', $ownerUserId)
            ->where('phone', $cleanPhone)
            ->delete();

        if ($foundItems->count() > 0) {
            // إذا ترك العميل خانة التوفر فارغة، لا يرد البوت نهائياً
            if ($msgAvailable === '') {
                return response()->json(['status' => 'skipped_empty_available_template'], 200);
            }

            $firstItem = $foundItems->first();
            $itemsListText = $foundItems->map(function ($i) {
                return "- {$i->item_name} (رقم: {$i->item_number}) | السعر: {$i->price} ريال";
            })->implode("\n");

            $templateVars['item_name']   = $firstItem->item_name;
            $templateVars['item_number'] = $firstItem->item_number;
            $templateVars['price']       = (string) $firstItem->price;
            $templateVars['quantity']    = (string) $firstItem->quantity;
            $templateVars['items']       = $itemsListText;

            $replyText = formatBotTemplate($msgAvailable, $templateVars);
        } else {
            // إذا ترك العميل خانة عدم التوفر فارغة، لا يرد البوت نهائياً
            if ($msgUnavailable === '') {
                return response()->json(['status' => 'skipped_empty_unavailable_template'], 200);
            }

            $replyText = formatBotTemplate($msgUnavailable, $templateVars);
        }
    }

    if (trim($replyText) === '') {
        return response()->json(['status' => 'no_reply_configured'], 200);
    }

    // إرسال الرد عبر API الواتساب الخاص بالعميل (مع تمرير session_id)
    $apiUrl    = trim($settings['whatsapp_api_url'] ?? '');
    $apiToken  = trim($settings['whatsapp_api_token'] ?? '');
    $sessionId = trim($settings['whatsapp_session_id'] ?? '');
    $convId    = data_get($payload, 'data.conversation_id', data_get($payload, 'conversation_id'));

    if (!empty($apiUrl)) {
        try {
            $headers = ['Accept' => 'application/json'];
            if (!empty($apiToken)) {
                $headers['Authorization'] = 'Bearer ' . $apiToken;
                $headers['X-Api-Key'] = $apiToken;
            }

            Http::withHeaders($headers)->withoutVerifying()->timeout(10)->post($apiUrl, [
                'session_id'      => $sessionId,
                'sessionId'       => $sessionId,
                'session'         => $sessionId,
                'instance_id'     => $sessionId,
                'token'           => $apiToken,
                'api_key'         => $apiToken,
                'to'              => $cleanPhone,
                'phone'           => $cleanPhone,
                'recipient'       => $cleanPhone,
                'number'          => $cleanPhone,
                'chatId'          => $rawSender,
                'conversation_id' => $convId,
                'body'            => $replyText,
                'message'         => $replyText,
                'text'            => $replyText,
                'content'         => $replyText,
            ]);
        } catch (\Throwable $e) {
        }
    }

    return response()->json([
        'status'     => 'success',
        'session_id' => $sessionId,
        'reply'      => $replyText,
        'message'    => $replyText,
        'text'       => $replyText,
        'body'       => $replyText,
        'data'       => [
            'session_id' => $sessionId,
            'reply'      => $replyText,
            'message'    => $replyText,
            'text'       => $replyText,
        ],
        'memory'     => [
            'car'  => $car,
            'year' => $year,
            'part' => $part,
        ]
    ], 200);
}
Route::post('/api/webhook/receiver', fn(Request $r) => handleWebhook($r));
Route::post('/api/webhook/receiver/', fn(Request $r) => handleWebhook($r));

require __DIR__.'/auth.php';

// معالجة تسجيل الدخول الموحد (إدارة أو عملاء) مع توليد وإظهار كود الـ OTP مؤقتاً
Route::post('/login', function (Request $request) {
    $loginInput = trim($request->input('login', ''));
    $password = trim($request->input('password', ''));
    $cleanInput = preg_replace('/\D/', '', $loginInput);

    if (strlen($cleanInput) === 10 && str_starts_with($cleanInput, '0')) {
        $formattedTarget = formatSaudiPhoneIntl($loginInput);
        $localAlternative = $cleanInput;

        $user = User::where('phone', $formattedTarget)
                    ->orWhere('phone', $localAlternative)
                    ->first();

        if (!$user) {
            return back()->withInput()->with('error', 'رقم الجوال هذا غير مسجل في النظام.');
        }

        if (!$user->is_active) {
            return back()->withInput()->with('error', 'تم إيقاف حسابك مؤقتاً، يرجى التواصل مع الإدارة.');
        }

        if (empty($password)) {
            $user->otp_code = (string) random_int(1000, 9999);
            $user->save();

            return back()->withInput()
                         ->with('phone_for_otp', $loginInput)
                         ->with('success', $user->otp_code);
        }

        if (!empty($user->otp_code) && hash_equals((string) $user->otp_code, (string) $password)) {
            $user->otp_code = null;
            $user->save();

            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->route('inventory.index');
        } else {
            return back()->withInput()
                         ->with('phone_for_otp', $loginInput)
                         ->with('success', $user->otp_code)
                         ->with('error', 'كود التحقق غير صحيح. حاول مرة أخرى.');
        }
    } else {
        if (Auth::attempt(['email' => $loginInput, 'password' => $password])) {
            $request->session()->regenerate();
            return redirect()->intended('/dashboard');
        }

        $user = User::where('email', $loginInput)->orWhere('name', $loginInput)->first();
        if ($user && Hash::check($password, $user->password)) {
            Auth::login($user);
            return redirect()->intended('/dashboard');
        }

        return back()->withInput()->with('error', 'بيانات الدخول غير صحيحة.');
    }
})->name('login');

Route::middleware('auth')->group(function () {

    // 1. المخزون (خاص لكل مستخدم)
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/', function (Request $request) {
            $user = auth()->user();
            $targetUserId = $user->id;

            if ($user->role === 'admin' && $request->filled('client_id')) {
                $targetUserId = (int) $request->query('client_id');
            }

            $query = Item::where('user_id', $targetUserId);

            if ($request->filled('search')) {
                $s = $request->query('search');
                $query->where(function ($q) use ($s) {
                    $q->where('item_number', 'like', "%{$s}%")
                      ->orWhere('item_name', 'like', "%{$s}%")
                      ->orWhere('detected_car_name', 'like', "%{$s}%");
                });
            }

            $items = $query->latest()->paginate(15);

            if ($request->ajax() || $request->wantsJson()) {
                $html = view('inventory.partials.table_rows', compact('items'))->render();
                return response()->json(['html' => $html]);
            }

            $viewingClient = ($user->role === 'admin' && $request->filled('client_id')) ? User::find($targetUserId) : null;

            return view('inventory.index', compact('items', 'viewingClient'));
        })->name('index');

        Route::post('/', function (Request $request) {
            $data = $request->validate([
                'item_number' => 'required|string|max:255',
                'item_name'   => 'required|string|max:255',
                'quantity'    => 'required|integer|min:0',
                'price'       => 'required|numeric|min:0',
            ]);

            $userId = auth()->id();
            $itemNumber = strtoupper(trim($data['item_number']));
            $itemName   = trim($data['item_name']);

            $existing = DB::table('items')
                ->where('user_id', $userId)
                ->where('item_number', $itemNumber)
                ->first();

            if ($existing) {
                DB::table('items')->where('id', $existing->id)->update([
                    'item_name'  => $itemName,
                    'quantity'   => $data['quantity'],
                    'price'      => $data['price'],
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('items')->insert([
                    'user_id'     => $userId,
                    'item_number' => $itemNumber,
                    'item_name'   => $itemName,
                    'quantity'    => $data['quantity'],
                    'price'       => $data['price'],
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            syncItemToMasterCore($itemNumber, $itemName, $userId);

            return back()->with('success', 'تمت إضافة الصنف بنجاح.');
        })->name('store');

        Route::post('/import', function (Request $request) {
            $userId = auth()->id();

            if ($request->has('items_batch') && is_array($request->input('items_batch'))) {
                $batch = $request->input('items_batch');
                $importedCount = 0;

                foreach ($batch as $row) {
                    $itemNumber = strtoupper(trim((string) ($row['item_number'] ?? '')));
                    $itemName   = trim((string) ($row['item_name'] ?? ''));
                    $quantity   = (int) ($row['quantity'] ?? 0);
                    $price      = (float) ($row['price'] ?? 0);

                    if ($itemNumber === '' || $itemName === '') continue;

                    $existing = DB::table('items')
                        ->where('user_id', $userId)
                        ->where('item_number', $itemNumber)
                        ->first();

                    if ($existing) {
                        DB::table('items')
                            ->where('id', $existing->id)
                            ->update([
                                'item_name'  => $itemName,
                                'quantity'   => $quantity,
                                'price'      => $price,
                                'updated_at' => now(),
                            ]);
                    } else {
                        DB::table('items')->insert([
                            'user_id'     => $userId,
                            'item_number' => $itemNumber,
                            'item_name'   => $itemName,
                            'quantity'    => $quantity,
                            'price'       => $price,
                            'created_at'  => now(),
                            'updated_at'  => now(),
                        ]);
                    }

                    syncItemToMasterCore($itemNumber, $itemName, $userId);
                    $importedCount++;
                }

                return response()->json([
                    'status'   => 'success',
                    'imported' => $importedCount,
                ]);
            }

            $response = app(ItemController::class)->import($request);
            DB::table('items')->whereNull('user_id')->update(['user_id' => $userId]);
            syncAllExistingItemsToCore();
            return $response;
        })->name('import');

        Route::put('/{item}', function (Request $request, Item $item) {
            if (auth()->user()->role !== 'admin' && $item->user_id !== auth()->id()) {
                abort(403);
            }

            $item->update([
                'item_name' => $request->input('item_name', $item->item_name),
                'quantity'  => $request->input('quantity', $item->quantity),
                'price'     => $request->input('price', $item->price),
            ]);

            syncItemToMasterCore($item->item_number, $item->item_name, $item->user_id);
            return back()->with('success', 'تم تحديث الصنف (رقم الصنف محمي وثابت).');
        })->name('update');

        Route::delete('/{item}', [ItemController::class, 'destroy'])->name('destroy');
    });

    // 2. إدارة العملاء
    Route::get('/clients', function () {
        if (auth()->user()->role !== 'admin') abort(403);

        $clients = User::where('role', '!=', 'admin')
            ->orWhereNull('role')
            ->get()
            ->map(function ($client) {
                $client->items_count = DB::table('items')->where('user_id', $client->id)->count();
                return $client;
            });

        return view('pages.clients', compact('clients'));
    })->name('clients.index');

    Route::post('/clients', function (Request $request) {
        if (auth()->user()->role !== 'admin') abort(403);

        $request->validate([
            'name'  => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'city'  => 'required|string|max:100',
        ]);

        $otp = (string) rand(1000, 9999);
        $formattedPhone = formatSaudiPhoneIntl($request->phone);
        $cleanDigits = preg_replace('/\D/', '', $request->phone);

        User::create([
            'name'      => $request->name,
            'email'     => $cleanDigits . '@client.local',
            'phone'     => $formattedPhone,
            'city'      => $request->city,
            'role'      => 'client',
            'otp_code'  => $otp,
            'is_active' => true,
            'password'  => Hash::make($otp),
        ]);

        return back()->with('success', "تمت إضافة العميل ({$request->name}) بنجاح! رقم الجوال: {$formattedPhone} | كود التحقق: {$otp}");
    })->name('clients.store');

    Route::put('/clients/{id}', function (Request $request, $id) {
        if (auth()->user()->role !== 'admin') abort(403);
        $client = User::findOrFail($id);
        $formattedPhone = formatSaudiPhoneIntl($request->phone);

        $client->update([
            'name'  => $request->name,
            'phone' => $formattedPhone,
            'city'  => $request->city,
        ]);
        return back()->with('success', 'تم تحديث بيانات العميل بنجاح.');
    })->name('clients.update');

    Route::post('/clients/{id}/toggle', function ($id) {
        if (auth()->user()->role !== 'admin') abort(403);
        $client = User::findOrFail($id);
        $client->update(['is_active' => !$client->is_active]);
        return back()->with('success', 'تم تغيير حالة حساب العميل.');
    })->name('clients.toggle');

    Route::delete('/clients/{id}', function ($id) {
        if (auth()->user()->role !== 'admin') abort(403);
        DB::table('items')->where('user_id', $id)->delete();
        User::where('id', $id)->delete();
        return back()->with('success', 'تم حذف العميل وجميع أصنافه بنجاح.');
    })->name('clients.destroy');

    // 3. المخزون المشترك (قلب النظام)
    Route::get('/master-catalog', function () {
        if (auth()->user()->role !== 'admin') abort(403);
        syncAllExistingItemsToCore();

        $masterItems = DB::table('master_items')
            ->select(
                'master_items.item_number',
                'master_items.first_item_name',
                DB::raw('COALESCE(SUM(items.quantity), 0) as total_quantity'),
                DB::raw('COUNT(DISTINCT items.user_id) as clients_count')
            )
            ->leftJoin('items', 'master_items.item_number', '=', 'items.item_number')
            ->groupBy('master_items.item_number', 'master_items.first_item_name')
            ->orderByDesc('total_quantity')
            ->get();

        return view('pages.master_catalog', compact('masterItems'));
    })->name('master.catalog');

    // 4. صفحة الصنف المقسومة
    Route::get('/master-catalog/{itemNumber}', function ($itemNumber) {
        if (auth()->user()->role !== 'admin') abort(403);

        purgeCarAndYearKeywords();

        $master = DB::table('master_items')->where('item_number', $itemNumber)->first();

        $clientHoldings = DB::table('items')
            ->leftJoin('users', 'items.user_id', '=', 'users.id')
            ->select(
                'items.*',
                DB::raw("COALESCE(users.name, 'المدير العام') as client_name")
            )
            ->where('items.item_number', $itemNumber)
            ->get();

        $keywords = DB::table('item_keywords')
            ->where('item_number', $itemNumber)
            ->orderBy('id', 'asc')
            ->get();

        return view('pages.master_details', compact('itemNumber', 'master', 'clientHoldings', 'keywords'));
    })->name('master.item.details');

    Route::post('/master-catalog/{itemNumber}/keyword', function (Request $request, $itemNumber) {
        if (auth()->user()->role !== 'admin') abort(403);
        $word = trim($request->input('keyword'));
        if ($word !== '') {
            DB::table('item_keywords')->insertOrIgnore([
                'item_number' => $itemNumber,
                'keyword'     => $word,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
        return back()->with('success', 'تمت إضافة الكلمة المفتاحية بنجاح.');
    })->name('master.item.keyword.add');

    // 5. إعدادات السيارات والذكاء الاصطناعي
    Route::get('/cars-dictionary', function () {
        if (auth()->user()->role !== 'admin') {
            abort(403, 'هذه الصفحة مخصصة لمدير النظام فقط.');
        }

        $cars = DB::table('cars')->latest()->get();
        $yearPatterns = DB::table('year_patterns')->latest()->get();

        return view('pages.cars_dictionary', compact('cars', 'yearPatterns'));
    })->name('cars.dictionary');

    Route::post('/cars-dictionary/car', function (Request $request) {
        if (auth()->user()->role !== 'admin') abort(403);

        $request->validate([
            'car_name' => 'required|string|max:255',
            'category' => 'required|string|max:255',
        ]);

        DB::table('cars')->insert([
            'car_name'   => trim($request->car_name),
            'category'   => trim($request->category),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        purgeCarAndYearKeywords();

        return back()->with('success', 'تمت إضافة السيارة وفئتها بنجاح.');
    })->name('cars.store');

    Route::delete('/cars-dictionary/car/{id}', function ($id) {
        if (auth()->user()->role !== 'admin') abort(403);
        DB::table('cars')->where('id', $id)->delete();
        return back()->with('success', 'تم حذف السيارة ومرادفاتها بنجاح.');
    })->name('cars.destroy');

    Route::get('/cars-dictionary/car/{id}/aliases', function ($id) {
        if (auth()->user()->role !== 'admin') abort(403);

        $car = DB::table('cars')->where('id', $id)->first();
        if (!$car) abort(404);

        $aliases = DB::table('car_aliases')->where('car_id', $id)->get();

        return view('pages.car_aliases', compact('car', 'aliases'));
    })->name('cars.aliases');

    Route::post('/cars-dictionary/car/{id}/aliases', function (Request $request, $id) {
        if (auth()->user()->role !== 'admin') abort(403);

        DB::table('car_aliases')->insert([
            'car_id'     => $id,
            'alias'      => trim($request->alias),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        purgeCarAndYearKeywords();

        return back()->with('success', 'تمت إضافة المرادف بنجاح.');
    })->name('cars.aliases.store');

    Route::delete('/cars-dictionary/alias/{id}', function ($id) {
        if (auth()->user()->role !== 'admin') abort(403);
        DB::table('car_aliases')->where('id', $id)->delete();
        return back()->with('success', 'تم حذف المرادف.');
    })->name('cars.aliases.delete');

    Route::post('/cars-dictionary/year-pattern', function (Request $request) {
        if (auth()->user()->role !== 'admin') abort(403);

        DB::table('year_patterns')->insert([
            'pattern_example' => trim($request->pattern_example),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        purgeCarAndYearKeywords();

        return back()->with('success', 'تمت إضافة نمط سنة الصنع بنجاح.');
    })->name('year.pattern.store');

    Route::delete('/cars-dictionary/year-pattern/{id}', function ($id) {
        if (auth()->user()->role !== 'admin') abort(403);
        DB::table('year_patterns')->where('id', $id)->delete();
        return back()->with('success', 'تم الحذف.');
    })->name('year.pattern.destroy');

    // 6. مسارات الرد الآلي والذكاء الاصطناعي (مفصولة لكل عميل على حدة)
    Route::get('/ai-whatsapp', function (Request $request) {
        $userId = auth()->id();
        $customerMessage = (string) $request->query('message', '');
        $matchedItems = collect();

        if (trim($customerMessage) !== '') {
            $parsedTest = parseWhatsappText($customerMessage);
            $searchPart = $parsedTest['part'] ?: $customerMessage;
            $partWords = array_values(array_filter(explode(' ', $searchPart), fn($w) => mb_strlen($w) >= 2));

            $query = Item::where('user_id', $userId);

            if (!empty($parsedTest['car'])) {
                $c = $parsedTest['car'];
                $query->where(function ($q) use ($c) {
                    $q->where('detected_car_name', 'like', "%{$c}%")
                      ->orWhere('item_name', 'like', "%{$c}%");
                });
            }

            if (!empty($partWords)) {
                $query->where(function ($q) use ($partWords, $customerMessage) {
                    $q->orWhere('item_number', 'like', "%{$customerMessage}%")
                      ->orWhere('item_name', 'like', "%{$customerMessage}%");
                    foreach ($partWords as $pw) {
                        $q->orWhere('item_name', 'like', "%{$pw}%")
                          ->orWhere('item_number', 'like', "%{$pw}%");
                    }
                });
            }

            $candidates = $query->take(20)->get();

            if (!empty($parsedTest['year'])) {
                $y = $parsedTest['year'];
                $candidates = $candidates->filter(fn($i) => isYearMatchingItem($y, $i->detected_years, $i->item_name))->values();
            }

            $matchedItems = $candidates->take(10);
        }

        $botSettings = getUserBotSettings($userId);
        $activeSessions = DB::table('whatsapp_sessions')
            ->where('user_id', $userId)
            ->latest('updated_at')
            ->take(15)
            ->get();

        return view('pages.second', [
            'customerMessage' => $customerMessage,
            'matchedItems'    => $matchedItems,
            'botSettings'     => $botSettings,
            'activeSessions'  => $activeSessions,
        ]);
    })->name('pages.second');

    Route::post('/ai-whatsapp/settings', function (Request $request) {
        $userId = auth()->id();
        $fields = [
            'reply_available',
            'reply_unavailable',
            'reply_ask_car',
            'reply_ask_year',
            'whatsapp_api_url',
            'whatsapp_api_token',
            'whatsapp_session_id',
        ];

        foreach ($fields as $field) {
            if ($request->exists($field)) {
                DB::table('user_bot_settings')->updateOrInsert(
                    ['user_id' => $userId, 'key' => $field],
                    ['value' => trim((string) $request->input($field, ''))]
                );
            }
        }

        return back()->with('success', 'تم حفظ إعدادات الربط وقوالب الرد الآلي الخاصة بحسابك بنجاح.');
    })->name('pages.second.settings');

    Route::delete('/ai-whatsapp/sessions/{id}', function ($id) {
        DB::table('whatsapp_sessions')
            ->where('id', $id)
            ->where('user_id', auth()->id())
            ->delete();
        return back()->with('success', 'تم مسح جلسة ذاكرة العميل.');
    })->name('pages.second.session.delete');

    // 7. إعدادات الربط API والنسخ الاحتياطي
    Route::get('/page-third', function () {
        if (auth()->user()->role !== 'admin') abort(403);
        $webhookLogs = DB::table('webhook_logs')->latest()->take(20)->get();
        return view('pages.third', compact('webhookLogs'));
    })->name('pages.third');

    Route::get('/download-backup', function () {
        if (auth()->user()->role !== 'admin') abort(403);
        $path = config('database.connections.sqlite.database', database_path('database.sqlite'));
        return response()->download($path, 'database_backup_' . date('Y-m-d_H-i-s') . '.sqlite');
    })->name('backup.download');

    Route::post('/restore-backup', function (Request $request) {
        if (auth()->user()->role !== 'admin') abort(403);

        $request->validate([
            'backup_file' => 'required|file',
        ]);

        $file = $request->file('backup_file');
        $targetPath = config('database.connections.sqlite.database', database_path('database.sqlite'));

        DB::disconnect('sqlite');
        copy($file->getRealPath(), $targetPath);

        return back()->with('success', 'تمت استعادة النسخة الاحتياطية لقاعدة البيانات بنجاح!');
    })->name('backup.restore');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});


Gemini

محادثة جديدة
المكتبة
الصور
مجدوَل
تخصيص
الفيديوهات
دفتر ملاحظات جديد
تطوير زر الإكسل والرد الآلي
حل خطأ Composer أثناء النشر على Render
نظام إدارة قاموس السيارات وسنوات الصنع
حل خطأ Namespace في لارفيل
بدء المحادثة الصوتية والكتابية
تطوير نظام إدارة المخزون
تعديل مفتاح الاتصال لليمن
تحليل تقني لموقع بيتزا هاوس
بناء مساعد ذكي للمخزون
نظام إدارة المقاهي الشامل (Dwell Cafe) - PHP & React إليك الكود الكامل والمحدث بناءً على طلباتك بدقة
عندي مقهى اسمه Dwell Cafe ابغا اسوي له واجهه اعرض فيه المنتجات ويدخلون عليه العملاء يسجلون طلبياتهم
محادثة مع Gemini
لا تعدل اي شي غير المطلوب , اولا زر استيراد اكسل غير متاح في حساب العميل ابغاك تضيفه مع اضافه زي شريط او عداد للارقام اللتي يتم الرفع حتى يكتمل , ثانيا ابغا الرد الالي تبع الواتساب حاليا مرتبط من خلال ويب هوك لكن لا يقوم بالرد ابغا نضيف انه يرد ويضيف خانات في حال التوفر وفي حال عدم التوفر , وفي حال عدم معرفه نوع السيارة من كلام العميل يكون خانه مخصصه نكتب فيها انه يطلب من العميل ايش نوع او اسم السياره وكذلك سنه الصنع في حال عدم التعرف عليها يطلب منه المهم يكون فيه زي الذاكره تحفظ الكلام وترتبه حتى يكتمل المطلوب ,

تعديل المطلوب بالكامل دون المساس بأي أجزاء أخرى من النظام يتطلب تحديث ملف المسارات (routes/web.php) بالإضافة إلى تحديث ملفي الواجهة الخاصين بالمخزون وإعدادات الرد الآلي.   
PHP

1. ملف المسارات المحدث بالكامل (routes/web.php)
تم تحديث دالة استقبال الويب هوك handleWebhook ومسار الاستيراد /inventory/import ومسار إعدادات الرد الآلي /ai-whatsapp، مع إنشاء الجداول التلقائية للردود والذاكرة المؤقتة (إذا لم تكن موجودة مسبقاً في قاعدة البيانات) لضمان عمل الكود فوراً دون أخطاء:   
PHP
+ 1

PHP
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

// تهيئة جداول إعدادات البوت وذاكرة المحادثة تلقائياً في حال عدم وجودها
if (!Schema::hasTable('bot_settings')) {
    Schema::create('bot_settings', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->text('value')->nullable();
    });
}
if (!Schema::hasTable('whatsapp_sessions')) {
    Schema::create('whatsapp_sessions', function (Blueprint $table) {
        $table->id();
        $table->string('phone')->unique();
        $table->string('detected_car')->nullable();
        $table->string('detected_year')->nullable();
        $table->text('part_query')->nullable();
        $table->text('full_conversation')->nullable();
        $table->timestamps();
    });
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
 * دالة التحليل والربط الذكية بقلب النظام والسيارات والسنوات
 */
function syncItemToMasterCore(string $itemNumber, string $itemName, ?int $userId = null): void {
    $cleanNumber = strtoupper(trim($itemNumber));
    $cleanName = trim($itemName);

    if ($cleanNumber === '') return;

    $detectedCar = null;
    $detectedCategory = null;
    $detectedYears = null;

    $carsList = DB::table('cars')->get();
    foreach ($carsList as $car) {
        if (mb_stripos($cleanName, $car->car_name) !== false) {
            $detectedCar = $car->car_name;
            $detectedCategory = $car->category;
            break;
        }
        $aliases = DB::table('car_aliases')->where('car_id', $car->id)->pluck('alias')->toArray();
        foreach ($aliases as $alias) {
            if ($alias !== '' && mb_stripos($cleanName, $alias) !== false) {
                $detectedCar = $car->car_name;
                $detectedCategory = $car->category;
                break 2;
            }
        }
    }

    $patterns = DB::table('year_patterns')->pluck('pattern_example')->toArray();
    $wordsInName = preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $cleanName, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($patterns as $pattern) {
        if (mb_stripos($cleanName, $pattern) !== false) {
            $detectedYears = $pattern;
            break;
        }
    }
    if (!$detectedYears) {
        foreach ($wordsInName as $word) {
            if (preg_match('/^(?:(?:19|20)?\d{2})[\/-](?:(?:19|20)?\d{2})$/u', $word) || preg_match('/^\d{2}[\-\/]\d{2}$/u', $word)) {
                $detectedYears = $word;
                break;
            }
        }
    }

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

    $skipWords = [];
    if ($detectedCar) {
        $skipWords = array_merge($skipWords, preg_split('/[\s,\-]+/u', $detectedCar));
    }
    if ($detectedYears) {
        $skipWords[] = $detectedYears;
    }
    $skipWords = array_map('mb_strtolower', array_filter($skipWords));

    foreach ($wordsInName as $word) {
        $wordClean = trim($word);
        $wordLower = mb_strtolower($wordClean);

        if (mb_strlen($wordClean) >= 2 && !is_numeric($wordClean) && !in_array($wordLower, $skipWords)) {
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
    $allItems = DB::table('items')->orderBy('id', 'asc')->get();
    foreach ($allItems as $item) {
        syncItemToMasterCore($item->item_number, $item->item_name, $item->user_id);
    }
}

/**
 * دالة استخراج السيارة والسنة واسم القطعة من نص رسالة العميل
 */
function parseWhatsappText(string $text): array {
    $detectedCar = null;
    $detectedYear = null;
    $remainingText = $text;

    $carsList = DB::table('cars')->get();
    foreach ($carsList as $car) {
        if (mb_stripos($text, $car->car_name) !== false) {
            $detectedCar = $car->car_name;
            $remainingText = str_ireplace($car->car_name, '', $remainingText);
            break;
        }
        $aliases = DB::table('car_aliases')->where('car_id', $car->id)->pluck('alias')->toArray();
        foreach ($aliases as $alias) {
            if ($alias !== '' && mb_stripos($text, $alias) !== false) {
                $detectedCar = $car->car_name;
                $remainingText = str_ireplace($alias, '', $remainingText);
                break 2;
            }
        }
    }

    $patterns = DB::table('year_patterns')->pluck('pattern_example')->toArray();
    foreach ($patterns as $pattern) {
        if (mb_stripos($text, $pattern) !== false) {
            $detectedYear = $pattern;
            $remainingText = str_ireplace($pattern, '', $remainingText);
            break;
        }
    }

    if (!$detectedYear) {
        if (preg_match('/\b(19\d{2}|20\d{2})\b/u', $text, $matches)) {
            $detectedYear = $matches[1];
            $remainingText = str_replace($matches[1], '', $remainingText);
        } elseif (preg_match('/\b(\d{2}[\-\/]\d{2})\b/u', $text, $matches)) {
            $detectedYear = $matches[1];
            $remainingText = str_replace($matches[1], '', $remainingText);
        }
    }

    // تنظيف النص المتبقي لاستخراج اسم القطعة الصافي
    $stopWords = ['السلام', 'عليكم', 'ابغى', 'ابغا', 'ابي', 'عندكم', 'موجود', 'احصل', 'سيارة', 'سياره', 'موديل', 'سنة', 'سنه', 'لو', 'سمحت', 'ياغالي', 'بكم', 'هل', 'فيه', 'يتوفر'];
    $words = preg_split('/[\s,\-\+\/\(\)\*\.]+/u', trim($remainingText), -1, PREG_SPLIT_NO_EMPTY);
    $cleanWords = [];
    foreach ($words as $w) {
        if (!in_array(mb_strtolower($w), $stopWords) && mb_strlen($w) >= 2) {
            $cleanWords[] = $w;
        }
    }

    return [
        'car'  => $detectedCar,
        'year' => $detectedYear,
        'part' => implode(' ', $cleanWords),
    ];
}

/**
 * دالة إرسال رسالة واتساب والرد التلقائي عبر الويب هوك مع الذاكرة
 */
function handleWebhook(Request $request) {
    $payload = $request->all();

    DB::table('webhook_logs')->insert([
        'event_type' => $request->input('event', 'unknown'),
        'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // تجاهل الرسائل الصادرة من البوت نفسه (fromMe) لمنع التكرار اللانهائي
    $fromMe = data_get($payload, 'data.fromMe', data_get($payload, 'fromMe', false));
    if ($fromMe === true || $fromMe === 'true' || $fromMe === 1) {
        return response()->json(['status' => 'ignored_from_me'], 200);
    }

    // استخراج رقم المرسل ونص الرسالة من مختلف صيغ الويب هوك (GreatWhats / UltraMsg / WhatsApp API)
    $sender = data_get($payload, 'data.from')
           ?? data_get($payload, 'from')
           ?? data_get($payload, 'sender')
           ?? data_get($payload, 'phone')
           ?? data_get($payload, 'data.key.remoteJid');

    $messageText = data_get($payload, 'data.body')
                ?? data_get($payload, 'body')
                ?? data_get($payload, 'message')
                ?? data_get($payload, 'text')
                ?? data_get($payload, 'data.message.conversation')
                ?? data_get($payload, 'data.message.extendedTextMessage.text');

    if (!$sender || !$messageText || !is_string($messageText)) {
        return response()->json(['status' => 'no_message_detected'], 200);
    }

    $cleanPhone = preg_replace('/@.*$/', '', $sender);
    $cleanPhone = preg_replace('/\D/', '', $cleanPhone);
    $messageText = trim($messageText);

    if ($cleanPhone === '' || $messageText === '') {
        return response()->json(['status' => 'empty'], 200);
    }

    // جلب إعدادات الردود المخصصة
    $settings = DB::table('bot_settings')->pluck('value', 'key')->toArray();
    $msgAskCar      = $settings['reply_ask_car'] ?? 'حياك الله، فضلاً ما هو نوع أو اسم السيارة؟';
    $msgAskYear     = $settings['reply_ask_year'] ?? 'فضلاً كم موديل (سنة صنع) السيارة؟';
    $msgAvailable   = $settings['reply_available'] ?? "القطعة متوفرة لدينا ✅\nتفاصيل القطعة:\n{items}";
    $msgUnavailable = $settings['reply_unavailable'] ?? 'نعتذر منك، القطعة المطلوبة غير متوفرة حالياً في المخزون ❌';

    // استرجاع ذاكرة المحادثة للعميل (تنتهي صلاحية الذاكرة بعد ساعتين من عدم التفاعل)
    $session = DB::table('whatsapp_sessions')->where('phone', $cleanPhone)->first();
    if ($session && now()->diffInMinutes($session->updated_at) > 120) {
        DB::table('whatsapp_sessions')->where('phone', $cleanPhone)->delete();
        $session = null;
    }

    // تحليل الرسالة الجديدة ودمجها مع الذاكرة السابقة
    $parsed = parseWhatsappText($messageText);

    $car  = $parsed['car']  ?: ($session->detected_car ?? null);
    $year = $parsed['year'] ?: ($session->detected_year ?? null);
    $part = $parsed['part'] ?: ($session->part_query ?? null);

    // إذا كان العميل قد ذكر جزءاً من اسم القطعة سابقاً وأضاف كلاماً جديداً للقطعة
    if (!empty($session->part_query) && !empty($parsed['part']) && mb_stripos($session->part_query, $parsed['part']) === false) {
        $part = trim($session->part_query . ' ' . $parsed['part']);
    }

    $fullConv = trim(($session->full_conversation ?? '') . ' ' . $messageText);

    // تحديث أو إنشاء ذاكرة العميل
    DB::table('whatsapp_sessions')->updateOrInsert(
        ['phone' => $cleanPhone],
        [
            'detected_car'      => $car,
            'detected_year'     => $year,
            'part_query'        => $part ?: $messageText,
            'full_conversation' => $fullConv,
            'updated_at'        => now(),
            'created_at'        => $session->created_at ?? now(),
        ]
    );

    // التحقق من اكتمال أركان الطلب: 1. نوع السيارة -> 2. سنة الصنع -> 3. البحث والرد
    if (!$car) {
        $replyText = $msgAskCar;
    } elseif (!$year) {
        $replyText = str_replace('{car}', $car, $msgAskYear);
    } else {
        // اكتملت البيانات (السيارة + السنة + القطعة) -> نبحث في المخزون
        $searchPart = $part ?: $fullConv;
        $partWords = array_filter(explode(' ', $searchPart), fn($w) => mb_strlen($w) >= 2);

        $matchedNumbers = [];
        if (!empty($partWords)) {
            $kwQuery = DB::table('item_keywords');
            foreach ($partWords as $pw) {
                $kwQuery->orWhere('keyword', 'like', "%{$pw}%");
            }
            $matchedNumbers = $kwQuery->pluck('item_number')->toArray();
        }

        $itemsQuery = Item::where('quantity', '>', 0)
            ->where(function ($q) use ($car) {
                $q->where('detected_car_name', 'like', "%{$car}%")
                  ->orWhere('item_name', 'like', "%{$car}%");
            });

        // مطابقة سنة الصنع (أو آخر رقمين منها مثل 18 لـ 2018)
        $shortYear = strlen($year) === 4 ? substr($year, 2, 2) : $year;
        $itemsQuery->where(function ($q) use ($year, $shortYear) {
            $q->where('detected_years', 'like', "%{$year}%")
              ->orWhere('detected_years', 'like', "%{$shortYear}%")
              ->orWhere('item_name', 'like', "%{$year}%")
              ->orWhere('item_name', 'like', "%{$shortYear}%");
        });

        // مطابقة اسم القطعة أو الكلمات المفتاحية
        if (!empty($partWords) || !empty($matchedNumbers)) {
            $itemsQuery->where(function ($q) use ($partWords, $matchedNumbers) {
                if (!empty($matchedNumbers)) {
                    $q->orWhereIn('item_number', $matchedNumbers);
                }
                foreach ($partWords as $pw) {
                    $q->orWhere('item_name', 'like', "%{$pw}%")
                      ->orWhere('item_number', 'like', "%{$pw}%");
                }
            });
        }

        $foundItems = $itemsQuery->take(5)->get();

        if ($foundItems->count() > 0) {
            $itemsListText = $foundItems->map(function ($i) {
                return "- {$i->item_name} (رقم: {$i->item_number}) | السعر: {$i->price} ريال";
            })->implode("\n");

            $replyText = str_replace(
                ['{items}', '{car}', '{year}', '{part}'],
                [$itemsListText, $car, $year, $searchPart],
                $msgAvailable
            );
        } else {
            $replyText = str_replace(
                ['{car}', '{year}', '{part}'],
                [$car, $year, $searchPart],
                $msgUnavailable
            );
        }

        // مسح الذاكرة بعد إتمام الرد النهائي بنجاح ليكون جاهزاً لطلب جديد
        DB::table('whatsapp_sessions')->where('phone', $cleanPhone)->delete();
    }

    // إرسال الرد عبر بوابة الواتساب إذا كان رابط الـ API مضافاً في الإعدادات
    $apiUrl   = $settings['whatsapp_api_url'] ?? '';
    $apiToken = $settings['whatsapp_api_token'] ?? '';

    if (!empty($apiUrl)) {
        try {
            Http::withoutVerifying()->timeout(10)->post($apiUrl, [
                'token'   => $apiToken,
                'api_key' => $apiToken,
                'to'      => $cleanPhone,
                'phone'   => $cleanPhone,
                'number'  => $cleanPhone,
                'body'    => $replyText,
                'message' => $replyText,
                'text'    => $replyText,
            ]);
        } catch (\Throwable $e) {
            // تجاهل خطأ الاتصال الخارجي لضمان إرجاع 200 للويب هوك
        }
    }

    return response()->json([
        'status'  => 'success',
        'reply'   => $replyText,
        'message' => $replyText,
        'memory'  => [
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

    // الحالة الأولى: إذا أدخل رقم جوال (10 أرقام تبدأ بـ 0)
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

        // إذا لم يدخل المستخدم كلمة المرور/الكود بعد (الخطوة الأولى: طلب الكود)
        if (empty($password)) {
            $user->otp_code = (string) random_int(1000, 9999);
            $user->save();

            return back()->withInput()
                         ->with('phone_for_otp', $loginInput)
                         ->with('success', $user->otp_code);
        }

        // الخطوة الثانية: التحقق من كود الـ OTP المدخل
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
    }

    // الحالة الثانية: تسجيل دخول الإدارة (اسم المستخدم/البريد وكلمة المرور)
    else {
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

    // 1. المخزون
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/', function (Request $request) {
            $user = auth()->user();
            $targetUserId = $user->id;

            if ($user->role === 'admin' && $request->filled('client_id')) {
                $targetUserId = (int) $request->query('client_id');
            }

            $query = Item::query();
            if ($user->role !== 'admin' || $request->filled('client_id')) {
                $query->where('user_id', $targetUserId);
            }

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

            $viewingClient = $request->filled('client_id') ? User::find($targetUserId) : null;

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
            $item = Item::create([
                'user_id'     => $userId,
                'item_number' => strtoupper(trim($data['item_number'])),
                'item_name'   => trim($data['item_name']),
                'quantity'    => $data['quantity'],
                'price'       => $data['price'],
            ]);

            syncItemToMasterCore($item->item_number, $item->item_name, $userId);

            return back()->with('success', 'تمت إضافة الصنف بنجاح.');
        })->name('store');

        // مسار الاستيراد يدعم الرفع المباشر أو الرفع المتدرج بالعداد (Batch Import) للعميل والمدير
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

                    $item = Item::updateOrCreate(
                        ['user_id' => $userId, 'item_number' => $itemNumber],
                        ['item_name' => $itemName, 'quantity' => $quantity, 'price' => $price]
                    );

                    syncItemToMasterCore($item->item_number, $item->item_name, $userId);
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

        return back()->with('success', 'تمت إضافة نمط سنة الصنع بنجاح.');
    })->name('year.pattern.store');

    Route::delete('/cars-dictionary/year-pattern/{id}', function ($id) {
        if (auth()->user()->role !== 'admin') abort(403);
        DB::table('year_patterns')->where('id', $id)->delete();
        return back()->with('success', 'تم الحذف.');
    })->name('year.pattern.destroy');

    // 6. مسارات الرد الآلي والذكاء الاصطناعي وإعدادات قوالب الرد والذاكرة
    Route::get('/ai-whatsapp', function (Request $request) {
        $customerMessage = (string) $request->query('message', '');
        $matchedItems = collect();

        if (trim($customerMessage) !== '') {
            $matchedNumbers = DB::table('item_keywords')
                ->where('keyword', 'like', "%{$customerMessage}%")
                ->pluck('item_number')
                ->toArray();

            $matchedItems = Item::whereIn('item_number', $matchedNumbers)
                ->orWhere('item_number', 'like', "%{$customerMessage}%")
                ->orWhere('item_name', 'like', "%{$customerMessage}%")
                ->take(10)
                ->get();
        }

        $botSettings = DB::table('bot_settings')->pluck('value', 'key')->toArray();
        $activeSessions = DB::table('whatsapp_sessions')->latest('updated_at')->take(15)->get();

        return view('pages.second', [
            'customerMessage' => $customerMessage,
            'matchedItems'    => $matchedItems,
            'botSettings'     => $botSettings,
            'activeSessions'  => $activeSessions,
        ]);
    })->name('pages.second');

    Route::post('/ai-whatsapp/settings', function (Request $request) {
        $fields = [
            'reply_available',
            'reply_unavailable',
            'reply_ask_car',
            'reply_ask_year',
            'whatsapp_api_url',
            'whatsapp_api_token',
        ];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                DB::table('bot_settings')->updateOrInsert(
                    ['key' => $field],
                    ['value' => trim((string) $request->input($field, ''))]
                );
            }
        }

        return back()->with('success', 'تم حفظ إعدادات الرد الآلي وقوالب الرسائل بنجاح.');
    })->name('pages.second.settings');

    Route::delete('/ai-whatsapp/sessions/{id}', function ($id) {
        DB::table('whatsapp_sessions')->where('id', $id)->delete();
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

        // إغلاق الاتصال بقاعدة البيانات مؤقتاً واستبدالها بالملف المرفوع
        DB::disconnect('sqlite');
        copy($file->getRealPath(), $targetPath);

        return back()->with('success', 'تمت استعادة النسخة الاحتياطية لقاعدة البيانات بنجاح!');
    })->name('backup.restore');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
2. إضافة زر استيراد إكسل والعداد التفاعلي في حساب العميل (resources/views/inventory/index.blade.php)
إظهار الزر للعميل: إذا كان زر استيراد الإكسل محاطاً بشرط @if(auth()->user()->role === 'admin') في ملف resources/views/inventory/index.blade.php، قم بإزالة هذا الشرط فقط ليظهر الزر للعميل والمدير معاً.

كود نموذج الاستيراد مع شريط التقدم والعداد (Progress Bar & Counter): ضع هذا الكود مكان نموذج استيراد الإكسل في resources/views/inventory/index.blade.php ليقوم بقراءة ملف الإكسل ورفعه على دفعات مع إظهار عداد حي للأرقام التي يتم رفعها (تم رفع X من أصل Y صنف):

HTML
<!-- قسم استيراد ملف الإكسل (متاح للعميل والمدير مع شريط تقدم وعداد) -->
<div class="bg-white p-4 rounded-xl shadow-sm border border-gray-200 mb-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h3 class="font-bold text-gray-800 text-base">استيراد الأصناف من ملف Excel</h3>
            <p class="text-xs text-gray-500">الأعمدة المطلوبة بالترتيب: رقم الصنف، اسم الصنف، الكمية، السعر</p>
        </div>

        <div class="flex items-center gap-3">
            <input type="file" id="excelFileInput" accept=".xlsx,.xls,.csv" class="block text-sm text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100" />
            <button type="button" id="startImportBtn" onclick="startExcelBatchImport()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 px-5 rounded-lg text-sm transition">
                استيراد إكسل
            </button>
        </div>
    </div>

    <!-- شريط التقدم وعداد الأرقام المرفوعة -->
    <div id="importProgressContainer" class="hidden mt-4 pt-3 border-t border-gray-100">
        <div class="flex justify-between items-center mb-1 text-sm font-semibold">
            <span id="importStatusText" class="text-emerald-700">جاري رفع ومعالجة الأصناف...</span>
            <span class="text-gray-800 bg-gray-100 px-3 py-0.5 rounded-full">
                تم رفع <span id="importedCounter" class="text-emerald-600 font-extrabold">0</span> من <span id="totalCounter" class="font-extrabold">0</span>
            </span>
        </div>
        <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
            <div id="importProgressBar" class="bg-emerald-600 h-3 rounded-full transition-all duration-300" style="width: 0%"></div>
        </div>
    </div>
</div>

<!-- مكتبة قراءة ملفات الإكسل في المتصفح لحساب العدد الدقيق وتشغيل العداد -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
async function startExcelBatchImport() {
    const fileInput = document.getElementById('excelFileInput');
    if (!fileInput.files.length) {
        alert('يرجى اختيار ملف الإكسل أولاً.');
        return;
    }

    const file = fileInput.files[0];
    const btn = document.getElementById('startImportBtn');
    const progressContainer = document.getElementById('importProgressContainer');
    const progressBar = document.getElementById('importProgressBar');
    const importedCounter = document.getElementById('importedCounter');
    const totalCounter = document.getElementById('totalCounter');
    const statusText = document.getElementById('importStatusText');

    btn.disabled = true;
    btn.classList.add('opacity-50', 'cursor-not-allowed');
    progressContainer.classList.remove('hidden');

    const data = await file.arrayBuffer();
    const workbook = XLSX.read(data, { type: 'array' });
    const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
    const rows = XLSX.utils.sheet_to_json(firstSheet, { header: 1, defval: '' });

    // استخراج الصفوف الفعلية وتخطي صف العناوين إن وجد
    const validItems = [];
    for (let i = 0; i < rows.length; i++) {
        const r = rows[i];
        if (!r || r.length < 2) continue;

        const col0 = String(r[0] ?? '').trim();
        const col1 = String(r[1] ?? '').trim();

        // تخطي صف العنوان الأول إذا كان نصياً مثل "رقم الصنف" أو "item_number"
        if (i === 0 && (col0.includes('رقم') || col0.toLowerCase().includes('number') || col0.toLowerCase().includes('code'))) {
            continue;
        }
        if (col0 === '' || col1 === '') continue;

        validItems.push({
            item_number: col0,
            item_name: col1,
            quantity: parseInt(r[2]) || 0,
            price: parseFloat(r[3]) || 0
        });
    }

    const total = validItems.length;
    if (total === 0) {
        alert('لم يتم العثور على أصناف صالحة داخل الملف.');
        btn.disabled = false;
        btn.classList.remove('opacity-50', 'cursor-not-allowed');
        return;
    }

    totalCounter.innerText = total;
    importedCounter.innerText = 0;
    progressBar.style.width = '0%';

    const batchSize = 25; // رفع 25 صنف في كل دفعة لتحديث العداد بسلاسة
    let uploaded = 0;

    for (let i = 0; i < total; i += batchSize) {
        const chunk = validItems.slice(i, i + batchSize);

        try {
            const response = await fetch("{{ route('inventory.import') }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ items_batch: chunk })
            });

            if (response.ok) {
                uploaded += chunk.length;
                if (uploaded > total) uploaded = total;
                const percent = Math.round((uploaded / total) * 100);

                importedCounter.innerText = uploaded;
                progressBar.style.width = percent + '%';
            }
        } catch (err) {
            console.error(err);
        }
    }

    statusText.innerText = 'اكتمل استيراد جميع الأصناف بنجاح! جاري تحديث الجدول...';
    setTimeout(() => window.location.reload(), 1000);
}
</script>
3. خانات الرد الآلي والذاكرة في صفحة الواتساب (resources/views/pages/second.blade.php)
أضف هذا القسم في ملف resources/views/pages/second.blade.php لتتمكن من التحكم في نصوص الردود (في حال التوفر، عدم التوفر، طلب اسم السيارة، طلب سنة الصنع، ورابط بوابة الإرسال) ومشاهدة الذاكرة الحية للمحادثات الجارية:

HTML
<!-- إعدادات قوالب الرد الآلي وذاكرة المحادثة للواتساب -->
<div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 mb-6">
    <h2 class="text-lg font-bold text-gray-800 mb-4">إعدادات الرد الآلي الذكي والذاكرة (WhatsApp Webhook)</h2>

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
                <label class="block text-sm font-bold text-gray-700 mb-1">رد البوت في حال توفر القطعة ✅</label>
                <textarea name="reply_available" rows="3" class="w-full border-gray-300 rounded-lg text-sm p-2.5 border focus:ring-emerald-500 focus:border-emerald-500">{{ $botSettings['reply_available'] ?? "القطعة متوفرة لدينا ✅\nتفاصيل القطعة:\n{items}" }}</textarea>
                <span class="text-xs text-gray-500">المتغيرات المتاحة: <code>{items}</code> لقائمة القطع والأسعار، <code>{car}</code> للسيارة، <code>{year}</code> للموديل</span>
            </div>

            <!-- خانة الرد في حال عدم التوفر -->
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">رد البوت في حال عدم توفر القطعة ❌</label>
                <textarea name="reply_unavailable" rows="3" class="w-full border-gray-300 rounded-lg text-sm p-2.5 border focus:ring-emerald-500 focus:border-emerald-500">{{ $botSettings['reply_unavailable'] ?? "نعتذر منك، القطعة المطلوبة لسيارة {car} موديل {year} غير متوفرة حالياً في المخزون ❌" }}</textarea>
                <span class="text-xs text-gray-500">المتغيرات المتاحة: <code>{car}</code> للسيارة، <code>{year}</code> للموديل، <code>{part}</code> للقطعة</span>
            </div>

            <!-- خانة السؤال عند عدم معرفة نوع السيارة -->
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">رد البوت عند عدم التعرف على نوع/اسم السيارة 🚗</label>
                <textarea name="reply_ask_car" rows="2" class="w-full border-gray-300 rounded-lg text-sm p-2.5 border focus:ring-emerald-500 focus:border-emerald-500">{{ $botSettings['reply_ask_car'] ?? 'حياك الله، فضلاً ما هو نوع أو اسم السيارة؟' }}</textarea>
            </div>

            <!-- خانة السؤال عند عدم معرفة سنة الصنع -->
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">رد البوت عند عدم التعرف على سنة الصنع 📅</label>
                <textarea name="reply_ask_year" rows="2" class="w-full border-gray-300 rounded-lg text-sm p-2.5 border focus:ring-emerald-500 focus:border-emerald-500">{{ $botSettings['reply_ask_year'] ?? 'فضلاً كم موديل (سنة صنع) السيارة {car}؟' }}</textarea>
            </div>
        </div>

        <!-- إعدادات رابط الإرسال للواتساب (اختياري في حال كان المزود يحتاج طلب HTTP لإرسال الرد) -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-3 border-t border-gray-100">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">رابط إرسال رسائل الواتساب (API Send URL)</label>
                <input type="text" name="whatsapp_api_url" value="{{ $botSettings['whatsapp_api_url'] ?? '' }}" placeholder="مثال: https://api.greatwhats.com/send" class="w-full border-gray-300 rounded-lg text-sm p-2.5 border" />
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">مفتاح الربط (API Token / Key)</label>
                <input type="text" name="whatsapp_api_token" value="{{ $botSettings['whatsapp_api_token'] ?? '' }}" placeholder="أدخل التوكن هنا" class="w-full border-gray-300 rounded-lg text-sm p-2.5 border" />
            </div>
        </div>

        <div class="pt-2">
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 px-6 rounded-lg text-sm transition">
                حفظ إعدادات الرد الآلي
            </button>
        </div>
    </form>
</div>

<!-- جدول ذاكرة المحادثات النشطة (يوضح كيف يحفظ البوت كلام العميل ويرتبه حتى يكتمل الطلب) -->
<div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 mb-6">
    <h3 class="text-base font-bold text-gray-800 mb-3">ذاكرة المحادثات الجارية (بانتظار استكمال بيانات العميل)</h3>
    <div class="overflow-x-auto">
        <table class="w-full text-sm text-right border-collapse">
            <thead>
                <tr class="bg-gray-50 border-b text-gray-700">
                    <th class="p-3">رقم العميل</th>
                    <th class="p-3">السيارة المكتشفة</th>
                    <th class="p-3">سنة الصنع</th>
                    <th class="p-3">القطعة المطلوبة</th>
                    <th class="p-3">إجمالي المحادثة المحفوظة</th>
                    <th class="p-3">إجراء</th>
                </tr>
            </thead>
            <tbody>
                @forelse($activeSessions ?? [] as $sess)
                    <tr class="border-b hover:bg-gray-50">
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
                        <td class="p-3 text-gray-500 text-xs">{{ $sess->full_conversation }}</td>
                        <td class="p-3">
                            <form action="{{ route('pages.second.session.delete', $sess->id) }}" method="POST">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline text-xs font-bold">مسح الذاكرة</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="p-4 text-center text-gray-400">لا توجد محادثات معلقة في الذاكرة حالياً.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
راجعه ووعدل المطلوب وارسله لي ورجاء ارسله لي ملف جاهز لا تظهر في الشاشه التعديلات لان النظام يعلق

ملفاتك المعدلة جاهزة للتحميل مباشرة دون عرض الأكواد على الشاشة:

رمز PHP
index.blade
 PHP 
رمز PHP
web
 PHP 
index.blade.php: ضعه في المسار resources/views/inventory/index.blade.php (تم تفعيل زر استيراد الإكسل لحساب العميل والمدير مع شريط التقدم وعداد الأرقام المرفوعة داخل نفس نافذة الاستيراد).   
PHP

web.php: ضعه في المسار routes/web.php (يدعم عداد الاستيراد التدريجي، والرد التلقائي للواتساب عبر الويب هوك مع حفظ وترتيب ذاكرة المحادثة للعميل حتى تكتمل بيانات السيارة وسنة الصنع والقطعة).   
PHP

إذا أردت تعديل صفحة الرد الآلي (resources/views/pages/second.blade.php) أيضاً وإرسالها كملف جاهز، أرفق لي الملف الحالي وسأقوم بتجهيزه لك بنفس الطريقة فوراً.





‫Gemini هو نموذج ذكاء اصطناعي وقد يعرض معلومات خاطئة.

تمت معالجة وتحليل البيانات في الرمز البرمجي
@extends('layouts.dashboard')

@section('title', 'لوحة التحكم - إدارة المخزون')

@section('content')
<div class="space-y-6">

    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <div>
            <h2 class="text-2xl font-bold text-slate-800">
                المخزون 
                @if(isset($viewingClient))
                    <span class="text-base text-emerald-600 font-semibold">(مخزون العميل: {{ $viewingClient->name }})</span>
                @else
                    <span class="text-base text-slate-500 font-semibold">({{ auth()->user()->role === 'admin' ? 'مخزونك العام' : ' ' }})</span>
                @endif
            </h2>
            <p class="text-sm text-slate-500 mt-1">اكتب في خانة البحث ليتم الفرز تلقائيًا دون الحاجة للضغط على Enter.</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            @if(isset($viewingClient))
                <a href="{{ route('clients.index') }}" class="bg-slate-200 hover:bg-slate-300 text-slate-800 px-4 py-2 rounded-lg text-sm font-semibold transition">
                    🔙 عودة لقائمة العملاء
                </a>
            @endif

            <div class="relative">
                <input type="text" id="live-search" value="{{ request('search') }}"
                       placeholder="ابحث برقم أو اسم الصنف تلقائيًا..."
                       class="w-72 border border-slate-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-emerald-500">
            </div>

            @if(!isset($viewingClient))
                <button type="button"
                        onclick="document.getElementById('excel-modal').classList.remove('hidden')"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">
                    📥 استيراد من Excel
                </button>
            @endif
        </div>
    </div>

    <!-- نموذج إضافة صنف جديد -->
    @if(auth()->user()->role === 'admin' || !isset($viewingClient))
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <h3 class="text-base font-bold text-slate-700 mb-4">إضافة صنف جديد</h3>

        <form method="POST" action="{{ route('inventory.store') }}" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            @csrf
            <input type="text" name="item_number" value="{{ old('item_number') }}" placeholder="رقم الصنف (فريد)" required
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm uppercase">

            <input type="text" name="item_name" value="{{ old('item_name') }}" placeholder="اسم الصنف / الترجمة" required
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm">

            <input type="number" name="quantity" value="{{ old('quantity') }}" placeholder="الكمية" min="0" required
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm">

            <input type="number" step="0.01" name="price" value="{{ old('price') }}" placeholder="السعر" min="0" required
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm">

            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2 px-4 rounded-lg text-sm transition">
                + حفظ الصنف
            </button>
        </form>
    </div>
    @endif

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-600 text-sm">
                        <th class="py-4 px-6 font-bold">رقم الصنف </th>
                        <th class="py-4 px-6 font-bold">اسم الصنف</th>
                        <th class="py-4 px-6 font-bold">الكمية</th>
                        <th class="py-4 px-6 font-bold">السعر</th>
                        <th class="py-4 px-6 font-bold">الإجراءات</th>
                    </tr>
                </thead>

                <tbody id="inventory-table-body" class="divide-y divide-slate-200 text-sm">
                    @include('inventory.partials.table_rows', ['items' => $items])
                </tbody>
            </table>
        </div>

        @if(isset($items) && method_exists($items, 'hasPages') && $items->hasPages())
            <div class="p-4 border-t border-slate-200">
                {{ $items->withQueryString()->links() }}
            </div>
        @endif
    </div>
</div>

<!-- نافذة استيراد الإكسل (متاحة للعميل والمدير مع شريط التقدم والعداد) -->
<div id="excel-modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-lg p-6 w-full max-w-md space-y-4">
        <h3 class="text-lg font-bold text-slate-800">استيراد الأصناف من ملف Excel</h3>

        <p class="text-xs text-slate-500">
            تأكد أن أعمدة الملف بالترتيب التالي:
            <strong class="text-slate-700">رقم الصنف، اسم الصنف، الكمية، السعر.</strong>
        </p>

        <form id="excel-import-form" method="POST" action="{{ route('inventory.import') }}" enctype="multipart/form-data" class="space-y-4" onsubmit="return handleExcelSubmit(event)">
            @csrf
            <input type="file" id="excel-file-input" name="excel_file" accept=".xlsx,.xls,.csv" required
                   class="w-full border border-slate-300 rounded-lg p-2 text-sm">

            <!-- شريط التقدم وعداد الأرقام المرفوعة -->
            <div id="import-progress-box" class="hidden space-y-2 pt-2 border-t border-slate-100">
                <div class="flex justify-between items-center text-xs font-bold">
                    <span id="import-status-label" class="text-indigo-600">جاري رفع الأصناف...</span>
                    <span class="bg-slate-100 text-slate-800 px-2.5 py-1 rounded-full">
                        تم رفع <span id="import-done-count" class="text-indigo-600">0</span> من <span id="import-total-count">0</span>
                    </span>
                </div>
                <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden">
                    <div id="import-progress-bar" class="bg-indigo-600 h-3 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" id="cancel-import-btn" onclick="document.getElementById('excel-modal').classList.add('hidden')"
                        class="px-4 py-2 bg-slate-200 rounded-lg text-sm font-semibold">
                    إلغاء
                </button>

                <button type="submit" id="submit-import-btn" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold">
                    بدء الاستيراد
                </button>
            </div>
        </form>
    </div>
</div>

<!-- نافذة التعديل (رقم الصنف مقفول readonly لا يمكن تعديله نهائياً من قبل العميل) -->
<div id="edit-modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-lg p-6 w-full max-w-md space-y-4">
        <h3 class="text-lg font-bold text-slate-800">تعديل بيانات الصنف</h3>
        <p class="text-xs text-rose-600 font-semibold">ملاحظة: رقم الصنف محمي وثابت ولا يمكن تعديله بعد إنشائه.</p>

        <form id="edit-form" method="POST" class="space-y-3">
            @csrf
            @method('PUT')

            <div>
                <label for="edit-number" class="text-xs font-semibold text-slate-600">رقم الصنف (غير قابل للتعديل)</label>
                <input type="text" id="edit-number" name="item_number" readonly
                       class="w-full border border-slate-300 bg-slate-100 text-slate-500 cursor-not-allowed rounded-lg px-3 py-2 text-sm mt-1 font-mono">
            </div>

            <div>
                <label for="edit-name" class="text-xs font-semibold text-slate-600">اسم الصنف</label>
                <input type="text" id="edit-name" name="item_name" required
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mt-1">
            </div>

            <div>
                <label for="edit-quantity" class="text-xs font-semibold text-slate-600">الكمية</label>
                <input type="number" id="edit-quantity" name="quantity" min="0" required
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mt-1">
            </div>

            <div>
                <label for="edit-price" class="text-xs font-semibold text-slate-600">السعر</label>
                <input type="number" step="0.01" id="edit-price" name="price" min="0" required
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mt-1">
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('edit-modal').classList.add('hidden')"
                        class="px-4 py-2 bg-slate-200 rounded-lg text-sm font-semibold">
                    إلغاء
                </button>

                <button type="submit" class="px-4 py-2 bg-amber-500 text-white rounded-lg text-sm font-semibold">
                    حفظ التعديلات
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
    let searchTimer = null;
    const searchInput = document.getElementById('live-search');
    const tableBody = document.getElementById('inventory-table-body');

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            const query = this.value;

            searchTimer = setTimeout(() => {
                fetch(`{{ route('inventory.index') }}?search=${encodeURIComponent(query)}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                })
                .then(response => {
                    if (!response.ok) throw new Error('تعذر تحميل النتائج');
                    return response.json();
                })
                .then(data => {
                    if (tableBody && data.html) {
                        tableBody.innerHTML = data.html;
                    }
                })
                .catch(error => console.error(error));
            }, 250);
        });
    }

    function openEditModal(id, number, name, quantity, price) {
        document.getElementById('edit-form').action = `/inventory/${id}`;
        document.getElementById('edit-number').value = number;
        document.getElementById('edit-name').value = name;
        document.getElementById('edit-quantity').value = quantity;
        document.getElementById('edit-price').value = price;
        document.getElementById('edit-modal').classList.remove('hidden');
    }

    async function handleExcelSubmit(e) {
        if (typeof XLSX === 'undefined') {
            return true; // في حال عدم الاتصال بالإنترنت لتحميل مكتبة القراءة، يرفع بالطريقة العادية
        }

        e.preventDefault();
        const fileInput = document.getElementById('excel-file-input');
        if (!fileInput.files.length) return false;

        const file = fileInput.files[0];
        const submitBtn = document.getElementById('submit-import-btn');
        const cancelBtn = document.getElementById('cancel-import-btn');
        const progressBox = document.getElementById('import-progress-box');
        const progressBar = document.getElementById('import-progress-bar');
        const doneCountEl = document.getElementById('import-done-count');
        const totalCountEl = document.getElementById('import-total-count');
        const statusLabel = document.getElementById('import-status-label');

        submitBtn.disabled = true;
        cancelBtn.disabled = true;
        submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
        progressBox.classList.remove('hidden');

        try {
            const buffer = await file.arrayBuffer();
            const workbook = XLSX.read(buffer, { type: 'array' });
            const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
            const rows = XLSX.utils.sheet_to_json(firstSheet, { header: 1, defval: '' });

            const validItems = [];
            for (let i = 0; i < rows.length; i++) {
                const r = rows[i];
                if (!r || r.length < 2) continue;

                const col0 = String(r[0] ?? '').trim();
                const col1 = String(r[1] ?? '').trim();

                if (i === 0 && (col0.includes('رقم') || col0.toLowerCase().includes('number') || col0.toLowerCase().includes('code'))) {
                    continue;
                }
                if (col0 === '' || col1 === '') continue;

                validItems.push({
                    item_number: col0,
                    item_name: col1,
                    quantity: parseInt(r[2]) || 0,
                    price: parseFloat(r[3]) || 0
                });
            }

            const total = validItems.length;
            if (total === 0) {
                alert('لم يتم العثور على أصناف صالحة في الملف.');
                submitBtn.disabled = false;
                cancelBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                return false;
            }

            totalCountEl.innerText = total;
            doneCountEl.innerText = 0;
            progressBar.style.width = '0%';

            const batchSize = 25;
            let uploaded = 0;

            for (let i = 0; i < total; i += batchSize) {
                const chunk = validItems.slice(i, i + batchSize);
                const res = await fetch("{{ route('inventory.import') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ items_batch: chunk })
                });

                if (res.ok) {
                    uploaded += chunk.length;
                    if (uploaded > total) uploaded = total;
                    const percent = Math.round((uploaded / total) * 100);
                    doneCountEl.innerText = uploaded;
                    progressBar.style.width = percent + '%';
                }
            }

            statusLabel.innerText = 'اكتمل الاستيراد بنجاح! جاري التحديث...';
            setTimeout(() => window.location.reload(), 800);
        } catch (err) {
            console.error(err);
            document.getElementById('excel-import-form').submit();
        }

        return false;
    }
</script>
@endsection
index.blade.php
جارٍ عرض "index.blade.php".

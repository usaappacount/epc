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
        $table->string('key')->primary();$table->text('value')->nullable();
    });
}
if (!Schema::hasTable('whatsapp_sessions')) {
    Schema::create('whatsapp_sessions', function (Blueprint $table) {$table->id();
        $table->string('phone')->unique();$table->string('detected_car')->nullable();
        $table->string('detected_year')->nullable();$table->text('part_query')->nullable();
        $table->text('full_conversation')->nullable();$table->timestamps();
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
    $digits = preg_replace('/\D/', '',$phone);
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
    foreach ($carsList as$car) {
        if (mb_stripos($cleanName,$car->car_name) !== false) {
            $detectedCar =$car->car_name;
            $detectedCategory =$car->category;
            break;
        }
        $aliases = DB::table('car_aliases')->where('car_id',$car->id)->pluck('alias')->toArray();
        foreach ($aliases as$alias) {
            if ($alias !== '' && mb_stripos($cleanName,$alias) !== false) {
                $detectedCar =$car->car_name;
                $detectedCategory =$car->category;
                break 2;
            }
        }
    }

    $patterns = DB::table('year_patterns')->pluck('pattern_example')->toArray();$wordsInName = preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $cleanName, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($patterns as$pattern) {
        if (mb_stripos($cleanName,$pattern) !== false) {
            $detectedYears =$pattern;
            break;
        }
    }
    if (!$detectedYears) {
        foreach ($wordsInName as$word) {
            if (preg_match('/^(?:(?:19|20)?\d{2})[\/-](?:(?:19|20)?\d{2})$/u', $word) || preg_match('/^\d{2}[\-\/]\d{2}$/u', $word)) {
                $detectedYears =$word;
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

    $existingMaster = DB::table('master_items')->where('item_number',$cleanNumber)->first();
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
    if ($detectedCar) {$skipWords = array_merge($skipWords, preg_split('/[\s,\-]+/u',$detectedCar));
    }
    if ($detectedYears) {
        $skipWords[] =$detectedYears;
    }
    $skipWords = array_map('mb_strtolower', array_filter($skipWords));

    foreach ($wordsInName as$word) {
        $wordClean = trim($word);
        $wordLower = mb_strtolower($wordClean);

        if (mb_strlen($wordClean) >= 2 && !is_numeric($wordClean) && !in_array($wordLower,$skipWords)) {
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
    foreach ($allItems as$item) {
        syncItemToMasterCore($item->item_number, $item->item_name,$item->user_id);
    }
}

/**
 * دالة استخراج السيارة والسنة واسم القطعة من نص رسالة العميل
 */
function parseWhatsappText(string $text): array {$detectedCar = null;
    $detectedYear = null;
    $remainingText =$text;

    $carsList = DB::table('cars')->get();
    foreach ($carsList as$car) {
        if (mb_stripos($text,$car->car_name) !== false) {
            $detectedCar =$car->car_name;
            $remainingText = str_ireplace($car->car_name, '',$remainingText);
            break;
        }
        $aliases = DB::table('car_aliases')->where('car_id',$car->id)->pluck('alias')->toArray();
        foreach ($aliases as$alias) {
            if ($alias !== '' && mb_stripos($text,$alias) !== false) {
                $detectedCar =$car->car_name;
                $remainingText = str_ireplace($alias, '',$remainingText);
                break 2;
            }
        }
    }

    $patterns = DB::table('year_patterns')->pluck('pattern_example')->toArray();
    foreach ($patterns as$pattern) {
        if (mb_stripos($text,$pattern) !== false) {
            $detectedYear =$pattern;
            $remainingText = str_ireplace($pattern, '',$remainingText);
            break;
        }
    }

    if (!$detectedYear) {
        if (preg_match('/\b(19\d{2}|20\d{2})\b/u', $text, $matches)) {$detectedYear = $matches[1];$remainingText = str_replace($matches[1], '',$remainingText);
        } elseif (preg_match('/\b(\d{2}[\-\/]\d{2})\b/u', $text, $matches)) {$detectedYear = $matches[1];$remainingText = str_replace($matches[1], '',$remainingText);
        }
    }

    // تنظيف النص المتبقي لاستخراج اسم القطعة الصافي
    $stopWords = ['السلام', 'عليكم', 'ابغى', 'ابغا', 'ابي', 'عندكم', 'موجود', 'احصل', 'سيارة', 'سياره', 'موديل', 'سنة', 'سنه', 'لو', 'سمحت', 'ياغالي', 'بكم', 'هل', 'فيه', 'يتوفر'];
    $words = preg_split('/[\s,\-\+\/\(\)\*\.]+/u', trim($remainingText), -1, PREG_SPLIT_NO_EMPTY);$cleanWords = [];
    foreach ($words as$w) {
        if (!in_array(mb_strtolower($w), $stopWords) && mb_strlen($w) >= 2) {
            $cleanWords[] =$w;
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

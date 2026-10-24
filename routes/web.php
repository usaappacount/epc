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

function handleWebhook(Request $request) {
    DB::table('webhook_logs')->insert([
        'event_type' => $request->input('event', 'unknown'),
        'payload'    => json_encode($request->all(), JSON_UNESCAPED_UNICODE),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    return response()->json(['status' => 'success'], 200);
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

        Route::post('/import', function (Request $request) {
            $response = app(ItemController::class)->import($request);
            DB::table('items')->whereNull('user_id')->update(['user_id' => auth()->id()]);
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

    // 6. مسارات الرد الآلي والذكاء الاصطناعي
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

        return view('pages.second', [
            'customerMessage' => $customerMessage,
            'matchedItems'    => $matchedItems,
        ]);
    })->name('pages.second');

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
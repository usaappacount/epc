<?php

use App\Http\Controllers\ItemController;
use App\Http\Controllers\ProfileController;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\File;

Route::get('/', function () {
    return redirect()->route('inventory.index');
});

Route::get('/dashboard', function () {
    return redirect()->route('inventory.index');
})->middleware(['auth', 'verified'])->name('dashboard');

// دالة موحدة لمعالجة طلبات الـ Webhook لمنع تكرار الكود
function handleWebhook(Request $request) {
    // 1. التحقق من التوقيع الأمني (إذا تم تفعيل المفتاح السري في .env)
    $signature = $request->header('x-webhook-signature');$webhookSecret = env('GREATWHATS_WEBHOOK_SECRET');

    if (!empty($webhookSecret)) {$rawBody = $request->getContent();$expectedSignature = 'sha256=' . hash_hmac('sha256', $rawBody,$webhookSecret);

        if (!$signature || !hash_equals($expectedSignature,$signature)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid signature - التوقيع غير صالح'
            ], 401);
        }
    }

    // 2. تخزين الحدث والبيانات في جدول السجلات
    DB::table('webhook_logs')->insert([
        'event_type' => $request->input('event', 'unknown'),
        'payload'    => json_encode($request->all(), JSON_UNESCAPED_UNICODE),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 3. الرد على المنصة بنجاح الاستلام
    return response()->json([
        'status'  => 'success',
        'message' => 'تم استلام الحدث ومعالجته بنجاح',
    ], 200);
}

// مسار الـ Webhook (يدعم الرابط سواء بوجود شرطة في النهاية أو بدونها لتفادي خطأ 404)
Route::post('/api/webhook/receiver', function (Request $request) {
    return handleWebhook($request);
});

Route::post('/api/webhook/receiver/', function (Request $request) {
    return handleWebhook($request);
});

Route::middleware('auth')->group(function () {
    // مسارات المخزون
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/', [ItemController::class, 'index'])->name('index');
        Route::post('/', [ItemController::class, 'store'])->name('store');
        Route::post('/import', [ItemController::class, 'import'])->name('import');
        Route::put('/{item}', [ItemController::class, 'update'])->name('update');
        Route::delete('/{item}', [ItemController::class, 'destroy'])->name('destroy');
    });

    // مسار صفحة الذكاء الاصطناعي والواتساب (الصفحة الثانية)
    Route::get('/ai-whatsapp', function (Request $request) {
        $customerMessage = (string)$request->query('message', '');
        $matchedItems = collect();$aiReply = null;

        if (trim($customerMessage) !== '') {
            $matchedItems = Item::smartSearch($customerMessage)->take(5)->get();
            if ($matchedItems->isNotEmpty()) {$lines = ["مرحباً بك! إليك الأصناف المتوفرة حسب طلبك:\n"];
                foreach ($matchedItems as $item) {$status = $item->quantity > 0 ? "متوفر ({$item->quantity})" : "غير متوفر";
                    $lines[] = "🔹 *{$item->item_name}* | السعر: {$item->price} ر.س \vert{} الحالة: {$status}";
                }
                $aiReply = implode("\n\n", $lines);
            } else {
                $aiReply = "عذراً، لم نتمكن من العثور على صنف يطابق طلبك ({$customerMessage}).";
            }
        }

        return view('pages.second', [
            'customerMessage' => $customerMessage,
            'message'         => $customerMessage,
            'aiReply'         => $aiReply,
            'reply'           => $aiReply,
            'botReply'        => $aiReply,
            'response'        => $aiReply,
            'matchedItems'    => $matchedItems,
            'items'           => $matchedItems,
        ]);
    })->name('pages.second');

    // صفحة إعدادات ربط الـ API والـ Webhook (الصفحة الثالثة - بحث ذكي تلقائي عن الملف)
    Route::get('/page-third', function () {
        $webhookLogs = DB::table('webhook_logs')->latest()->take(20)->get();

        // فحص جميع المسارات والأسماء المحتملة للملف تلقائياً
        foreach (['third', 'pages.third', 'Third', 'pages.Third', 'page-third', 'pages.page-third'] as $viewName) {
            if (View::exists($viewName)) {
                return view($viewName, compact('webhookLogs'));
            }
        }

        // إذا لم يجده، يبحث عن أي ملف يحتوي اسمه على كلمة third داخل مجلد views ويفتحه
        $allFiles = File::allFiles(resource_path('views'));
        foreach ($allFiles as$file) {
            $relativePath =$file->getRelativePathname();
            if (stripos($relativePath, 'third') !== false && str_ends_with($relativePath, '.blade.php')) {
                $detectedView = str_replace(['/', '\\', '.blade.php'], ['.', '.', ''],$relativePath);
                return view($detectedView, compact('webhookLogs'));
            }
        }

        // إذا لم يكن مرفوعاً على السيرفر أصلاً، يعرض لك قائمة بالملفات الموجودة لمعرفة السبب
        $fileList = collect($allFiles)->map(fn($f) =>$f->getRelativePathname())->implode('<br>');
        return response("<h3>لم يتم العثور على ملف third.blade.php على السيرفر!</h3><p>الملفات الموجودة حالياً داخل resources/views هي:</p><pre>{$fileList}</pre>");
    })->name('pages.third');

    // مسار تحميل النسخة الاحتياطية لقاعدة البيانات
    Route::get('/download-backup', function () {
        $path = database_path('database.sqlite');
        if (!file_exists($path)) {
            return back()->with('error', 'ملف قاعدة البيانات غير موجود.');
        }
        return response()->download($path, 'database_backup_' . date('Y-m-d_H-i-s') . '.sqlite');
    })->name('backup.download');

    // مسارات الملف الشخصي
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.

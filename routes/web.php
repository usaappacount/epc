<?php

use App\Http\Controllers\ItemController;
use App\Http\Controllers\ProfileController;
use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Blade;

Route::get('/', function () {
    return redirect()->route('inventory.index');
});

Route::get('/dashboard', function () {
    return redirect()->route('inventory.index');
})->middleware(['auth', 'verified'])->name('dashboard');

// دالة موحدة لمعالجة طلبات الـ Webhook
function handleWebhook(Request $request) {
    $signature = $request->header('x-webhook-signature');
    $webhookSecret = env('GREATWHATS_WEBHOOK_SECRET');

    if (!empty($webhookSecret)) {
        $rawBody = $request->getContent();
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $rawBody, $webhookSecret);

        if (!$signature || !hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid signature - التوقيع غير صالح'
            ], 401);
        }
    }

    DB::table('webhook_logs')->insert([
        'event_type' => $request->input('event', 'unknown'),
        'payload'    => json_encode($request->all(), JSON_UNESCAPED_UNICODE),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return response()->json([
        'status'  => 'success',
        'message' => 'تم استلام الحدث ومعالجته بنجاح',
    ], 200);
}

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
        $customerMessage = (string) $request->query('message', '');
        $matchedItems = collect();
        $aiReply = null;

        if (trim($customerMessage) !== '') {
            $matchedItems = Item::smartSearch($customerMessage)->take(5)->get();
            if ($matchedItems->isNotEmpty()) {
                $lines = ["مرحباً بك! إليك الأصناف المتوفرة حسب طلبك:\n"];
                foreach ($matchedItems as $item) {
                    $status = $item->quantity > 0 ? "متوفر ({$item->quantity})" : "غير متوفر";
                    $lines[] = "🔹 *{$item->item_name}* | السعر: {$item->price} ر.س | الحالة: {$status}";
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

    // الصفحة الثالثة: تعمل مباشرة ومضمونة 100% حتى بدون ملف خارجي
    Route::get('/page-third', function () {
        $webhookLogs = DB::table('webhook_logs')->latest()->take(20)->get();

        // إذا كان الملف موجوداً في pages.third أو third يفتحه، وإلا يعرض التصميم المدمج فوراً
        if (view()->exists('pages.third')) {
            return view('pages.third', compact('webhookLogs'));
        }
        if (view()->exists('third')) {
            return view('third', compact('webhookLogs'));
        }

        $html = <<<'BLADE'
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight text-right">
            {{ __('إعدادات الربط API') }}
        </h2>
    </x-slot>

    <div class="py-12" dir="rtl">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-bold mb-2 text-gray-900">🔗 رابط استقبال الـ Webhook (Webhook URL)</h3>
                <p class="text-gray-600 text-sm mb-4">استخدم هذا الرابط في إعدادات منصة الواتساب (GreatWhats) لربط الرسائل والأحداث:</p>
                <div class="flex items-center gap-2 bg-gray-100 p-3 rounded-md border border-gray-300">
                    <input type="text" readonly value="{{ url('/api/webhook/receiver') }}" class="w-full bg-transparent border-none text-gray-800 focus:ring-0 text-sm" id="webhookUrl">
                    <button onclick="navigator.clipboard.writeText(document.getElementById('webhookUrl').value); alert('تم نسخ الرابط بنجاح!');" class="bg-blue-600 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700 transition">
                        نسخ الرابط
                    </button>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-bold mb-2 text-gray-900">💾 النسخ الاحتياطي لقاعدة البيانات</h3>
                <p class="text-gray-600 text-sm mb-4">قم بتحميل نسخة احتياطية لجميع البيانات (الموظفين، الأصناف، السجلات) إلى جهازك:</p>
                <a href="{{ route('backup.download') }}" class="inline-flex items-center bg-green-600 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700 transition">
                    📥 تحميل نسخة احتياطية الآن (.sqlite)
                </a>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-bold mb-4 text-gray-900">📋 سجلات الطلبات الواردة (Webhook Logs)</h3>
                @if(isset($webhookLogs) && $webhookLogs->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-right">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">نوع الحدث</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">البيانات (Payload)</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">الوقت</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200 text-sm">
                                @foreach($webhookLogs as $log)
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap font-semibold text-blue-600">{{ $log->event_type }}</td>
                                        <td class="px-6 py-4 text-gray-600 max-w-xs truncate" title="{{ $log->payload }}">{{ $log->payload }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-gray-400 text-xs">{{ $log->created_at }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-gray-500 text-center py-6">لا توجد سجلات واردة حتى الآن.</p>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
BLADE;

        return Blade::render($html, compact('webhookLogs'));
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
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

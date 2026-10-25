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

// تهيئة جداول إعدادات البوت العامة وإعدادات كل عميل وذاكرة المحادثة والحدود والاستثناءات تلقائياً
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

// جدول عداد الاستعلامات لكل رقم واتساب لدى كل تاجر
if (!Schema::hasTable('whatsapp_customer_quotas')) {
    Schema::create('whatsapp_customer_quotas', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->default(1)->index();
        $table->string('phone')->index();
        $table->integer('queries_count')->default(0);
        $table->timestamps();
        $table->unique(['user_id', 'phone']);
    });
}

// جدول الأرقام المستثناة (حد مفتوح لا نهائي)
if (!Schema::hasTable('whatsapp_whitelist_phones')) {
    Schema::create('whatsapp_whitelist_phones', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->default(1)->index();
        $table->string('phone')->index();
        $table->string('label')->nullable();
        $table->timestamps();
        $table->unique(['user_id', 'phone']);
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
 * دالة توحيد صيغة الرقم للمقارنة في الواتساب (تزيل + و 0 البادئة وتوحد 966)
 */
function normalizeWhatsappDigits(string $phone): string {
    $digits = preg_replace('/@.*$/', '', $phone);
    $digits = preg_replace('/\D/', '', $digits);
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) === 10 && str_starts_with($digits, '05')) {
        $digits = '966' . substr($digits, 1);
    } elseif (strlen($digits) === 9 && str_starts_with($digits, '5')) {
        $digits = '966' . $digits;
    }
    return $digits;
}

/**
 * دالة تحويل الأرقام العربية/الفارسية إلى إنجليزية
 */
function normalizeArabicDigits(string $str): string {
    $arabic = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $english = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    return str_replace($arabic, $english, $str);
}

/**
 * توحيد الحروف العربية (الألف والهمزات والتاء المربوطة والياء) لضمان دقة التطابق
 */
function normalizeArabicSpelling(string $str): string {
    $str = normalizeArabicDigits(trim($str));
    $str = str_replace(['أ', 'إ', 'آ'], 'ا', $str);
    $str = str_replace('ة', 'ه', $str);
    $str = str_replace('ى', 'ي', $str);
    return mb_strtolower($str);
}

/**
 * قائمة الكلمات الوصفية والاتجاهات التي لا تعتبر اسماً أساسياً للقطعة بمفردها
 */
function getPartModifierWords(): array {
    return [
        'يمين', 'يسار', 'امامي', 'اماميه', 'خلفي', 'خلفيه',
        'علوي', 'علويه', 'سفلي', 'سفليه', 'داخلي', 'داخليه', 'خارجي', 'خارجيه',
        'وسط', 'جانبي', 'جهه', 'طقم', 'حبه', 'كامل', 'اصلي', 'وكاله', 'تجاري', 'يباني', 'ياباني'
    ];
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
 * دالة ذكية لتوريث اسم القطعة عندما يكتب الزبون اتجاهين معطوفين
 * مثال: ["مقص امامي يمين", "يسار"] => ["مقص امامي يمين", "مقص امامي يسار"]
 * مثال: ["مساعد امامي", "خلفي"] => ["مساعد امامي", "مساعد خلفي"]
 */
function expandCompoundDirectionalRequests(array $rawParts): array {
    $modifiers = getPartModifierWords();
    $sideWords = ['يمين', 'يسار'];
    $posWords  = ['امامي', 'اماميه', 'خلفي', 'خلفيه'];
    $vertWords = ['علوي', 'علويه', 'سفلي', 'سفليه'];
    $inOutWords= ['داخلي', 'داخليه', 'خارجي', 'خارجيه'];

    $expanded = [];

    foreach ($rawParts as $phrase) {
        $phrase = trim($phrase);
        if ($phrase === '') continue;

        $words = preg_split('/\s+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY);
        $normWords = array_map('normalizeArabicSpelling', $words);

        // فحص هل هذه العبارة تتكون من كلمات اتجاه فقط؟ (مثل كلمة "يسار" لوحدها، أو "خلفي يمين" لوحدها)
        $onlyModifiers = true;
        foreach ($normWords as $nw) {
            if (!in_array($nw, $modifiers)) {
                $onlyModifiers = false;
                break;
            }
        }

        // إذا كانت العبارة عبارة عن اتجاه فقط (مثل "يسار") ولدينا قطعة سابقة (مثل "مقص امامي يمين")
        if ($onlyModifiers && !empty($expanded)) {
            $prevPhrase = end($expanded);
            $prevWords  = preg_split('/\s+/u', $prevPhrase, -1, PREG_SPLIT_NO_EMPTY);

            $newPhraseWords = $prevWords;

            foreach ($words as $modWord) {
                $normMod = normalizeArabicSpelling($modWord);
                $replaced = false;

                // تحديد المجموعة المتضادة (يمين<->يسار، أمامي<->خلفي، علوي<->سفلي)
                $oppositeGroup = [];
                if (in_array($normMod, $sideWords))  $oppositeGroup = $sideWords;
                elseif (in_array($normMod, $posWords))  $oppositeGroup = $posWords;
                elseif (in_array($normMod, $vertWords)) $oppositeGroup = $vertWords;
                elseif (in_array($normMod, $inOutWords))$oppositeGroup = $inOutWords;

                if (!empty($oppositeGroup)) {
                    foreach ($newPhraseWords as $idx => $pw) {
                        if (in_array(normalizeArabicSpelling($pw), $oppositeGroup)) {
                            $newPhraseWords[$idx] = $modWord;
                            $replaced = true;
                            break;
                        }
                    }
                }

                if (!$replaced) {
                    $newPhraseWords[] = $modWord;
                }
            }

            $expanded[] = implode(' ', $newPhraseWords);
        } else {
            $expanded[] = $phrase;
        }
    }

    return array_values(array_unique($expanded));
}

/**
 * دالة البحث الدقيق عن قطعة واحدة في المخزون:
 * تمنع ظهور أي صنف لا يطابق الاسم الأساسي للقطعة (فلا يظهر كرسي مكينة أو ذراع دركسون عند طلب مقص!)
 */
function findMatchingItemsForSinglePart(string $singlePartRequest, string $car, string $year, int $ownerUserId) {
    $singlePartRequest = trim($singlePartRequest);
    if ($singlePartRequest === '') {
        return collect();
    }

    // 1. إذا كان الطلب رقم صنف مباشر
    $directMatch = Item::where('user_id', $ownerUserId)
        ->where('quantity', '>', 0)
        ->where('item_number', strtoupper($singlePartRequest))
        ->first();

    if ($directMatch) {
        return collect([$directMatch]);
    }

    // 2. تقسيم كلمات الطلب إلى: (كلمات أساسية لاسم القطعة مثل: مقص، مساعد، كرسي) و (كلمات اتجاه/وصف مثل: امامي، يمين، يسار)
    $allWords = array_values(array_filter(preg_split('/\s+/u', $singlePartRequest), fn($w) => mb_strlen($w) >= 2));
    $modifiersList = getPartModifierWords();

    $coreNounWords = [];
    $directionWords = [];

    foreach ($allWords as $w) {
        $normW = normalizeArabicSpelling($w);
        if (in_array($normW, $modifiersList)) {
            $directionWords[] = $w;
        } else {
            $coreNounWords[] = $w;
        }
    }

    // إذا لم يكتب الزبون أي اسم قطعة أساسي (مثلاً كتب "امامي يمين" فقط بدون اسم قطعة)، لا نرجع أصناف عشوائية!
    if (empty($coreNounWords)) {
        return collect();
    }

    // 3. جلب جميع أصناف هذه السيارة المتوفرة لدى التاجر
    $carCandidates = Item::where('user_id', $ownerUserId)
        ->where('quantity', '>', 0)
        ->where(function ($q) use ($car) {
            $q->where('detected_car_name', 'like', "%{$car}%")
              ->orWhere('item_name', 'like', "%{$car}%");
        })
        ->get();

    // 4. فلترة صارمة: يجب أن يحتوي الصنف على جميع الكلمات الأساسية للقطعة + جميع الاتجاهات المطلوبة + سنة الصنع
    $matched = $carCandidates->filter(function ($item) use ($coreNounWords, $directionWords, $year) {
        // أولاً: التحقق من مطابقة سنة الصنع
        if (!isYearMatchingItem($year, $item->detected_years, $item->item_name)) {
            return false;
        }

        $itemNameNorm = normalizeArabicSpelling($item->item_name);

        // جلب الكلمات المفتاحية المربوطة بهذا الرقم في قلب النظام (إن وجدت)
        $itemKeywords = DB::table('item_keywords')
            ->where('item_number', $item->item_number)
            ->pluck('keyword')
            ->map(fn($k) => normalizeArabicSpelling($k))
            ->implode(' ');

        $fullSearchableText = $itemNameNorm . ' ' . $itemKeywords;

        // ثانياً (شرط إجباري صارم): كل كلمة أساسية في طلب الزبون (مثل "مقص" أو "بواجي") يجب أن تكون موجودة في الصنف
        foreach ($coreNounWords as $coreWord) {
            $cwNorm = normalizeArabicSpelling($coreWord);
            // معالجة جمع/مفرد شائع مثل (كرسي <-> كراسي) أو (مساعد <-> مساعدات)
            $ variants = [$cwNorm];
            if ($cwNorm === 'كراسي') $variants[] = 'كرسي';
            if ($cwNorm === 'كرسي')  $variants[] = 'كراسي';
            if ($cwNorm === 'مساعدات') $variants[] = 'مساعد';
            if ($cwNorm === 'مقصات')   $variants[] = 'مقص';
            if ($cwNorm === 'اذرعه')   $variants[] = 'ذراع';

            $foundCore = false;
            foreach ($variants as $v) {
                if (mb_stripos($fullSearchableText, $v) !== false) {
                    $foundCore = true;
                    break;
                }
            }

            if (!$foundCore) {
                return false;
            }
        }

        // ثالثاً (شرط إجباري للاتجاهات): إذا حدد الزبون اتجاهاً (مثل "يمين" أو "يسار" أو "امامي")، يجب ألا نعطيه الاتجاه المعاكس!
        foreach ($directionWords as $dirWord) {
            $dwNorm = normalizeArabicSpelling($dirWord);

            // إذا طلب "يمين" والصنف مكتوب فيه "يسار" فقط (وليس يمين) نرفضه، والعكس
            if ($dwNorm === 'يمين' && mb_stripos($fullSearchableText, 'يمين') === false) {
                return false;
            }
            if ($dwNorm === 'يسار' && mb_stripos($fullSearchableText, 'يسار') === false) {
                return false;
            }
            if (in_array($dwNorm, ['امامي', 'اماميه']) && mb_stripos($fullSearchableText, 'خلفي') !== false && mb_stripos($fullSearchableText, 'امام') === false) {
                return false;
            }
            if (in_array($dwNorm, ['خلفي', 'خلفيه']) && mb_stripos($fullSearchableText, 'امام') !== false && mb_stripos($fullSearchableText, 'خلف') === false) {
                return false;
            }

            // التأكد من وجود كلمة الاتجاه في اسم الصنف إذا كان الصنف يحدد اتجاهاً
            $dirRoot = rtrim($dwNorm, 'ه'); // مثل اماميه -> امامي
            if (mb_stripos($fullSearchableText, $dirRoot) === false && mb_stripos($fullSearchableText, $dwNorm) === false) {
                return false;
            }
        }

        return true;
    })->take(3)->values();

    return $matched;
}

/**
 * دالة تحليل رسالة الزبون واستخراج:
 * 1. أرقام القطع المباشرة (part_numbers)
 * 2. السيارة وسنة الصنع
 * 3. قائمة الأصناف المطلوبة مع دعم الوراثة الذكية للاتجاهات (مثل: مساعد امامي يمين و يسار)
 */
function parseWhatsappText(string $text, ?int $ownerUserId = null): array {
    $normText = normalizeArabicDigits($text);
    $detectedCar = null;
    $detectedCategory = null;
    $detectedYear = null;
    $remainingText = $normText;

    // 1. استخراج أرقام الأصناف المباشرة (مثل 90919-01289 أو أرقام أصناف مطابقة للمخزون)
    $extractedPartNumbers = [];
    if (preg_match_all('/\b[A-Za-z0-9]{3,}[\-][A-Za-z0-9\-]{2,}\b/u', $remainingText, $pm)) {
        foreach ($pm[0] as $partNum) {
            if (!preg_match('/^(?:19|20)?\d{2}[\-](?:19|20)?\d{2}$/', $partNum)) {
                $extractedPartNumbers[] = strtoupper($partNum);
                $remainingText = str_replace($partNum, ' ', $remainingText);
            }
        }
    }

    // فحص ما إذا كانت هناك كلمة تطابق رقم صنف مباشر في مخزون التاجر (بدون شرطة مثلاً)
    $tokens = preg_split('/[\s,،\n\r]+/u', trim($remainingText), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($tokens as $tk) {
        $cleanTk = strtoupper(trim($tk));
        if (strlen($cleanTk) >= 5 && preg_match('/[0-9]/', $cleanTk)) {
            $existsAsNumber = DB::table('items')
                ->when($ownerUserId, fn($q) => $q->where('user_id', $ownerUserId))
                ->where('item_number', $cleanTk)
                ->exists();
            if ($existsAsNumber) {
                $extractedPartNumbers[] = $cleanTk;
                $remainingText = str_ireplace($tk, ' ', $remainingText);
            }
        }
    }
    $extractedPartNumbers = array_values(array_unique($extractedPartNumbers));

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

    // 3. البحث في أنماط السنوات المضافة في الإعدادات
    $patterns = DB::table('year_patterns')->pluck('pattern_example')->toArray();
    foreach ($patterns as $pattern) {
        $normPattern = normalizeArabicDigits(trim($pattern));
        if ($normPattern !== '' && preg_match('/(?<!\d)' . preg_quote($normPattern, '/') . '(?!\d)/u', $remainingText)) {
            $detectedYear = $normPattern;
            $remainingText = preg_replace('/(?<!\d)' . preg_quote($normPattern, '/') . '(?!\d)/u', ' ', $remainingText, 1);
            break;
        }
    }

    // 4. التعرف الذكي على سنة الصنع (رباعية 2025 أو ثنائية 25 أو نطاق 18-22)
    if (!$detectedYear) {
        if (preg_match('/(?<!\d)((?:19|20)?\d{2}\s*[\/-]\s*(?:19|20)?\d{2})(?!\d)/u', $remainingText, $matches)) {
            $detectedYear = str_replace(' ', '', $matches[1]);
            $remainingText = str_replace($matches[1], ' ', $remainingText);
        } elseif (preg_match('/(?<!\d)(19[7-9]\d|20[0-3]\d)(?!\d)/u', $remainingText, $matches)) {
            $detectedYear = $matches[1];
            $remainingText = preg_replace('/(?<!\d)' . preg_quote($matches[1], '/') . '(?!\d)/u', ' ', $remainingText, 1);
        } elseif (preg_match('/(?<!\d)(\d{2})(?!\d)/u', $remainingText, $matches)) {
            $twoDigit = (int) $matches[1];
            if ($twoDigit <= 35 || $twoDigit >= 70) {
                $detectedYear = $matches[1];
                $remainingText = preg_replace('/(?<!\d)' . preg_quote($matches[1], '/') . '(?!\d)/u', ' ', $remainingText, 1);
            }
        }
    }

    // 5. تقسيم النص المتبقي إلى طلبات متعددة مع معالجة حرف الواو الملتصق أو المنفصل
    $stopWords = ['السلام', 'عليكم', 'ورحمة', 'الله', 'وبركاته', 'مساء', 'صباح', 'الخير', 'النور', 'اهلا', 'هلا', 'حياك', 'حياكم', 'ابغى', 'ابغا', 'ابي', 'أبغى', 'أبي', 'وابغا', 'وابغى', 'وابي', 'عندكم', 'عندك', 'موجود', 'احصل', 'أحصل', 'الاقي', 'ألاقي', 'سيارة', 'سياره', 'السيارة', 'السياره', 'موديل', 'سنة', 'سنه', 'لو', 'سمحت', 'سمحتوا', 'ياغالي', 'بكم', 'هل', 'فيه', 'يتوفر', 'متوفر', 'من', 'فضلك', 'مطلوب', 'طلب', 'اريد', 'أريد', 'احتاج', 'أحتاج', 'شوف', 'لي', 'كذلك', 'ايضا', 'برضه'];

    $modifiersList = getPartModifierWords();

    // تقسيم مبدئي بالفواصل أو الأسطر أو علامة + أو " و " أو " وكذلك " أو " مع "
    $rawSegments = preg_split('/(?:[\n\r,،\+\/]+|\s+و\s+|\s+وكذلك\s+|\s+مع\s+)/u', trim($remainingText), -1, PREG_SPLIT_NO_EMPTY);

    $partsList = [];
    foreach ($rawSegments as $seg) {
        $subTokens = preg_split('/\s+/u', trim($seg), -1, PREG_SPLIT_NO_EMPTY);
        $currentPhrase = [];

        foreach ($subTokens as $tok) {
            $tokClean = trim($tok, '-_*. ');
            if ($tokClean === '' || in_array(mb_strtolower($tokClean), $stopWords)) {
                continue;
            }

            // معالجة حالة التصاق حرف الواو ببداية الكلمة مثل "ويسار" أو "ويمين" أو "وفحمات"
            if (mb_strlen($tokClean) >= 4 && mb_substr($tokClean, 0, 1) === 'و') {
                $withoutWaw = mb_substr($tokClean, 1);
                $normWithoutWaw = normalizeArabicSpelling($withoutWaw);

                // هل الكلمة بعد إزالة الواو هي اتجاه (مثل "يسار" في "ويسار") أو قطعة معروفة؟
                $isDirectionWord = in_array($normWithoutWaw, $modifiersList);
                $isKnownPart = $isDirectionWord || DB::table('item_keywords')->where('keyword', 'like', "%{$withoutWaw}%")->exists();

                if ($isKnownPart && !empty($currentPhrase)) {
                    $partsList[] = implode(' ', $currentPhrase);
                    $currentPhrase = [$withoutWaw];
                    continue;
                }
            }

            if (mb_strlen($tokClean) >= 2) {
                $currentPhrase[] = $tokClean;
            }
        }

        if (!empty($currentPhrase)) {
            $partsList[] = implode(' ', $currentPhrase);
        }
    }

    // 6. تطبيق الوراثة الذكية للاتجاهات (تحويل ["مقص امامي يمين", "يسار"] إلى ["مقص امامي يمين", "مقص امامي يسار"])
    $partsList = expandCompoundDirectionalRequests($partsList);

    return [
        'part_numbers' => $extractedPartNumbers,
        'car'          => $detectedCar,
        'category'     => $detectedCategory,
        'year'         => $detectedYear,
        'parts_list'   => $partsList,
        'part'         => implode(' | ', $partsList),
    ];
}

/**
 * دالة التحقق الذكية: هل رسالة الزبون تمثل استعلاماً حقيقياً عن قطع غيار أم مجرد تحية/دردشة؟
 */
function hasGenuinePartInquiryIntent(string $messageText, array $parsed, int $ownerUserId): bool {
    if (!empty($parsed['part_numbers'])) {
        return true;
    }

    if (!empty($parsed['car'])) {
        return true;
    }

    if (empty($parsed['parts_list'])) {
        return false;
    }

    foreach ($parsed['parts_list'] as $partPhrase) {
        $words = preg_split('/\s+/u', $partPhrase, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $w) {
            if (mb_strlen($w) < 2) continue;

            $inKeywords = DB::table('item_keywords')->where('keyword', 'like', "%{$w}%")->exists();
            if ($inKeywords) return true;

            $inItems = DB::table('items')
                ->where('user_id', $ownerUserId)
                ->where(function ($q) use ($w) {
                    $q->where('item_name', 'like', "%{$w}%")
                      ->orWhere('item_number', 'like', "%{$w}%");
                })
                ->exists();
            if ($inItems) return true;
        }
    }

    $explicitIntentWords = ['قطعة', 'قطعه', 'قطع', 'غيار', 'ابغى', 'ابغا', 'ابي', 'أبغى', 'عندكم', 'متوفر', 'يتوفر', 'احصل', 'سعر', 'بكم', 'مطلوب'];
    foreach ($explicitIntentWords as $intentWord) {
        if (mb_stripos($messageText, $intentWord) !== false && !empty($parsed['parts_list'])) {
            return true;
        }
    }

    return false;
}

/**
 * دالة جلب إعدادات البوت الخاصة بعميل معين
 */
function getUserBotSettings(int $userId): array {
    $defaults = [
        'reply_available'       => "القطعة متوفرة لدينا ✅\nاسم الصنف: {item_name}\nرقم الصنف: {item_number}\nالسعر: {price} ريال",
        'reply_unavailable'     => "نعتذر منك، القطعة المطلوبة ({part}) لسيارة {car} موديل {year} غير متوفرة حالياً ❌",
        'reply_ask_car'         => "حياك الله، فضلاً ما هو نوع أو اسم السيارة؟",
        'reply_ask_year'        => "فضلاً كم موديل (سنة صنع) السيارة {car}؟",
        'reply_quota_exceeded'  => "",
        'max_queries_per_phone' => "0",
        'allow_group_replies'   => "0",
        'whatsapp_api_url'      => "",
        'whatsapp_api_token'    => "",
        'whatsapp_session_id'   => "",
    ];

    $saved = DB::table('user_bot_settings')
        ->where('user_id', $userId)
        ->pluck('value', 'key')
        ->toArray();

    if (empty($saved)) {
        $legacy = DB::table('bot_settings')->pluck('value', 'key')->toArray();
        if (!empty($legacy)) {
            $saved = $legacy;
        }
    }

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
 * دالة تحديد صاحب حساب الواتساب بناءً على session_id أو الرابط
 */
function resolveWebhookOwnerUserId(Request $request, array $payload): int {
    if ($request->filled('user_id')) {
        return (int) $request->query('user_id');
    }

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

/**
 * دالة إرسال رسالة الواتساب عبر بوابة الربط
 */
function sendWhatsappReplyMessage(array $settings, string $cleanPhone, string $rawSender, $convId, string $replyText): void {
    $apiUrl    = trim($settings['whatsapp_api_url'] ?? '');
    $apiToken  = trim($settings['whatsapp_api_token'] ?? '');
    $sessionId = trim($settings['whatsapp_session_id'] ?? '');

    if (!empty($apiUrl) && trim($replyText) !== '') {
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
}

/**
 * دالة إرسال رمز التحقق (OTP) لتسجيل الدخول عبر الواتساب
 */
function sendWhatsappLoginOtp(string $phone, string $otpCode): bool {
    $cleanPhone = normalizeWhatsappDigits($phone);
    if ($cleanPhone === '') {
        return false;
    }

    $admin = User::where('role', 'admin')->first();
    $settings = $admin ? getUserBotSettings($admin->id) : [];

    if (empty(trim($settings['whatsapp_api_url'] ?? ''))) {
        $configuredUser = DB::table('user_bot_settings')
            ->where('key', 'whatsapp_api_url')
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->first();

        if ($configuredUser) {
            $settings = getUserBotSettings((int) $configuredUser->user_id);
        }
    }

    if (empty(trim($settings['whatsapp_api_url'] ?? ''))) {
        return false;
    }

    $message = "🔐 رمز التحقق الخاص بك لتسجيل الدخول هو: *{$otpCode}*\nيرجى عدم مشاركة هذا الرمز مع أي شخص.";
    $jid = $cleanPhone . '@s.whatsapp.net';

    sendWhatsappReplyMessage($settings, $cleanPhone, $jid, null, $message);
    return true;
}

/**
 * دالة فحص صارمة جداً لاكتشاف ما إذا كانت الرسالة قادمة من مجموعة (Group) أو قناة/حالة
 */
function detectGroupAndBroadcastStatus(array $payload): array {
    $rawJson = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '';

    $isBroadcastOrStatus = str_contains($rawJson, '@broadcast')
                        || str_contains($rawJson, 'status@')
                        || str_contains($rawJson, '@newsletter');

    $possibleJids = [
        data_get($payload, 'data.chat_id'),
        data_get($payload, 'data.chatId'),
        data_get($payload, 'data.chat.id'),
        data_get($payload, 'data.key.remoteJid'),
        data_get($payload, 'data.remoteJid'),
        data_get($payload, 'data.from'),
        data_get($payload, 'data.to'),
        data_get($payload, 'data.group_id'),
        data_get($payload, 'data.groupId'),
        data_get($payload, 'data.message.chatId'),
        data_get($payload, 'data.message.from'),
        data_get($payload, 'data.message.key.remoteJid'),
        data_get($payload, 'chatId'),
        data_get($payload, 'chat_id'),
        data_get($payload, 'remoteJid'),
        data_get($payload, 'from'),
        data_get($payload, 'group_id'),
        data_get($payload, 'groupId'),
    ];

    $groupJid = null;
    foreach ($possibleJids as $jid) {
        if (is_string($jid) && str_contains($jid, '@g.us')) {
            $groupJid = $jid;
            break;
        }
    }

    $isGroupFlag = data_get($payload, 'data.isGroup')
                ?? data_get($payload, 'data.is_group')
                ?? data_get($payload, 'isGroup')
                ?? data_get($payload, 'is_group')
                ?? data_get($payload, 'data.message.isGroupMsg')
                ?? data_get($payload, 'data.chat.isGroup')
                ?? false;

    $participant = data_get($payload, 'data.participant')
                ?? data_get($payload, 'data.key.participant')
                ?? data_get($payload, 'data.message.participant')
                ?? data_get($payload, 'data.message.key.participant')
                ?? data_get($payload, 'data.author')
                ?? data_get($payload, 'data.message.author')
                ?? data_get($payload, 'participant')
                ?? data_get($payload, 'author');

    $isGroup = ($groupJid !== null)
            || ($isGroupFlag === true || $isGroupFlag === 'true' || $isGroupFlag === 1 || $isGroupFlag === '1')
            || (!empty($participant))
            || str_contains($rawJson, '@g.us');

    return [
        'is_broadcast' => $isBroadcastOrStatus,
        'is_group'     => $isGroup,
        'group_jid'    => $groupJid,
    ];
}

/**
 * دالة معالجة الويب هوك الذكية
 */
function handleWebhook(Request $request) {
    $payload = $request->all();
    $eventType = $request->input('event', data_get($payload, 'event', 'unknown'));

    if ($eventType === 'test.ping') {
        return response()->json(['status' => 'pong'], 200);
    }

    // 1. تجاهل الرسائل الصادرة من البوت نفسه فوراً
    $fromMe = data_get($payload, 'data.fromMe')
           ?? data_get($payload, 'data.message.fromMe')
           ?? data_get($payload, 'data.is_from_me')
           ?? data_get($payload, 'fromMe', false);

    if ($fromMe === true || $fromMe === 'true' || $fromMe === 1 || $eventType === 'message.sent') {
        return response()->json(['status' => 'ignored_from_me'], 200);
    }

    // 2. معرفة صاحب البوت وإعداداته قبل قراءة الرسالة
    $ownerUserId = resolveWebhookOwnerUserId($request, $payload);
    $ownerUser   = User::find($ownerUserId);
    $settings    = getUserBotSettings($ownerUserId);
    $allowGroups = ($settings['allow_group_replies'] ?? '0') === '1';

    // 3. فحص القروبات والقنوات بشكل صارم قبل استخراج رقم المرسل أو قراءة النص
    $groupCheck = detectGroupAndBroadcastStatus($payload);

    if ($groupCheck['is_broadcast']) {
        return response()->json(['status' => 'ignored_broadcast_or_status'], 200);
    }

    // إذا كانت الرسالة من قروب والخيار غير مفعل: لا نقرأ الرسالة ولا نسجلها ولا نرد على الشخص في الخاص ولا في القروب
    if ($groupCheck['is_group'] && !$allowGroups) {
        return response()->json(['status' => 'ignored_group_message_completely'], 200);
    }

    DB::table('webhook_logs')->insert([
        'event_type' => $eventType,
        'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

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

    $rawSender = ($groupCheck['is_group'] && !empty($groupCheck['group_jid']))
               ? (string) $groupCheck['group_jid']
               : (string) $sender;

    $cleanPhone = ($groupCheck['is_group'] && !empty($groupCheck['group_jid']))
                ? $groupCheck['group_jid']
                : normalizeWhatsappDigits((string) $sender);

    $messageText = trim($messageText);

    if ($cleanPhone === '' || $messageText === '') {
        return response()->json(['status' => 'empty'], 200);
    }

    $msgAskCar        = trim($settings['reply_ask_car'] ?? '');
    $msgAskYear       = trim($settings['reply_ask_year'] ?? '');
    $msgAvailable     = trim($settings['reply_available'] ?? '');
    $msgUnavailable   = trim($settings['reply_unavailable'] ?? '');
    $msgQuotaExceeded = trim($settings['reply_quota_exceeded'] ?? '');
    $maxQueriesLimit  = (int) ($settings['max_queries_per_phone'] ?? 0);
    $sessionId        = trim($settings['whatsapp_session_id'] ?? '');
    $convId           = data_get($payload, 'data.conversation_id', data_get($payload, 'conversation_id'));

    // 4. التحقق من الاستثناء (Whitelist) وحد الاستعلامات المسموح لكل رقم
    $quotaPhoneKey = normalizeWhatsappDigits((string) $sender) ?: $cleanPhone;

    $isWhitelisted = DB::table('whatsapp_whitelist_phones')
        ->where('user_id', $ownerUserId)
        ->where('phone', $quotaPhoneKey)
        ->exists();

    $quotaRecord = DB::table('whatsapp_customer_quotas')
        ->where('user_id', $ownerUserId)
        ->where('phone', $quotaPhoneKey)
        ->first();

    $currentQueriesUsed = $quotaRecord ? (int) $quotaRecord->queries_count : 0;

    if (!$isWhitelisted && $maxQueriesLimit > 0 && $currentQueriesUsed >= $maxQueriesLimit) {
        if ($msgQuotaExceeded !== '') {
            $quotaReply = formatBotTemplate($msgQuotaExceeded, [
                'client_name' => $ownerUser->name ?? '',
                'phone'       => $quotaPhoneKey,
            ]);
            sendWhatsappReplyMessage($settings, $cleanPhone, $rawSender, $convId, $quotaReply);
            return response()->json(['status' => 'quota_exceeded_replied', 'reply' => $quotaReply], 200);
        }
        return response()->json(['status' => 'ignored_quota_exceeded'], 200);
    }

    // استرجاع جلسة الذاكرة النشطة (إن وجدت)
    $session = DB::table('whatsapp_sessions')
        ->where('user_id', $ownerUserId)
        ->where('phone', $quotaPhoneKey)
        ->first();

    if ($session && now()->diffInMinutes($session->updated_at) > 120) {
        DB::table('whatsapp_sessions')->where('id', $session->id)->delete();
        $session = null;
    }

    $parsed = parseWhatsappText($messageText, $ownerUserId);

    // 5. إذا لم تكن هناك جلسة مفتوحة، نتحقق مما إذا كانت الرسالة استعلاماً حقيقياً عن قطع غيار أم مجرد تحية/دردشة
    if (!$session && !hasGenuinePartInquiryIntent($messageText, $parsed, $ownerUserId)) {
        return response()->json(['status' => 'ignored_non_inquiry_chat'], 200);
    }

    $replyBlocks = [];
    $unavailablePartsNames = [];

    // 6. الحالة الأولى: إذا كتب الزبون رقم صنف مباشر (أو عدة أرقام أصناف) ولا توجد عناصر أخرى تتطلب سيارة/سنة
    if (!empty($parsed['part_numbers']) && empty($parsed['parts_list']) && !$session) {
        foreach ($parsed['part_numbers'] as $pNum) {
            $item = Item::where('user_id', $ownerUserId)
                ->where('quantity', '>', 0)
                ->where(function ($q) use ($pNum) {
                    $q->where('item_number', $pNum)
                      ->orWhere('item_number', 'like', "%{$pNum}%");
                })
                ->first();

            if ($item) {
                if ($msgAvailable !== '') {
                    $replyBlocks[] = formatBotTemplate($msgAvailable, [
                        'item_name'   => $item->item_name,
                        'item_number' => $item->item_number,
                        'price'       => (string) $item->price,
                        'quantity'    => (string) $item->quantity,
                        'car'         => $item->detected_car_name ?? '',
                        'category'    => $item->detected_category ?? '',
                        'year'        => $item->detected_years ?? '',
                        'part'        => $pNum,
                        'client_name' => $ownerUser->name ?? '',
                        'phone'       => $quotaPhoneKey,
                        'items'       => "- {$item->item_name} (رقم: {$item->item_number}) | السعر: {$item->price} ريال",
                    ]);
                }
            } else {
                $unavailablePartsNames[] = $pNum;
            }
        }

        if (!empty($unavailablePartsNames) && $msgUnavailable !== '') {
            $combinedMissing = implode(' ، ', array_unique($unavailablePartsNames));
            $replyBlocks[] = formatBotTemplate($msgUnavailable, [
                'item_name'   => $combinedMissing,
                'item_number' => $combinedMissing,
                'price'       => '',
                'quantity'    => '0',
                'car'         => '',
                'category'    => '',
                'year'        => '',
                'part'        => $combinedMissing,
                'client_name' => $ownerUser->name ?? '',
                'phone'       => $quotaPhoneKey,
                'items'       => '',
            ]);
        }

        DB::table('whatsapp_customer_quotas')->updateOrInsert(
            ['user_id' => $ownerUserId, 'phone' => $quotaPhoneKey],
            [
                'queries_count' => $currentQueriesUsed + 1,
                'updated_at'    => now(),
                'created_at'    => $quotaRecord->created_at ?? now(),
            ]
        );

        $finalDirectReply = implode("\n\n", array_filter($replyBlocks));
        if ($finalDirectReply === '') {
            return response()->json(['status' => 'skipped_empty_template'], 200);
        }

        sendWhatsappReplyMessage($settings, $cleanPhone, $rawSender, $convId, $finalDirectReply);

        return response()->json([
            'status'     => 'success',
            'session_id' => $sessionId,
            'reply'      => $finalDirectReply,
            'message'    => $finalDirectReply,
            'text'       => $finalDirectReply,
            'body'       => $finalDirectReply,
        ], 200);
    }

    // 7. الحالة الثانية: استعلام بالاسم (مفرد أو متعدد الأصناف) يحتاج (القطعة + السيارة + سنة الصنع)
    $car  = $parsed['car']  ?: ($session->detected_car ?? null);
    $year = $parsed['year'] ?: ($session->detected_year ?? null);

    $existingParts = [];
    if (!empty($session->part_query)) {
        $existingParts = array_values(array_filter(array_map('trim', explode('|', $session->part_query))));
    }
    $newParts = array_merge($parsed['part_numbers'], $parsed['parts_list']);
    $mergedPartsList = expandCompoundDirectionalRequests(array_values(array_unique(array_merge($existingParts, $newParts))));
    $partQueryString = implode(' | ', $mergedPartsList);

    $fullConv = trim(($session->full_conversation ?? '') . ' ' . $messageText);

    if ($session) {
        DB::table('whatsapp_sessions')->where('id', $session->id)->update([
            'detected_car'      => $car,
            'detected_year'     => $year,
            'part_query'        => $partQueryString,
            'full_conversation' => $fullConv,
            'updated_at'        => now(),
        ]);
    } else {
        DB::table('whatsapp_sessions')->insert([
            'user_id'           => $ownerUserId,
            'phone'             => $quotaPhoneKey,
            'detected_car'      => $car,
            'detected_year'     => $year,
            'part_query'        => $partQueryString,
            'full_conversation' => $fullConv,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    $carCategory = $parsed['category'] ?? '';
    if (!$carCategory && $car) {
        $carRow = DB::table('cars')->where('car_name', $car)->first();
        $carCategory = $carRow->category ?? '';
    }

    $readablePartsSummary = implode(' ، ', $mergedPartsList);

    $baseTemplateVars = [
        'item_name'   => '',
        'item_number' => '',
        'price'       => '',
        'quantity'    => '',
        'car'         => $car ?? '',
        'category'    => $carCategory,
        'year'        => $year ?? '',
        'part'        => $readablePartsSummary ?: $messageText,
        'client_name' => $ownerUser->name ?? '',
        'phone'       => $quotaPhoneKey,
        'items'       => '',
    ];

    if (empty($mergedPartsList)) {
        return response()->json(['status' => 'waiting_for_part_name_in_memory'], 200);
    }

    // طلب نوع السيارة مرة واحدة لجميع الأصناف إذا لم يُعرف
    if (!$car) {
        if ($msgAskCar === '') {
            return response()->json(['status' => 'skipped_empty_ask_car_template'], 200);
        }
        $replyText = formatBotTemplate($msgAskCar, $baseTemplateVars);
        sendWhatsappReplyMessage($settings, $cleanPhone, $rawSender, $convId, $replyText);

        return response()->json([
            'status'     => 'success',
            'session_id' => $sessionId,
            'reply'      => $replyText,
            'message'    => $replyText,
            'text'       => $replyText,
            'body'       => $replyText,
        ], 200);
    }

    // طلب سنة الصنع مرة واحدة لجميع الأصناف إذا لم تُعرف
    if (!$year) {
        if ($msgAskYear === '') {
            return response()->json(['status' => 'skipped_empty_ask_year_template'], 200);
        }
        $replyText = formatBotTemplate($msgAskYear, $baseTemplateVars);
        sendWhatsappReplyMessage($settings, $cleanPhone, $rawSender, $convId, $replyText);

        return response()->json([
            'status'     => 'success',
            'session_id' => $sessionId,
            'reply'      => $replyText,
            'message'    => $replyText,
            'text'       => $replyText,
            'body'       => $replyText,
        ], 200);
    }

    // 8. اكتملت البيانات (قائمة الأصناف + السيارة + سنة الصنع) -> البحث الدقيق عن كل صنف على حدة
    foreach ($mergedPartsList as $singlePartRequest) {
        $singlePartRequest = trim($singlePartRequest);
        if ($singlePartRequest === '') continue;

        $foundForThisPart = findMatchingItemsForSinglePart($singlePartRequest, $car, $year, $ownerUserId);

        if ($foundForThisPart->count() > 0) {
            foreach ($foundForThisPart as $matchedItem) {
                if ($msgAvailable !== '') {
                    $itemVars = $baseTemplateVars;
                    $itemVars['item_name']   = $matchedItem->item_name;
                    $itemVars['item_number'] = $matchedItem->item_number;
                    $itemVars['price']       = (string) $matchedItem->price;
                    $itemVars['quantity']    => (string) $matchedItem->quantity;
                    $itemVars['part']        = $singlePartRequest;
                    $itemVars['items']       => "- {$matchedItem->item_name} (رقم: {$matchedItem->item_number}) | السعر: {$matchedItem->price} ريال";

                    // تكرار قالب التوفر لكل صنف متوفر تحت بعضه
                    $replyBlocks[] = formatBotTemplate($msgAvailable, $itemVars);
                }
            }
        } else {
            $unavailablePartsNames[] = $singlePartRequest;
        }
    }

    // إرسال رد عدم التوفر مرة واحدة فقط في نهاية الرسالة بدون تكرار للأصناف غير الموجودة
    if (!empty($unavailablePartsNames) && $msgUnavailable !== '') {
        $missingSummary = implode(' ، ', array_unique($unavailablePartsNames));
        $unavailVars = $baseTemplateVars;
        $unavailVars['part']      = $missingSummary;
        $unavailVars['item_name'] = $missingSummary;
        $replyBlocks[] = formatBotTemplate($msgUnavailable, $unavailVars);
    }

    // مسح الذاكرة بعد اكتمال الطلب والرد
    DB::table('whatsapp_sessions')
        ->where('user_id', $ownerUserId)
        ->where('phone', $quotaPhoneKey)
        ->delete();

    // زيادة عداد الاستعلامات للزبون
    DB::table('whatsapp_customer_quotas')->updateOrInsert(
        ['user_id' => $ownerUserId, 'phone' => $quotaPhoneKey],
        [
            'queries_count' => $currentQueriesUsed + 1,
            'updated_at'    => now(),
            'created_at'    => $quotaRecord->created_at ?? now(),
        ]
    );

    $replyText = implode("\n\n", array_values(array_unique(array_filter($replyBlocks))));

    if (trim($replyText) === '') {
        return response()->json(['status' => 'no_reply_configured'], 200);
    }

    sendWhatsappReplyMessage($settings, $cleanPhone, $rawSender, $convId, $replyText);

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
    ], 200);
}
Route::post('/api/webhook/receiver', fn(Request $r) => handleWebhook($r));
Route::post('/api/webhook/receiver/', fn(Request $r) => handleWebhook($r));

require __DIR__.'/auth.php';

// معالجة تسجيل الدخول الموحد (إدارة أو عملاء) مع إرسال رمز التحقق عبر الواتساب
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

            // إرسال كود التحقق إلى واتساب العميل
            $sentViaWhatsapp = sendWhatsappLoginOtp($user->phone ?: $loginInput, $user->otp_code);

            return back()->withInput()
                         ->with('phone_for_otp', $loginInput)
                         ->with('whatsapp_otp_sent', $sentViaWhatsapp)
                         ->with('success', $sentViaWhatsapp ? 'تم إرسال رمز التحقق إلى رقمك في الواتساب بنجاح.' : $user->otp_code);
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
                    'price'      => $data['price'],
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
                            'price'      => $price,
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

    // 6. مسارات الرد الآلي والذكاء الاصطناعي والحدود والاستثناءات (مع تطبيق نفس البحث الدقيق في شاشة التجربة)
    Route::get('/ai-whatsapp', function (Request $request) {
        $userId = auth()->id();
        $customerMessage = (string) $request->query('message', '');
        $matchedItems = collect();

        if (trim($customerMessage) !== '') {
            $parsedTest = parseWhatsappText($customerMessage, $userId);

            if (!empty($parsedTest['part_numbers'])) {
                $matchedItems = Item::where('user_id', $userId)
                    ->whereIn('item_number', $parsedTest['part_numbers'])
                    ->get();
            }

            if ($matchedItems->isEmpty() && !empty($parsedTest['parts_list'])) {
                $testCar  = $parsedTest['car'] ?? '';
                $testYear = $parsedTest['year'] ?? '';

                foreach ($parsedTest['parts_list'] as $singleReq) {
                    $found = findMatchingItemsForSinglePart($singleReq, $testCar, $testYear, $userId);
                    foreach ($found as $fItem) {
                        $matchedItems->push($fItem);
                    }
                }
                $matchedItems = $matchedItems->unique('id')->values();
            }
        }

        $botSettings = getUserBotSettings($userId);
        $activeSessions = DB::table('whatsapp_sessions')
            ->where('user_id', $userId)
            ->latest('updated_at')
            ->take(15)
            ->get();

        $whitelistPhones = DB::table('whatsapp_whitelist_phones')
            ->where('user_id', $userId)
            ->latest()
            ->get();

        $customerQuotas = DB::table('whatsapp_customer_quotas')
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->take(20)
            ->get();

        return view('pages.second', [
            'customerMessage' => $customerMessage,
            'matchedItems'    => $matchedItems,
            'botSettings'     => $botSettings,
            'activeSessions'  => $activeSessions,
            'whitelistPhones' => $whitelistPhones,
            'customerQuotas'  => $customerQuotas,
        ]);
    })->name('pages.second');

    Route::post('/ai-whatsapp/settings', function (Request $request) {
        $userId = auth()->id();
        $fields = [
            'reply_available',
            'reply_unavailable',
            'reply_ask_car',
            'reply_ask_year',
            'reply_quota_exceeded',
            'max_queries_per_phone',
            'allow_group_replies',
            'whatsapp_api_url',
            'whatsapp_api_token',
            'whatsapp_session_id',
        ];

        foreach ($fields as $field) {
            if ($field === 'allow_group_replies') {
                $val = $request->boolean('allow_group_replies') || $request->input('allow_group_replies') === '1' ? '1' : '0';
                DB::table('user_bot_settings')->updateOrInsert(
                    ['user_id' => $userId, 'key' => 'allow_group_replies'],
                    ['value' => $val]
                );
                continue;
            }

            if ($request->exists($field)) {
                DB::table('user_bot_settings')->updateOrInsert(
                    ['user_id' => $userId, 'key' => $field],
                    ['value' => trim((string) $request->input($field, ''))]
                );
            }
        }

        return back()->with('success', 'تم حفظ إعدادات البوت وحدود الاستعلام وقوالب الرسائل بنجاح.');
    })->name('pages.second.settings');

    // إضافة رقم مستثنى (حد مفتوح لا نهائي)
    Route::post('/ai-whatsapp/whitelist', function (Request $request) {
        $userId = auth()->id();
        $rawPhone = trim((string) $request->input('phone', ''));
        $label    = trim((string) $request->input('label', ''));

        $normalized = normalizeWhatsappDigits($rawPhone);
        if ($normalized === '') {
            return back()->with('error', 'يرجى إدخال رقم جوال صحيح.');
        }

        DB::table('whatsapp_whitelist_phones')->updateOrInsert(
            ['user_id' => $userId, 'phone' => $normalized],
            ['label' => $label, 'updated_at' => now(), 'created_at' => now()]
        );

        return back()->with('success', "تمت إضافة الرقم ({$normalized}) إلى قائمة الاستثناء (استعلام مفتوح بدون حد).");
    })->name('pages.second.whitelist.add');

    Route::delete('/ai-whatsapp/whitelist/{id}', function ($id) {
        DB::table('whatsapp_whitelist_phones')
            ->where('id', $id)
            ->where('user_id', auth()->id())
            ->delete();
        return back()->with('success', 'تم حذف الرقم من قائمة الاستثناء.');
    })->name('pages.second.whitelist.delete');

    // تصفير عداد الاستعلامات لرقم معين أو للجميع
    Route::post('/ai-whatsapp/quotas/reset', function (Request $request) {
        $userId = auth()->id();
        if ($request->filled('quota_id')) {
            DB::table('whatsapp_customer_quotas')
                ->where('id', $request->input('quota_id'))
                ->where('user_id', $userId)
                ->update(['queries_count' => 0, 'updated_at' => now()]);
            return back()->with('success', 'تم تصفير عداد الاستعلامات لهذا الرقم.');
        }

        DB::table('whatsapp_customer_quotas')
            ->where('user_id', $userId)
            ->update(['queries_count' => 0, 'updated_at' => now()]);
        return back()->with('success', 'تم تصفير عداد الاستعلامات لجميع الأرقام بنجاح.');
    })->name('pages.second.quotas.reset');

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

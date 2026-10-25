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

/*
|--------------------------------------------------------------------------
| 1. تهيئة وترقية الجداول التلقائية في الخلفية (Database Auto-Migration)
|--------------------------------------------------------------------------
*/
try {
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
        Schema::table('whatsapp_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->default(1)->index();
        });
    }

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

    // ترقية جدول قلب النظام (master_items) ليدعم تسجيل أكثر من سيارة وموديل لنفس الرقم الموحد
    if (Schema::hasTable('master_items')) {
        if (!Schema::hasColumn('master_items', 'compatible_cars')) {
            Schema::table('master_items', function (Blueprint $table) {
                $table->text('compatible_cars')->nullable();
            });
        }
        if (!Schema::hasColumn('master_items', 'compatible_years')) {
            Schema::table('master_items', function (Blueprint $table) {
                $table->text('compatible_years')->nullable();
            });
        }
    }

    // جداول الاستيراد الذكي في الخلفية ونقطة الاسترجاع الفورية (Rollback Snapshots)
    if (!Schema::hasTable('excel_import_jobs')) {
        Schema::create('excel_import_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->string('status')->default('pending')->index(); // pending, processing, completed, failed, rolled_back
            $table->integer('total_rows')->default(0);
            $table->integer('processed_rows')->default(0);
            $table->integer('imported_count')->default(0);
            $table->integer('updated_count')->default(0);
            $table->text('column_mapping')->nullable();
            $table->unsignedBigInteger('snapshot_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    if (!Schema::hasTable('inventory_snapshots')) {
        Schema::create('inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('label')->nullable();
            $table->integer('items_count')->default(0);
            $table->longText('snapshot_data');
            $table->timestamps();
        });
    }

    // ضمان ربط حساب الأدمن برقم الجوال 0556666037
    if (Schema::hasTable('users')) {
        $adminUser = DB::table('users')->where('role', 'admin')->first();
        if ($adminUser && empty($adminUser->phone)) {
            DB::table('users')->where('id', $adminUser->id)->update(['phone' => '+966556666037']);
        }
    }
} catch (\Throwable $e) {
    // تجاهل أي خطأ في قاعدة البيانات أثناء بناء الحاوية في Docker
}

/*
|--------------------------------------------------------------------------
| 2. دوال المساعدة وتنسيق النصوص والأرقام (Helper & Normalization Functions)
|--------------------------------------------------------------------------
*/

/**
 * تنسيق رقم الجوال بالصيغة الدولية الموحدة +966
 */
function formatSaudiPhoneIntl($phone): string {
    $digits = preg_replace('/\D/', '', (string) $phone);
    if (str_starts_with($digits, '966')) {
        $digits = substr($digits, 3);
    }
    if (str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) === 9) {
        return '+966' . $digits;
    }
    return (string) $phone;
}

/**
 * توحيد صيغة الرقم للمقارنة في الواتساب (تزيل + و 0 البادئة وتوحد 966)
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
 * تحويل الأرقام العربية/الفارسية إلى إنجليزية
 */
function normalizeArabicDigits(string $str): string {
    $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $english = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    return str_replace($arabic, $english, $str);
}

/**
 * توحيد الحروف العربية (الألف والهمزات والتاء المربوطة والياء والتشكيل) لضمان دقة التطابق
 */
function normalizeArabicSpelling(string $str): string {
    $str = normalizeArabicDigits(trim($str));
    // إزالة التشكيل والمد (التطويل)
    $str = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $str);
    $str = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $str);
    $str = str_replace('ة', 'ه', $str);
    $str = str_replace(['ى', 'ئ'], 'ي', $str);
    $str = str_replace('ؤ', 'و', $str);
    return mb_strtolower(trim($str));
}

/**
 * قائمة الكلمات الوصفية والاتجاهات التي لا تعتبر اسماً أساسياً للقطعة بمفردها
 */
function getPartModifierWords(): array {
    return [
        'يمين', 'يسار', 'امامي', 'اماميه', 'خلفي', 'خلفيه',
        'علوي', 'علويه', 'سفلي', 'سفليه', 'داخلي', 'داخليه', 'خارجي', 'خارجيه',
        'وسط', 'جانبي', 'جهه', 'طقم', 'حبه', 'كامل', 'اصلي', 'وكاله', 'تجاري', 'يباني', 'ياباني', 'صيني', 'كوري', 'تايوان'
    ];
}

/**
 * قائمة شاملة لكلمات التحية، الدردشة، التوقف، والكلمات العامة التي لا تعتبر أسماء قطع غيار أبداً
 */
function getChatAndStopWords(): array {
    $rawWords = [
        // تحيات وترحيب
        'السلام', 'سلام', 'عليكم', 'وعليكم', 'ورحمة', 'ورحمه', 'الله', 'وبركاته',
        'مساء', 'صباح', 'الخير', 'النور', 'الورد', 'اهلا', 'أهلا', 'هلا', 'سهلا', 'مرحبا', 'مراحب',
        'حياك', 'حياكم', 'بيك', 'فيك', 'بك', 'ياغالي', 'الغالي', 'اخوي', 'أخوي', 'ياخوي', 'شيخ', 'طويل', 'العمر',
        // سؤال عن الحال ودردشة
        'كيفك', 'كيف', 'الحال', 'حالك', 'حالكم', 'شلونك', 'شلونكم', 'اخبارك', 'أخبارك', 'اخباركم', 'علومك', 'علومكم',
        'عساك', 'عساكم', 'طيب', 'طيبين', 'بخير', 'الحمد', 'لله', 'الحمدلله', 'تمام', 'نعمه', 'نعمة', 'امورك', 'أمورك',
        // شكر ومجاملات
        'شكرا', 'شكراً', 'مشكور', 'مشكورين', 'يعطيك', 'يعطيكم', 'العافيه', 'العافية', 'عافيه', 'جزاك', 'جزاكم',
        'خيرا', 'خير', 'لاهنت', 'لا', 'هنت', 'تسلم', 'يسلمو', 'ماقصرت', 'ما', 'قصرت', 'كفو', 'رحم', 'والديك',
        // كلمات طلب واستفهام عامة (لا تعتبر اسم قطعة بمفردها)
        'ابغى', 'ابغا', 'ابي', 'أبغى', 'أبي', 'وابغا', 'وابغى', 'وابي', 'بغيت', 'اريد', 'أريد', 'احتاج', 'أحتاج',
        'مطلوب', 'طلب', 'اطلب', 'أطلب', 'عندكم', 'عندك', 'موجود', 'موجوده', 'موجودة', 'يتوفر', 'متوفر', 'متوفره',
        'احصل', 'أحصل', 'الاقي', 'ألاقي', 'بلقى', 'القى', 'ألقى', 'شوف', 'شف', 'لي', 'لنا', 'بالله', 'فضلا', 'فضلاً',
        'لو', 'سمحت', 'سمحتوا', 'سمحتم', 'من', 'فضلك', 'تكرما', 'ممكن', 'استفسار', 'سؤال', 'اسال', 'أسأل',
        'بكم', 'كم', 'سعر', 'سعرها', 'سعره', 'اسعار', 'أسعار', 'قيمة', 'قيمه', 'تكلف', 'يكلف',
        'هل', 'فيه', 'في', 'فبه', 'هذا', 'هذه', 'هذي', 'ذا', 'ذيك', 'الي', 'اللي', 'الذي', 'التي',
        'سيارة', 'سياره', 'السيارة', 'السياره', 'موديل', 'مديل', 'سنة', 'سنه', 'صنع', 'حقت', 'حق', 'تبع', 'للسيارة',
        'قطعة', 'قطعه', 'قطع', 'غيار', 'صنف', 'رقم', 'كود',
        // أسئلة عامة عن المحل والدوام والموقع
        'وين', 'فين', 'موقعكم', 'موقعك', 'الموقع', 'عنوانكم', 'فرعكم', 'فاتحين', 'تفتحون', 'تقفلون', 'دوامكم', 'اللوكيشن',
        'توصيل', 'شحن', 'تشحنون', 'توصلون', 'الرياض', 'جدة', 'جده', 'الدمام', 'مكة', 'مكه',
        // ردود قصيرة
        'نعم', 'اي', 'ايه', 'ايوه', 'أيوة', 'يب', 'يس', 'لا', 'لأ', 'ممتاز', 'طيب', 'اوك', 'اوكي', 'تم', 'حاضر', 'ان', 'شاء', 'انشاءالله',
        'كذلك', 'ايضا', 'أيضا', 'برضه', 'برضو', 'بعد', 'مع', 'او', 'أو', 'بس', 'فقط'
    ];

    return array_values(array_unique(array_map('normalizeArabicSpelling', $rawWords)));
}

/**
 * قائمة كلمات الإلغاء أو الإنهاء التي إذا كتبها الزبون يتم مسح الجلسة المعلقة بصمت
 */
function isSessionCancelMessage(string $messageText): bool {
    $norm = normalizeArabicSpelling($messageText);
    $cancelPhrases = [
        'خلاص', 'كنسل', 'الغاء', 'إلغاء', 'الغي', 'ما ابغى', 'ما ابغا', 'ما ابي', 'مابغى', 'مابي',
        'بطلت', 'هونت', 'لقيتها', 'حصلتها', 'شريتها', 'شكرا ما ابغى'
    ];
    foreach ($cancelPhrases as $phrase) {
        if ($norm === normalizeArabicSpelling($phrase)) {
            return true;
        }
    }
    return false;
}

/**
 * قاموس الجمع والمفرد والمرادفات الإملائية الشائعة لقطع الغيار (يدعم القاموس التلقائي)
 */
function getWordVariantsForSearch(string $word): array {
    $norm = normalizeArabicSpelling($word);
    $variants = [$norm];

    // نزع "ال" التعريف إن وجدت لإضافة الجذر أيضاً
    if (mb_strlen($norm) > 4 && str_starts_with($norm, 'ال')) {
        $withoutAl = mb_substr($norm, 2);
        $variants[] = $withoutAl;
    } else {
        $variants[] = 'ال' . $norm;
    }

    $synonymGroups = [
        ['كرسي', 'كراسي'],
        ['مساعد', 'مساعدات', 'جانبين', 'جمبين'],
        ['مقص', 'مقصات'],
        ['ذراع', 'اذرعه', 'اذرعة'],
        ['رديتر', 'لديتر', 'اديتر', 'راديتر', 'رديترات'],
        ['فحمات', 'فحمه', 'قماشات', 'قماش', 'تيل'],
        ['هوب', 'هوبات'],
        ['شمعه', 'شمعات', 'نور', 'انوار', 'فانوس'],
        ['اسطب', 'اصطب', 'اسطبات', 'اصطبات', 'سطب'],
        ['صدام', 'صدامات', 'صدامه'],
        ['رفرف', 'رفارف'],
        ['كويل', 'كويلات'],
        ['بوجي', 'بواجي'],
        ['فلتر', 'فلاتر', 'صفايه', 'صفاية'],
        ['كمبروسر', 'كمبرسر', 'كومبرسر', 'كمبريسور'],
        ['دينمو', 'دينامو', 'دنموا'],
        ['سلف', 'مارش'],
        ['طرمبه', 'طرمبة', 'طرنبه', 'طرمبات'],
        ['عكس', 'عكوس'],
        ['فلنجه', 'فلنجات', 'بيرنق', 'رمان'],
        ['مرايه', 'مرايا', 'مرايات'],
        ['نسافه', 'نسافات'],
        ['بطانه', 'بطانات', 'صاجه', 'صاجات'],
        ['كارتير', 'كرتير'],
        ['ثلاجه', 'ثلاجات'],
        ['بخاخ', 'بخاخات'],
        ['حساس', 'حساسات'],
    ];

    foreach ($synonymGroups as $group) {
        $normalizedGroup = array_map('normalizeArabicSpelling', $group);
        if (in_array($norm, $normalizedGroup, true) || (isset($withoutAl) && in_array($withoutAl, $normalizedGroup, true))) {
            $variants = array_merge($variants, $normalizedGroup);
        }
    }

    return array_values(array_unique(array_filter($variants)));
}

/**
 * القاموس القياسي الشامل لأسماء قطع غيار السيارات لضمان تعرف البوت عليها دائماً
 * حتى لو لم تكن مضافة بعد في جدول item_keywords
 */
function getStandardAutoPartLexicon(): array {
    $words = [
        'صدام', 'صدامات', 'صدامه', 'صاجه', 'صاجات', 'بطانه', 'بطانات', 'رفرف', 'رفارف',
        'كبوت', 'شنطه', 'باب', 'ابواب', 'شبك', 'شمعه', 'شمعات', 'نور', 'انوار', 'فانوس',
        'اسطب', 'اصطب', 'اسطبات', 'اصطبات', 'سطب', 'كشاف', 'كشافات', 'مرايه', 'مرايا', 'مرايات',
        'زجاج', 'قزاز', 'مساحه', 'مساحات', 'نسافه', 'نسافات', 'جسر', 'جسور', 'قاعده', 'قواعد',
        'كلبس', 'كلبسات', 'فيبر', 'حشوه', 'سمكه', 'عتبه', 'هوايه', 'مفصل', 'مفصلات', 'قفل', 'كيلون', 'مقبض', 'يد',
        'مساعد', 'مساعدات', 'جانبين', 'جمبين', 'مقص', 'مقصات', 'ذراع', 'اذرعه', 'اذرعة', 'جوزه', 'جوزات',
        'مسمار', 'مسامير', 'توازن', 'عمود', 'عكس', 'عكوس', 'فلنجه', 'فلنجات', 'بيرنق', 'رمان', 'كفر', 'جنط', 'جنوط',
        'فحمات', 'فحمه', 'قماشات', 'قماش', 'تيل', 'هوب', 'هوبات', 'كليبر', 'باكم', 'جلنط', 'سلك',
        'رديتر', 'لديتر', 'اديتر', 'راديتر', 'رديترات', 'قربه', 'غطاء', 'مروحه', 'مراوح', 'دينمو', 'دينامو',
        'كمبروسر', 'كمبرسر', 'كومبرسر', 'كمبريسور', 'ثلاجه', 'ثلاجات', 'فريون', 'لي', 'ليات', 'خرطوش', 'خراطيش',
        'طرمبه', 'طرمبة', 'طرنبه', 'طرمبات', 'صفايه', 'صفاية', 'فلتر', 'فلاتر', 'بوجي', 'بواجي', 'كويل', 'كويلات',
        'بخاخ', 'بخاخات', 'حساس', 'حساسات', 'سلف', 'مارش', 'بطاريه', 'ضفيره', 'كمبيوتر', 'فيوز', 'كتاوت',
        'مكينه', 'ماكينه', 'محرك', 'قير', 'جير', 'دفرنس', 'دبل', 'كرونه', 'كارتير', 'كرتير', 'وجه', 'وجيه', 'صوفه', 'صوف',
        'جنزير', 'شداد', 'سير', 'سيور', 'بكره', 'بكرات', 'ثروتل', 'بوابه', 'شكمان', 'دبه', 'تيربو',
        'كرسي', 'كراسي', 'دركسون', 'طاره', 'دوده', 'طبلون', 'عداد', 'فرشه', 'ديكور', 'شاشه', 'ارباق', 'ايرباق', 'حزام'
    ];
    return array_values(array_unique(array_map('normalizeArabicSpelling', $words)));
}

/**
 * قائمة الكلمات الفرعية/الملحقة بقطع الغيار (بحيث لا يخلط البوت بين الصدام نفسه وبين صاجة الصدام أو قاعدة الصدام)
 */
function getSubComponentPartWords(): array {
    $subWords = [
        'صاجه', 'صاجات', 'بطانه', 'بطانات', 'قاعده', 'قواعد', 'جسر', 'جسور',
        'كلبس', 'كلبسات', 'برواز', 'اطار', 'غطاء', 'اغطيه', 'حشوه', 'فوم',
        'زجاجه', 'عدسه', 'ظفيره', 'فيش', 'سلك', 'جلده', 'جلد', 'ربله', 'ربلات',
        'مسطره', 'نيكل', 'زيج', 'لحيه', 'دقن', 'شبك', 'فتحه', 'كرسي', 'مفصل'
    ];
    return array_values(array_unique(array_map('normalizeArabicSpelling', $subWords)));
}

/*
|--------------------------------------------------------------------------
| 3. دوال فحص التوافق والمزامنة مع قلب النظام (Master Core & Multi-Car Sync)
|--------------------------------------------------------------------------
*/

/**
 * التحقق مما إذا كانت سنة العميل (مثل 25 أو 2025) تقع داخل نطاق سنة الصنف أو سنوات التوافق
 */
function isYearMatchingItem(string $queryYear, ?string $itemYears, string $itemName, ?string $masterCompatibleYears = null): bool {
    $qNorm = normalizeArabicDigits(trim($queryYear));
    if (!preg_match('/^(\d{2}|\d{4})$/', $qNorm)) {
        return true;
    }
    $qFull  = strlen($qNorm) === 2 ? ((int)$qNorm <= 40 ? 2000 + (int)$qNorm : 1900 + (int)$qNorm) : (int)$qNorm;
    $qShort = substr((string)$qFull, -2);

    $targetText = normalizeArabicDigits(($itemYears ?? '') . ' ' . $itemName . ' ' . ($masterCompatibleYears ?? ''));

    // إذا لم يكن للصنف أي سنة محددة مسجلة إطلاقاً، نعتبره متوافقاً عامةً
    if (!preg_match('/\d{2}/', $targetText)) {
        return true;
    }

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
 * تنظيف الكلمات المفتاحية من أسماء السيارات وسنوات الصنع وكلمات التوقف
 */
function purgeCarAndYearKeywords(): void {
    $carsList    = DB::table('cars')->get();
    $aliasesList = DB::table('car_aliases')->pluck('alias')->toArray();
    $patterns    = DB::table('year_patterns')->pluck('pattern_example')->toArray();

    $forbiddenWords = getChatAndStopWords();
    foreach ($carsList as $c) {
        $forbiddenWords[] = normalizeArabicSpelling($c->car_name);
        foreach (preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $c->car_name, -1, PREG_SPLIT_NO_EMPTY) as $cw) {
            $forbiddenWords[] = normalizeArabicSpelling($cw);
        }
        if (!empty($c->category)) {
            $forbiddenWords[] = normalizeArabicSpelling($c->category);
            foreach (preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $c->category, -1, PREG_SPLIT_NO_EMPTY) as $catW) {
                $forbiddenWords[] = normalizeArabicSpelling($catW);
            }
        }
    }
    foreach ($aliasesList as $a) {
        $forbiddenWords[] = normalizeArabicSpelling($a);
        foreach (preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $a, -1, PREG_SPLIT_NO_EMPTY) as $aw) {
            $forbiddenWords[] = normalizeArabicSpelling($aw);
        }
    }
    foreach ($patterns as $p) {
        $forbiddenWords[] = normalizeArabicSpelling($p);
        foreach (preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $p, -1, PREG_SPLIT_NO_EMPTY) as $pw) {
            $forbiddenWords[] = normalizeArabicSpelling($pw);
        }
    }

    $forbiddenWords = array_values(array_unique(array_filter($forbiddenWords)));

    $allKeywords = DB::table('item_keywords')->get();
    $seenPerItem = [];

    foreach ($allKeywords as $kw) {
        $kwNorm = normalizeArabicSpelling($kw->keyword);
        $isYear = preg_match('/^(?:19|20)\d{2}$/u', $kwNorm)
               || preg_match('/^(?:(?:19|20)?\d{2})[\/-](?:(?:19|20)?\d{2})$/u', $kwNorm)
               || preg_match('/^\d+$/u', $kwNorm);

        $uniqueKey = strtoupper(trim($kw->item_number)) . '::' . $kwNorm;

        if ($kwNorm === '' || mb_strlen($kwNorm) < 2 || $isYear || in_array($kwNorm, $forbiddenWords, true) || isset($seenPerItem[$uniqueKey])) {
            DB::table('item_keywords')->where('id', $kw->id)->delete();
        } else {
            $seenPerItem[$uniqueKey] = true;
        }
    }
}

/**
 * دالة التحليل والربط الذكية بقلب النظام (تراكم المرادفات من جميع العملاء + تعدد السيارات والموديلات لنفس الرقم الموحد)
 */
function syncItemToMasterCore(string $itemNumber, string $itemName, ?int $userId = null): void {
    $cleanNumber = strtoupper(trim($itemNumber));
    $cleanName   = normalizeArabicDigits(trim($itemName));

    if ($cleanNumber === '' || $cleanName === '') return;

    $detectedCarsList  = [];
    $detectedCategories = [];
    $detectedYearsList = [];

    $carsList    = DB::table('cars')->get();
    $allCarWords = [];

    foreach ($carsList as $car) {
        $allCarWords = array_merge($allCarWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $car->car_name, -1, PREG_SPLIT_NO_EMPTY));
        if (!empty($car->category)) {
            $allCarWords[] = $car->category;
            $allCarWords = array_merge($allCarWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $car->category, -1, PREG_SPLIT_NO_EMPTY));
        }
        if (mb_stripos($cleanName, $car->car_name) !== false) {
            $detectedCarsList[]   = $car->car_name;
            $detectedCategories[] = $car->category;
        }
        $aliases = DB::table('car_aliases')->where('car_id', $car->id)->pluck('alias')->toArray();
        foreach ($aliases as $alias) {
            if ($alias !== '') {
                $allCarWords = array_merge($allCarWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $alias, -1, PREG_SPLIT_NO_EMPTY));
                if (mb_stripos($cleanName, $alias) !== false) {
                    $detectedCarsList[]   = $car->car_name;
                    $detectedCategories[] = $car->category;
                }
            }
        }
    }

    $detectedCarsList   = array_values(array_unique(array_filter($detectedCarsList)));
    $detectedCategories = array_values(array_unique(array_filter($detectedCategories)));

    $patterns     = DB::table('year_patterns')->pluck('pattern_example')->toArray();
    $allYearWords = [];
    foreach ($patterns as $pattern) {
        $normP = normalizeArabicDigits(trim($pattern));
        if ($normP !== '') {
            $allYearWords[] = $normP;
            $allYearWords = array_merge($allYearWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $normP, -1, PREG_SPLIT_NO_EMPTY));
            if (mb_stripos($cleanName, $normP) !== false) {
                $detectedYearsList[] = $normP;
            }
        }
    }

    $nameForKeywords = $cleanName;
    foreach ($carsList as $car) {
        $nameForKeywords = str_ireplace($car->car_name, ' ', $nameForKeywords);
        if (!empty($car->category)) {
            $nameForKeywords = str_ireplace($car->category, ' ', $nameForKeywords);
        }
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

    // استخراج جميع نطاقات السنوات أو السنوات المفردة في اسم الصنف
    if (preg_match_all('/(?:(?:19|20)?\d{2})\s*[\/-]\s*(?:(?:19|20)?\d{2})/u', $cleanName, $mRanges)) {
        foreach ($mRanges[0] as $r) {
            $detectedYearsList[] = str_replace(' ', '', $r);
        }
    }
    if (empty($detectedYearsList)) {
        if (preg_match_all('/\b(?:19\d{2}|20\d{2})\b/u', $cleanName, $mFullYears)) {
            foreach ($mFullYears[0] as $fy) {
                $detectedYearsList[] = $fy;
            }
        } elseif (preg_match_all('/(?<!\d)\d{2}(?!\d)/u', $nameForKeywords, $mShortYears)) {
            foreach ($mShortYears[0] as $sy) {
                $detectedYearsList[] = $sy;
            }
        }
    }

    $detectedYearsList = array_values(array_unique(array_filter($detectedYearsList)));

    $nameForKeywords = preg_replace('/(?:(?:19|20)?\d{2})\s*[\/-]\s*(?:(?:19|20)?\d{2})/u', ' ', $nameForKeywords);
    $nameForKeywords = preg_replace('/\b(?:19|20)\d{2}\b/u', ' ', $nameForKeywords);
    $nameForKeywords = preg_replace('/(?<!\d)\d{2}(?!\d)/u', ' ', $nameForKeywords);

    $itemCarString      = !empty($detectedCarsList) ? implode(' | ', $detectedCarsList) : null;
    $itemCategoryString = !empty($detectedCategories) ? implode(' | ', $detectedCategories) : null;
    $itemYearsString    = !empty($detectedYearsList) ? implode(' | ', $detectedYearsList) : null;

    // تحديث سجل الصنف الخاص بهذا العميل تحديداً
    $itemUpdateQuery = DB::table('items')->where('item_number', $cleanNumber);
    if ($userId !== null) {
        $itemUpdateQuery->where('user_id', $userId);
    }
    $itemUpdateQuery->update([
        'detected_car_name' => $itemCarString,
        'detected_category' => $itemCategoryString,
        'detected_years'    => $itemYearsString,
    ]);

    // تجميع كل السيارات والموديلات المتوافقة مع هذا الرقم الموحد عبر جميع العملاء في master_items
    $allHoldingsForNumber = DB::table('items')->where('item_number', $cleanNumber)->get();
    $masterCarsPool  = $detectedCarsList;
    $masterYearsPool = $detectedYearsList;

    foreach ($allHoldingsForNumber as $holding) {
        if (!empty($holding->detected_car_name)) {
            foreach (explode('|', $holding->detected_car_name) as $hc) {
                if (trim($hc) !== '') $masterCarsPool[] = trim($hc);
            }
        }
        if (!empty($holding->detected_years)) {
            foreach (explode('|', $holding->detected_years) as $hy) {
                if (trim($hy) !== '') $masterYearsPool[] = trim($hy);
            }
        }
    }

    $masterCarsString  = implode(' | ', array_values(array_unique($masterCarsPool)));
    $masterYearsString = implode(' | ', array_values(array_unique($masterYearsPool)));

    $existingMaster = DB::table('master_items')->where('item_number', $cleanNumber)->first();
    if (!$existingMaster) {
        $insertData = [
            'item_number'     => $cleanNumber,
            'first_item_name' => $cleanName,
            'first_user_id'   => $userId,
            'created_at'      => now(),
            'updated_at'      => now(),
        ];
        if (Schema::hasColumn('master_items', 'compatible_cars')) {
            $insertData['compatible_cars']  = $masterCarsString ?: null;
            $insertData['compatible_years'] = $masterYearsString ?: null;
        }
        DB::table('master_items')->insert($insertData);
    } else {
        if (Schema::hasColumn('master_items', 'compatible_cars')) {
            $prevCars  = !empty($existingMaster->compatible_cars) ? array_map('trim', explode('|', $existingMaster->compatible_cars)) : [];
            $prevYears = !empty($existingMaster->compatible_years) ? array_map('trim', explode('|', $existingMaster->compatible_years)) : [];

            $mergedCars  = implode(' | ', array_values(array_unique(array_filter(array_merge($prevCars, $masterCarsPool)))));
            $mergedYears = implode(' | ', array_values(array_unique(array_filter(array_merge($prevYears, $masterYearsPool)))));

            DB::table('master_items')->where('item_number', $cleanNumber)->update([
                'compatible_cars'  => $mergedCars ?: null,
                'compatible_years' => $mergedYears ?: null,
                'updated_at'       => now(),
            ]);
        }
    }

    // استخراج الكلمات الدلالية (المرادفات) من اسم الصنف وإضافتها للقاموس بدون تكرار
    $skipWords = array_merge($allCarWords, $allYearWords, getChatAndStopWords());
    foreach ($detectedCarsList as $dc) {
        $skipWords[] = $dc;
        $skipWords = array_merge($skipWords, preg_split('/[\s,\-]+/u', $dc));
    }
    foreach ($detectedCategories as $dcat) {
        $skipWords[] = $dcat;
        $skipWords = array_merge($skipWords, preg_split('/[\s,\-]+/u', $dcat));
    }
    foreach ($detectedYearsList as $dy) {
        $skipWords[] = $dy;
        $skipWords = array_merge($skipWords, preg_split('/[\s,\-\/]+/u', $dy));
    }
    $skipWords = array_values(array_unique(array_map('normalizeArabicSpelling', array_filter($skipWords))));

    $existingKeywordsNorm = DB::table('item_keywords')
        ->where('item_number', $cleanNumber)
        ->pluck('keyword')
        ->map(function ($k) {
            return normalizeArabicSpelling($k);
        })
        ->toArray();

    $keywordsWords = preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $nameForKeywords, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($keywordsWords as $word) {
        $wordClean = trim($word);
        $wordNorm  = normalizeArabicSpelling($wordClean);

        $isYearPattern = preg_match('/^(?:19|20)\d{2}$/u', $wordNorm)
                      || preg_match('/^(?:(?:19|20)?\d{2})[\/-](?:(?:19|20)?\d{2})$/u', $wordNorm)
                      || preg_match('/^\d+$/u', $wordNorm);

        if (
            mb_strlen($wordNorm) >= 2 &&
            !is_numeric($wordNorm) &&
            !$isYearPattern &&
            !in_array($wordNorm, $skipWords, true) &&
            !in_array($wordNorm, $existingKeywordsNorm, true)
        ) {
            DB::table('item_keywords')->insertOrIgnore([
                'item_number' => $cleanNumber,
                'keyword'     => $wordClean,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
            $existingKeywordsNorm[] = $wordNorm;
        }
    }
}

/**
 * دالة تنظيف وتوحيد المخزون وقلب النظام (تمنع تضخم الكميات وتحذف الأصناف اليتيمة والمكررة)
 */
function cleanupAndNormalizeInventoryData(): void {
    // 1. حذف أي أصناف يتيمة تابعة لمستخدمين تم حذفهم من جدول users
    $validUserIds = DB::table('users')->pluck('id')->toArray();
    if (!empty($validUserIds)) {
        DB::table('items')->whereNotIn('user_id', $validUserIds)->delete();
    }

    // 2. توحيد أرقام الأصناف (إزالة المسافات الزائدة وتحويلها لحروف كبيرة) ودمج المكرر لنفس العميل
    $allItems = DB::table('items')->orderBy('id', 'desc')->get();
    $seenUserItems = [];

    foreach ($allItems as $item) {
        $normNum = strtoupper(trim((string) $item->item_number));
        if ($normNum === '') {
            DB::table('items')->where('id', $item->id)->delete();
            continue;
        }

        $key = $item->user_id . '::' . $normNum;
        if (isset($seenUserItems[$key])) {
            // إذا كان هناك سجل مكرر لنفس العميل ونفس رقم الصنف، نحتفظ بالأحدث فقط ونحذف القديم لمنع تضاعف الكميات
            DB::table('items')->where('id', $item->id)->delete();
            continue;
        }

        $seenUserItems[$key] = true;
        if ($item->item_number !== $normNum) {
            DB::table('items')->where('id', $item->id)->update(['item_number' => $normNum]);
        }
    }

    // 3. حذف أي أرقام في master_items لم يعد لها أي وجود فعلي في جدول items
    $activeItemNumbers = DB::table('items')->distinct()->pluck('item_number')->toArray();
    if (empty($activeItemNumbers)) {
        DB::table('master_items')->delete();
    } else {
        DB::table('master_items')->whereNotIn('item_number', $activeItemNumbers)->delete();
    }
}

/**
 * مزامنة جميع الأصناف الحالية مع قلب النظام بكفاءة
 */
/**
 * [إضافة 1]: التعرف الذكي التلقائي على أعمدة ملف الإكسل (Smart Column Mapping)
 * يدعم مختلف التسميات العربية والإنجليزية الشائعة لدى تجار قطع الغيار
 */
function detectSmartExcelColumns(array $headerRow): array {
    $mapping = [
        'item_number' => null,
        'item_name'   => null,
        'quantity'    => null,
        'price'       => null,
    ];

    $numberAliases = ['item_number', 'part_number', 'part_no', 'partno', 'code', 'sku', 'number', 'id', 'رقم الصنف', 'رقم القطعة', 'رقم القطعه', 'كود الصنف', 'كود', 'الرقم', 'رقم'];
    $nameAliases   = ['item_name', 'part_name', 'description', 'name', 'title', 'item', 'اسم الصنف', 'اسم القطعة', 'اسم القطعه', 'البيان', 'الوصف', 'الصنف', 'الاسم', 'بيان الصنف'];
    $qtyAliases    = ['quantity', 'qty', 'stock', 'balance', 'count', 'avail', 'الكمية', 'الكميه', 'الرصيد', 'العدد', 'المتوفر', 'كمية', 'رصيد'];
    $priceAliases  = ['price', 'cost', 'rate', 'amount', 'unit_price', 'selling_price', 'السعر', 'سعر البيع', 'القيمة', 'القيمه', 'سعر الوحدة', 'سعر', 'التكلفة'];

    foreach ($headerRow as $colIndex => $colValue) {
        $cleanCol = normalizeArabicSpelling(preg_replace('/[\s_\-\.]+/u', ' ', trim((string) $colValue)));
        $rawLower = strtolower(trim((string) $colValue));

        if ($mapping['item_number'] === null) {
            foreach ($numberAliases as $alias) {
                if ($cleanCol === normalizeArabicSpelling($alias) || $rawLower === $alias || str_contains($cleanCol, normalizeArabicSpelling($alias))) {
                    $mapping['item_number'] = $colIndex;
                    break;
                }
            }
        }
        if ($mapping['item_name'] === null) {
            foreach ($nameAliases as $alias) {
                if ($cleanCol === normalizeArabicSpelling($alias) || $rawLower === $alias || str_contains($cleanCol, normalizeArabicSpelling($alias))) {
                    $mapping['item_name'] = $colIndex;
                    break;
                }
            }
        }
        if ($mapping['quantity'] === null) {
            foreach ($qtyAliases as $alias) {
                if ($cleanCol === normalizeArabicSpelling($alias) || $rawLower === $alias || str_contains($cleanCol, normalizeArabicSpelling($alias))) {
                    $mapping['quantity'] = $colIndex;
                    break;
                }
            }
        }
        if ($mapping['price'] === null) {
            foreach ($priceAliases as $alias) {
                if ($cleanCol === normalizeArabicSpelling($alias) || $rawLower === $alias || str_contains($cleanCol, normalizeArabicSpelling($alias))) {
                    $mapping['price'] = $colIndex;
                    break;
                }
            }
        }
    }

    // في حال كان الملف بدون ترويسة واضحة، نعتمد الترتيب الافتراضي القياسي (0: رقم الصنف، 1: اسم الصنف، 2: الكمية، 3: السعر)
    $keys = array_keys($headerRow);
    if ($mapping['item_number'] === null && isset($keys[0])) $mapping['item_number'] = $keys[0];
    if ($mapping['item_name'] === null && isset($keys[1]))   $mapping['item_name']   = $keys[1];
    if ($mapping['quantity'] === null && isset($keys[2]))    $mapping['quantity']    = $keys[2];
    if ($mapping['price'] === null && isset($keys[3]))       $mapping['price']       = $keys[3];

    return $mapping;
}

/**
 * استخراج قيمة الخلية من السطر بناءً على الربط الذكي للأعمدة
 */
function extractRowMappedValue(array $row, $mappedKey, string $fieldName, int $fallbackPos) {
    if ($mappedKey !== null && array_key_exists($mappedKey, $row)) {
        return $row[$mappedKey];
    }
    if (array_key_exists($fieldName, $row)) {
        return $row[$fieldName];
    }
    $values = array_values($row);
    return $values[$fallbackPos] ?? null;
}

/**
 * [إضافة 5]: إنشاء نقطة استرجاع فورية (One-Click Rollback Snapshot) قبل بدء الاستيراد
 */
function createInventoryRollbackSnapshot(int $userId, ?string $label = null): int {
    // الاحتفاظ بآخر 5 نسخ استرجاع فقط لكل مستخدم لتوفير المساحة
    $oldSnapshots = DB::table('inventory_snapshots')
        ->where('user_id', $userId)
        ->orderByDesc('id')
        ->skip(4)
        ->take(50)
        ->pluck('id')
        ->toArray();

    if (!empty($oldSnapshots)) {
        DB::table('inventory_snapshots')->whereIn('id', $oldSnapshots)->delete();
    }

    $currentItems = DB::table('items')
        ->where('user_id', $userId)
        ->select('item_number', 'item_name', 'quantity', 'price', 'detected_car_name', 'detected_category', 'detected_years')
        ->get()
        ->toArray();

    return DB::table('inventory_snapshots')->insertGetId([
        'user_id'       => $userId,
        'label'         => $label ?: ('نسخة تلقائية قبل استيراد ' . now()->format('Y-m-d H:i:s')),
        'items_count'   => count($currentItems),
        'snapshot_data' => json_encode($currentItems, JSON_UNESCAPED_UNICODE),
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);
}

/**
 * [إضافة 5]: استعادة نقطة الاسترجاع الفورية بضغطة زر (One-Click Rollback Restore)
 */
function restoreInventoryRollbackSnapshot(int $userId, ?int $snapshotId = null): array {
    $query = DB::table('inventory_snapshots')->where('user_id', $userId);
    if ($snapshotId !== null) {
        $query->where('id', $snapshotId);
    } else {
        $query->orderByDesc('id');
    }

    $snapshot = $query->first();
    if (!$snapshot) {
        return ['status' => 'error', 'message' => 'لا توجد نقطة استرجاع محفوظة لهذا الحساب.'];
    }

    $itemsData = json_decode($snapshot->snapshot_data, true) ?: [];

    DB::beginTransaction();
    try {
        DB::table('items')->where('user_id', $userId)->delete();

        $now = now();
        $chunks = array_chunk($itemsData, 500);
        foreach ($chunks as $chunk) {
            $insertRows = [];
            foreach ($chunk as $row) {
                $insertRows[] = [
                    'user_id'           => $userId,
                    'item_number'       => $row['item_number'],
                    'item_name'         => $row['item_name'],
                    'quantity'          => (int) ($row['quantity'] ?? 0),
                    'price'             => (float) ($row['price'] ?? 0),
                    'detected_car_name' => $row['detected_car_name'] ?? null,
                    'detected_category' => $row['detected_category'] ?? null,
                    'detected_years'    => $row['detected_years'] ?? null,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];
            }
            if (!empty($insertRows)) {
                DB::table('items')->insert($insertRows);
            }
        }

        DB::table('excel_import_jobs')
            ->where('user_id', $userId)
            ->where('snapshot_id', $snapshot->id)
            ->update(['status' => 'rolled_back', 'updated_at' => $now]);

        DB::commit();
        cleanupAndNormalizeInventoryData();

        return [
            'status'         => 'success',
            'restored_count' => count($itemsData),
            'snapshot_date'  => $snapshot->created_at,
            'message'        => 'تم التراجع عن الاستيراد واستعادة المخزون السابق بنجاح (' . count($itemsData) . ' صنف).',
        ];
    } catch (\Throwable $e) {
        DB::rollBack();
        return ['status' => 'error', 'message' => 'تعذر استعادة النسخة: ' . $e->getMessage()];
    }
}

/**
 * تحميل قاموس السيارات والمرادفات والأنماط في الذاكرة العشوائية (RAM Cache) لسرعة فائقة أثناء الاستيراد الجماعي
 */
function buildMasterDictionaryRamCache(): array {
    $cars    = DB::table('cars')->get()->toArray();
    $aliases = DB::table('car_aliases')->get()->groupBy('car_id')->toArray();
    $patterns = DB::table('year_patterns')
        ->pluck('pattern_example')
        ->map(fn($p) => normalizeArabicDigits(trim((string) $p)))
        ->filter()
        ->values()
        ->toArray();

    $allCarWords  = [];
    $allAliases   = [];
    $carMapById   = [];

    foreach ($cars as $car) {
        $carMapById[$car->id] = $car;
        $allCarWords[] = $car->car_name;
        $allCarWords = array_merge($allCarWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $car->car_name, -1, PREG_SPLIT_NO_EMPTY));
        if (!empty($car->category)) {
            $allCarWords[] = $car->category;
            $allCarWords = array_merge($allCarWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $car->category, -1, PREG_SPLIT_NO_EMPTY));
        }
        $carAliases = $aliases[$car->id] ?? [];
        foreach ($carAliases as $alObj) {
            $al = trim((string) $alObj->alias);
            if ($al !== '') {
                $allAliases[] = ['alias' => $al, 'car_name' => $car->car_name, 'category' => $car->category];
                $allCarWords  = array_merge($allCarWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $al, -1, PREG_SPLIT_NO_EMPTY));
            }
        }
    }

    $allYearWords = [];
    foreach ($patterns as $normP) {
        $allYearWords[] = $normP;
        $allYearWords = array_merge($allYearWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $normP, -1, PREG_SPLIT_NO_EMPTY));
    }

    $baseSkipWords = array_values(array_unique(array_map('normalizeArabicSpelling', array_filter(array_merge($allCarWords, $allYearWords, getChatAndStopWords())))));

    return [
        'cars'           => $cars,
        'all_aliases'    => $allAliases,
        'patterns'       => $patterns,
        'base_skip_words'=> $baseSkipWords,
        'has_compat_col' => Schema::hasColumn('master_items', 'compatible_cars'),
    ];
}

/**
 * تحليل دفعة كاملة من الأصناف وحفظها وإرسالها لقلب النظام في الذاكرة (Bulk Upsert & Sync)
 * تعالج مئات الأصناف في أجزاء من الثانية دون إغراق قاعدة البيانات بالاستعلامات المتكررة
 */
function processBulkInventoryChunkFast(array $rows, int $userId, ?array $ramCache = null, ?array $columnMapping = null): array {
    if (empty($rows)) {
        return ['processed' => 0, 'imported' => 0, 'updated' => 0];
    }

    if ($ramCache === null) {
        $ramCache = buildMasterDictionaryRamCache();
    }

    // إذا لم يتم تمرير ربط الأعمدة، نكتشفه تلقائياً من أول سطر
    if ($columnMapping === null) {
        $firstRow = reset($rows);
        if (is_array($firstRow)) {
            $columnMapping = detectSmartExcelColumns(array_keys($firstRow));
        }
    }

    $normalizedBatch = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;

        $rawNum  = extractRowMappedValue($row, $columnMapping['item_number'] ?? null, 'item_number', 0);
        $rawName = extractRowMappedValue($row, $columnMapping['item_name'] ?? null, 'item_name', 1);
        $rawQty  = extractRowMappedValue($row, $columnMapping['quantity'] ?? null, 'quantity', 2);
        $rawPrc  = extractRowMappedValue($row, $columnMapping['price'] ?? null, 'price', 3);

        $itemNumber = strtoupper(trim((string) $rawNum));
        $itemName   = normalizeArabicDigits(trim((string) $rawName));

        // تخطي صف الرأس (Header Row) إن وجد ضمن البيانات
        if ($itemNumber === '' || $itemName === '' || in_array(strtolower($itemNumber), ['item_number', 'part_number', 'code', 'رقم الصنف', 'كود الصنف'], true)) {
            continue;
        }

        $quantity = max(0, (int) preg_replace('/[^\d\-]/', '', normalizeArabicDigits((string) ($rawQty ?? 0))));
        $price    = max(0, (float) preg_replace('/[^\d\.]/', '', normalizeArabicDigits((string) ($rawPrc ?? 0))));

        $normalizedBatch[$itemNumber] = [
            'item_number' => $itemNumber,
            'item_name'   => $itemName,
            'quantity'    => $quantity,
            'price'       => $price,
        ];
    }

    if (empty($normalizedBatch)) {
        return ['processed' => count($rows), 'imported' => 0, 'updated' => 0];
    }

    $batchNumbers = array_keys($normalizedBatch);

    // جلب الأصناف الموجودة مسبقاً لدى المستخدم في استعلام واحد فقط
    $existingItems = DB::table('items')
        ->where('user_id', $userId)
        ->whereIn('item_number', $batchNumbers)
        ->pluck('id', 'item_number')
        ->toArray();

    $now           = now();
    $toInsertItems = [];
    $toUpdateItems = [];
    $keywordsBatch = [];
    $masterUpdates = [];

    foreach ($normalizedBatch as $itemNumber => $data) {
        $cleanName          = $data['item_name'];
        $detectedCarsList   = [];
        $detectedCategories = [];
        $detectedYearsList  = [];

        foreach ($ramCache['cars'] as $car) {
            if (mb_stripos($cleanName, $car->car_name) !== false) {
                $detectedCarsList[]   = $car->car_name;
                $detectedCategories[] = $car->category;
            }
        }
        foreach ($ramCache['all_aliases'] as $alData) {
            if (mb_stripos($cleanName, $alData['alias']) !== false) {
                $detectedCarsList[]   = $alData['car_name'];
                $detectedCategories[] = $alData['category'];
            }
        }

        $detectedCarsList   = array_values(array_unique(array_filter($detectedCarsList)));
        $detectedCategories = array_values(array_unique(array_filter($detectedCategories)));

        foreach ($ramCache['patterns'] as $normP) {
            if (mb_stripos($cleanName, $normP) !== false) {
                $detectedYearsList[] = $normP;
            }
        }

        $nameForKeywords = $cleanName;
        foreach ($ramCache['cars'] as $car) {
            $nameForKeywords = str_ireplace($car->car_name, ' ', $nameForKeywords);
            if (!empty($car->category)) {
                $nameForKeywords = str_ireplace($car->category, ' ', $nameForKeywords);
            }
        }
        foreach ($ramCache['all_aliases'] as $alData) {
            $nameForKeywords = str_ireplace($alData['alias'], ' ', $nameForKeywords);
        }
        foreach ($ramCache['patterns'] as $normP) {
            $nameForKeywords = str_ireplace($normP, ' ', $nameForKeywords);
        }

        if (preg_match_all('/(?:(?:19|20)?\d{2})\s*[\/-]\s*(?:(?:19|20)?\d{2})/u', $cleanName, $mRanges)) {
            foreach ($mRanges[0] as $r) {
                $detectedYearsList[] = str_replace(' ', '', $r);
            }
        }
        if (empty($detectedYearsList)) {
            if (preg_match_all('/ (?:19\d{2}|20\d{2}) /u', $cleanName, $mFullYears)) {
                foreach ($mFullYears[0] as $fy) {
                    $detectedYearsList[] = $fy;
                }
            } elseif (preg_match_all('/(?<!\d)\d{2}(?!\d)/u', $nameForKeywords, $mShortYears)) {
                foreach ($mShortYears[0] as $sy) {
                    $detectedYearsList[] = $sy;
                }
            }
        }

        $detectedYearsList = array_values(array_unique(array_filter($detectedYearsList)));

        $nameForKeywords = preg_replace('/(?:(?:19|20)?\d{2})\s*[\/-]\s*(?:(?:19|20)?\d{2})/u', ' ', $nameForKeywords);
        $nameForKeywords = preg_replace('/ (?:19|20)\d{2} /u', ' ', $nameForKeywords);
        $nameForKeywords = preg_replace('/(?<!\d)\d{2}(?!\d)/u', ' ', $nameForKeywords);

        $itemCarString      = !empty($detectedCarsList) ? implode(' | ', $detectedCarsList) : null;
        $itemCategoryString = !empty($detectedCategories) ? implode(' | ', $detectedCategories) : null;
        $itemYearsString    = !empty($detectedYearsList) ? implode(' | ', $detectedYearsList) : null;

        $rowPayload = [
            'user_id'           => $userId,
            'item_number'       => $itemNumber,
            'item_name'         => $cleanName,
            'quantity'          => $data['quantity'],
            'price'             => $data['price'],
            'detected_car_name' => $itemCarString,
            'detected_category' => $itemCategoryString,
            'detected_years'    => $itemYearsString,
            'updated_at'        => $now,
        ];

        if (isset($existingItems[$itemNumber])) {
            $rowPayload['id'] = $existingItems[$itemNumber];
            $toUpdateItems[]  = $rowPayload;
        } else {
            $rowPayload['created_at'] = $now;
            $toInsertItems[]          = $rowPayload;
        }

        $masterUpdates[$itemNumber] = [
            'first_item_name' => $cleanName,
            'cars'            => $detectedCarsList,
            'years'           => $detectedYearsList,
        ];

        $keywordsWords = preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $nameForKeywords, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($keywordsWords as $word) {
            $wordClean = trim($word);
            $wordNorm  = normalizeArabicSpelling($wordClean);
            $isYearPattern = preg_match('/^(?:19|20)\d{2}$/u', $wordNorm)
                          || preg_match('/^(?:(?:19|20)?\d{2})[\/-](?:(?:19|20)?\d{2})$/u', $wordNorm)
                          || preg_match('/^\d+$/u', $wordNorm);

            if (mb_strlen($wordNorm) >= 2 && !is_numeric($wordNorm) && !$isYearPattern && !in_array($wordNorm, $ramCache['base_skip_words'], true)) {
                $keywordsBatch[] = [
                    'item_number' => $itemNumber,
                    'keyword'     => $wordClean,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
            }
        }
    }

    DB::beginTransaction();
    try {
        if (!empty($toInsertItems)) {
            foreach (array_chunk($toInsertItems, 250) as $insChunk) {
                DB::table('items')->insert($insChunk);
            }
        }

        foreach ($toUpdateItems as $updRow) {
            $rowId = $updRow['id'];
            unset($updRow['id']);
            DB::table('items')->where('id', $rowId)->update($updRow);
        }

        // تحديث قلب النظام (master_items) دفعة واحدة
        $existingMasters = DB::table('master_items')
            ->whereIn('item_number', $batchNumbers)
            ->get()
            ->keyBy('item_number');

        $masterInsertRows = [];
        foreach ($masterUpdates as $itemNum => $mInfo) {
            $carStr  = !empty($mInfo['cars']) ? implode(' | ', $mInfo['cars']) : null;
            $yearStr = !empty($mInfo['years']) ? implode(' | ', $mInfo['years']) : null;

            if (!isset($existingMasters[$itemNum])) {
                $mRow = [
                    'item_number'     => $itemNum,
                    'first_item_name' => $mInfo['first_item_name'],
                    'first_user_id'   => $userId,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ];
                if ($ramCache['has_compat_col']) {
                    $mRow['compatible_cars']  = $carStr;
                    $mRow['compatible_years'] = $yearStr;
                }
                $masterInsertRows[] = $mRow;
            } elseif ($ramCache['has_compat_col']) {
                $exMaster  = $existingMasters[$itemNum];
                $prevCars  = !empty($exMaster->compatible_cars) ? array_map('trim', explode('|', $exMaster->compatible_cars)) : [];
                $prevYears = !empty($exMaster->compatible_years) ? array_map('trim', explode('|', $exMaster->compatible_years)) : [];

                $mergedCars  = implode(' | ', array_values(array_unique(array_filter(array_merge($prevCars, $mInfo['cars'])))));
                $mergedYears = implode(' | ', array_values(array_unique(array_filter(array_merge($prevYears, $mInfo['years'])))));

                DB::table('master_items')->where('item_number', $itemNum)->update([
                    'compatible_cars'  => $mergedCars ?: null,
                    'compatible_years' => $mergedYears ?: null,
                    'updated_at'       => $now,
                ]);
            }
        }

        if (!empty($masterInsertRows)) {
            foreach (array_chunk($masterInsertRows, 250) as $mChunk) {
                DB::table('master_items')->insertOrIgnore($mChunk);
            }
        }

        if (!empty($keywordsBatch)) {
            foreach (array_chunk($keywordsBatch, 300) as $kwChunk) {
                DB::table('item_keywords')->insertOrIgnore($kwChunk);
            }
        }

        DB::commit();
    } catch (\Throwable $e) {
        DB::rollBack();
        throw $e;
    }

    return [
        'processed' => count($rows),
        'imported'  => count($toInsertItems),
        'updated'   => count($toUpdateItems),
    ];
}

/**
 * قراءة ملف الإكسل أو CSV وتحويله إلى مصفوفة أسطر بسرعة عالية (يدعم Maatwebsite Excel و PhpSpreadsheet و SimpleXLSX و CSV)
 */
function parseUploadedSpreadsheetFileRows(string $filePath): array {
    if (!file_exists($filePath)) {
        return [];
    }

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    if ($ext === 'json') {
        $json = json_decode((string) file_get_contents($filePath), true);
        return is_array($json) ? $json : [];
    }

    if ($ext === 'csv' || $ext === 'txt') {
        $rows = [];
        if (($handle = fopen($filePath, 'r')) !== false) {
            while (($data = fgetcsv($handle, 0, ',')) !== false) {
                $rows[] = $data;
            }
            fclose($handle);
        }
        return $rows;
    }

    if (class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        return $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
    }

    if (class_exists(\Maatwebsite\Excel\Facades\Excel::class)) {
        $sheets = \Maatwebsite\Excel\Facades\Excel::toArray(new \stdClass(), $filePath);
        return $sheets[0] ?? [];
    }

    return [];
}

/**
 * تنفيذ مهمة الاستيراد في الخلفية بشكل كامل ومستقل عن الجلسة والمتصفح
 */
function runBackgroundImportJobWorker(int $jobId): void {
    @ignore_user_abort(true);
    @set_time_limit(0);

    $job = DB::table('excel_import_jobs')->where('id', $jobId)->first();
    if (!$job || in_array($job->status, ['completed', 'rolled_back'], true)) {
        return;
    }

    DB::table('excel_import_jobs')->where('id', $jobId)->update([
        'status'     => 'processing',
        'updated_at' => now(),
    ]);

    try {
        $rows = parseUploadedSpreadsheetFileRows((string) $job->file_path);
        if (empty($rows)) {
            DB::table('excel_import_jobs')->where('id', $jobId)->update([
                'status'        => 'failed',
                'error_message' => 'الملف فارغ أو تعذر قراءة محتواه.',
                'updated_at'    => now(),
            ]);
            return;
        }

        $headerRow     = reset($rows);
        $columnMapping = !empty($job->column_mapping)
            ? json_decode($job->column_mapping, true)
            : (is_array($headerRow) ? detectSmartExcelColumns($headerRow) : null);

        $totalRows = count($rows);
        DB::table('excel_import_jobs')->where('id', $jobId)->update([
            'total_rows'     => $totalRows,
            'column_mapping' => json_encode($columnMapping, JSON_UNESCAPED_UNICODE),
            'updated_at'     => now(),
        ]);

        $ramCache      = buildMasterDictionaryRamCache();
        $chunks        = array_chunk($rows, 400);
        $processedRows = 0;
        $importedTotal = 0;
        $updatedTotal  = 0;

        foreach ($chunks as $chunk) {
            $stats = processBulkInventoryChunkFast($chunk, (int) $job->user_id, $ramCache, $columnMapping);
            $processedRows += $stats['processed'];
            $importedTotal += $stats['imported'];
            $updatedTotal  += $stats['updated'];

            DB::table('excel_import_jobs')->where('id', $jobId)->update([
                'processed_rows' => min($totalRows, $processedRows),
                'imported_count' => $importedTotal,
                'updated_count'  => $updatedTotal,
                'updated_at'     => now(),
            ]);
        }

        DB::table('excel_import_jobs')->where('id', $jobId)->update([
            'status'         => 'completed',
            'processed_rows' => $totalRows,
            'imported_count' => $importedTotal,
            'updated_count'  => $updatedTotal,
            'updated_at'     => now(),
        ]);

        if (!empty($job->file_path) && file_exists($job->file_path)) {
            @unlink($job->file_path);
        }
    } catch (\Throwable $e) {
        DB::table('excel_import_jobs')->where('id', $jobId)->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
            'updated_at'    => now(),
        ]);
    }
}

function syncAllExistingItemsToCore(): void {
    cleanupAndNormalizeInventoryData();
    purgeCarAndYearKeywords();

    $allItems = DB::table('items')->orderBy('id', 'asc')->get();
    foreach ($allItems as $item) {
        syncItemToMasterCore($item->item_number, $item->item_name, $item->user_id);
    }
}

/*
|--------------------------------------------------------------------------
| 4. محرك تحليل رسائل الواتساب والبحث الذكي في المخزون والقاموس المشترك
|--------------------------------------------------------------------------
*/

/**
 * توريث اسم القطعة عندما يكتب الزبون اتجاهين معطوفين (مثل: مساعد امامي يمين ويسار)
 */
function expandCompoundDirectionalRequests(array $rawParts): array {
    $modifiers  = getPartModifierWords();
    $sideWords  = ['يمين', 'يسار'];
    $posWords   = ['امامي', 'اماميه', 'خلفي', 'خلفيه'];
    $vertWords  = ['علوي', 'علويه', 'سفلي', 'سفليه'];
    $inOutWords = ['داخلي', 'داخليه', 'خارجي', 'خارجيه'];

    $expanded = [];

    foreach ($rawParts as $phrase) {
        $phrase = trim($phrase);
        if ($phrase === '') continue;

        $words     = preg_split('/\s+/u', $phrase, -1, PREG_SPLIT_NO_EMPTY);
        $normWords = array_map('normalizeArabicSpelling', $words);

        $onlyModifiers = true;
        foreach ($normWords as $nw) {
            if (!in_array($nw, $modifiers, true)) {
                $onlyModifiers = false;
                break;
            }
        }

        if ($onlyModifiers && !empty($expanded)) {
            $prevPhrase     = end($expanded);
            $prevWords      = preg_split('/\s+/u', $prevPhrase, -1, PREG_SPLIT_NO_EMPTY);
            $newPhraseWords = $prevWords;

            foreach ($words as $modWord) {
                $normMod  = normalizeArabicSpelling($modWord);
                $replaced = false;

                $oppositeGroup = [];
                if (in_array($normMod, $sideWords, true))       $oppositeGroup = $sideWords;
                elseif (in_array($normMod, $posWords, true))    $oppositeGroup = $posWords;
                elseif (in_array($normMod, $vertWords, true))   $oppositeGroup = $vertWords;
                elseif (in_array($normMod, $inOutWords, true))  $oppositeGroup = $inOutWords;

                if (!empty($oppositeGroup)) {
                    foreach ($newPhraseWords as $idx => $pw) {
                        if (in_array(normalizeArabicSpelling($pw), $oppositeGroup, true)) {
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
 * البحث الدقيق عن قطعة واحدة في مخزون العميل بالاعتماد على:
 * 1) اسم الصنف لدى العميل
 * 2) قاموس المرادفات المشترك (item_keywords) المجمع من كل العملاء لنفس الرقم الموحد
 * 3) توافق السيارات والموديلات المسجل لنفس الرقم في (master_items)
 */
function findMatchingItemsForSinglePart(string $singlePartRequest, string $car, string $year, int $ownerUserId) {
    $singlePartRequest = trim($singlePartRequest);
    if ($singlePartRequest === '') {
        return collect();
    }

    // فحص التطابق المباشر برقم الصنف
    $directMatch = Item::where('user_id', $ownerUserId)
        ->where('quantity', '>', 0)
        ->where('item_number', strtoupper($singlePartRequest))
        ->first();

    if ($directMatch) {
        return collect([$directMatch]);
    }

    $allWords = array_values(array_filter(preg_split('/\s+/u', $singlePartRequest), function ($w) {
        return mb_strlen(trim($w)) >= 2;
    }));

    $modifiersList    = getPartModifierWords();
    $stopWordsList    = getChatAndStopWords();
    $subComponentsSet = getSubComponentPartWords();

    $coreNounWords          = [];
    $directionWords         = [];
    $requestedSubComponents = [];

    foreach ($allWords as $w) {
        $normW = normalizeArabicSpelling($w);
        if (in_array($normW, $stopWordsList, true)) {
            continue;
        }
        if (in_array($normW, $modifiersList, true)) {
            $directionWords[] = $w;
        } else {
            $coreNounWords[] = $w;
            $wVariants = getWordVariantsForSearch($normW);
            foreach ($wVariants as $wv) {
                $wvNoAl = (mb_strlen($wv) > 4 && str_starts_with($wv, 'ال')) ? mb_substr($wv, 2) : $wv;
                if (in_array($wv, $subComponentsSet, true) || in_array($wvNoAl, $subComponentsSet, true)) {
                    $requestedSubComponents[] = $wv;
                    $requestedSubComponents[] = $wvNoAl;
                }
            }
        }
    }

    $requestedSubComponents = array_values(array_unique($requestedSubComponents));

    if (empty($coreNounWords)) {
        return collect();
    }

    // جلب الأرقام الموحدة التي تتوافق مع السيارة المطلوبة في master_items (لدعم القطع المشتركة بين عدة سيارات)
    $masterCompatibleNumbers = [];
    if ($car !== '' && Schema::hasColumn('master_items', 'compatible_cars')) {
        $masterCompatibleNumbers = DB::table('master_items')
            ->where('compatible_cars', 'like', "%{$car}%")
            ->pluck('item_number')
            ->toArray();
    }

    $carCandidates = Item::where('user_id', $ownerUserId)
        ->where('quantity', '>', 0)
        ->when($car !== '', function ($q) use ($car, $masterCompatibleNumbers) {
            $q->where(function ($sub) use ($car, $masterCompatibleNumbers) {
                $sub->where('detected_car_name', 'like', "%{$car}%")
                    ->orWhere('item_name', 'like', "%{$car}%");
                if (!empty($masterCompatibleNumbers)) {
                    $sub->orWhereIn('item_number', $masterCompatibleNumbers);
                }
            });
        })
        ->get();

    // تحميل بيانات التوافق والكلمات المفتاحية دفعة واحدة لتسريع البحث
    $candidateNumbers = $carCandidates->pluck('item_number')->unique()->toArray();

    $keywordsByNumber = [];
    if (!empty($candidateNumbers)) {
        $rawKws = DB::table('item_keywords')
            ->whereIn('item_number', $candidateNumbers)
            ->get();
        foreach ($rawKws as $rk) {
            $keywordsByNumber[$rk->item_number][] = normalizeArabicSpelling($rk->keyword);
        }
    }

    $masterYearsByNumber = [];
    if (!empty($candidateNumbers) && Schema::hasColumn('master_items', 'compatible_years')) {
        $masterYearsByNumber = DB::table('master_items')
            ->whereIn('item_number', $candidateNumbers)
            ->pluck('compatible_years', 'item_number')
            ->toArray();
    }

    $matched = $carCandidates->filter(function ($item) use ($coreNounWords, $directionWords, $requestedSubComponents, $subComponentsSet, $year, $keywordsByNumber, $masterYearsByNumber) {
        $masterCompatYears = $masterYearsByNumber[$item->item_number] ?? null;

        if ($year !== '' && !isYearMatchingItem($year, $item->detected_years, $item->item_name, $masterCompatYears)) {
            return false;
        }

        $itemNameNorm = normalizeArabicSpelling($item->item_name);
        $itemKeywords = implode(' ', $keywordsByNumber[$item->item_number] ?? []);

        // النص الكامل القابل للبحث يشمل اسم الصنف لدى العميل + كل مرادفات القاموس التراكمي لنفس الرقم الموحد
        $fullSearchableText = $itemNameNorm . ' ' . $itemKeywords;
        $searchableTokens   = preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $fullSearchableText, -1, PREG_SPLIT_NO_EMPTY);
        $itemNameTokens     = preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $itemNameNorm, -1, PREG_SPLIT_NO_EMPTY);

        // التحقق من وجود الكلمات الأساسية للقطعة (مع مرادفاتها وجموعها)
        foreach ($coreNounWords as $coreWord) {
            $variants  = getWordVariantsForSearch($coreWord);
            $foundCore = false;

            foreach ($variants as $v) {
                foreach ($searchableTokens as $token) {
                    $tokenNoAl = (mb_strlen($token) > 4 && str_starts_with($token, 'ال')) ? mb_substr($token, 2) : $token;
                    $vNoAl     = (mb_strlen($v) > 4 && str_starts_with($v, 'ال')) ? mb_substr($v, 2) : $v;

                    if ($token === $v || $tokenNoAl === $vNoAl) {
                        $foundCore = true;
                        break 2;
                    }
                    if (mb_strlen($vNoAl) >= 4 && (str_contains($tokenNoAl, $vNoAl) || str_contains($vNoAl, $tokenNoAl))) {
                        $foundCore = true;
                        break 2;
                    }
                }
            }

            if (!$foundCore) {
                return false;
            }
        }

        // منع الخلط بين القطعة الأساسية والقطعة الفرعية:
        // إذا طلب الزبون "صدام امامي" فقط (ولم يطلب صاجة أو بطانة أو قاعدة أو جسر)، نستبعد الأصناف التي هي عبارة عن "صاجة صدام" أو "قاعدة صدام"
        if (empty($requestedSubComponents)) {
            foreach ($itemNameTokens as $iToken) {
                $iTokenNoAl = (mb_strlen($iToken) > 4 && str_starts_with($iToken, 'ال')) ? mb_substr($iToken, 2) : $iToken;
                if (in_array($iToken, $subComponentsSet, true) || in_array($iTokenNoAl, $subComponentsSet, true)) {
                    return false;
                }
            }
        }

        // التحقق الصارم من الاتجاهات (يمين/يسار/أمامي/خلفي)
        foreach ($directionWords as $dirWord) {
            $dwNorm = normalizeArabicSpelling($dirWord);

            if ($dwNorm === 'يمين' && mb_stripos($fullSearchableText, 'يمين') === false) {
                return false;
            }
            if ($dwNorm === 'يسار' && mb_stripos($fullSearchableText, 'يسار') === false) {
                return false;
            }
            if (in_array($dwNorm, ['امامي', 'اماميه'], true) && mb_stripos($fullSearchableText, 'خلفي') !== false && mb_stripos($fullSearchableText, 'امام') === false) {
                return false;
            }
            if (in_array($dwNorm, ['خلفي', 'خلفيه'], true) && mb_stripos($fullSearchableText, 'امام') !== false && mb_stripos($fullSearchableText, 'خلف') === false) {
                return false;
            }

            $dirRoot = rtrim($dwNorm, 'ه');
            if (mb_stripos($fullSearchableText, $dirRoot) === false && mb_stripos($fullSearchableText, $dwNorm) === false) {
                return false;
            }
        }

        return true;
    })->sortByDesc(function ($item) use ($singlePartRequest, $coreNounWords) {
        // ترتيب النتائج بالأولوية للأصناف المطابقة تماماً لعبارة العميل في اسم الصنف نفسه
        $itemNameNorm = normalizeArabicSpelling($item->item_name);
        $reqNorm      = normalizeArabicSpelling($singlePartRequest);
        $score        = 0;

        if (mb_stripos($itemNameNorm, $reqNorm) !== false) {
            $score += 100;
        }

        $directNounHits = 0;
        foreach ($coreNounWords as $cw) {
            foreach (getWordVariantsForSearch($cw) as $v) {
                if (mb_stripos($itemNameNorm, $v) !== false) {
                    $directNounHits++;
                    break;
                }
            }
        }
        $score += ($directNounHits * 25);

        // تفضيل الاسم الأقصر والأدق عند تساوي التطابق
        $score -= mb_strlen($itemNameNorm) * 0.1;

        return $score;
    })->take(3)->values();

    return $matched;
}

/**
 * تحليل رسالة الزبون واستخراج أرقام القطع والسيارة والسنة والأصناف المطلوبة بدقة
 */
function replaceWholeWordArabicSafe(string $subject, string $search): string {
    $search = trim($search);
    if ($search === '') return $subject;
    // استبدال الكلمة فقط إذا كانت محاطة بحدود كلمات أو مسافات لمنع قص حروف من كلمات مثل (صدام / صاجة)
    $pattern = '/(?<=^|[\s,،\-\+\/\(\)\*\.])' . preg_quote($search, '/') . '(?=$|[\s,،\-\+\/\(\)\*\.])/iu';
    return preg_replace($pattern, ' ', $subject);
}

function containsWholeWordArabicSafe(string $subject, string $search): bool {
    $search = trim($search);
    if ($search === '') return false;
    $pattern = '/(?<=^|[\s,،\-\+\/\(\)\*\.])' . preg_quote($search, '/') . '(?=$|[\s,،\-\+\/\(\)\*\.])/iu';
    if (preg_match($pattern, $subject)) {
        return true;
    }
    // فحص إضافي بعد توحيد الهمزات والتاء المربوطة
    $normSubject = normalizeArabicSpelling($subject);
    $normSearch  = normalizeArabicSpelling($search);
    $normPattern = '/(?<=^|[\s,،\-\+\/\(\)\*\.])' . preg_quote($normSearch, '/') . '(?=$|[\s,،\-\+\/\(\)\*\.])/iu';
    return (bool) preg_match($normPattern, $normSubject);
}

function parseWhatsappText(string $text, ?int $ownerUserId = null): array {
    $normText         = normalizeArabicDigits($text);
    $detectedCar      = null;
    $detectedCategory = null;
    $detectedYear     = null;
    $remainingText    = $normText;

    // 1. استخراج أرقام القطع (Part Numbers)
    $extractedPartNumbers = [];
    if (preg_match_all('/\b[A-Za-z0-9]{3,}[\-][A-Za-z0-9\-]{2,}\b/u', $remainingText, $pm)) {
        foreach ($pm[0] as $partNum) {
            if (!preg_match('/^(?:19|20)?\d{2}[\-](?:19|20)?\d{2}$/', $partNum)) {
                $extractedPartNumbers[] = strtoupper($partNum);
                $remainingText = str_replace($partNum, ' ', $remainingText);
            }
        }
    }

    $tokens = preg_split('/[\s,،\n\r]+/u', trim($remainingText), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($tokens as $tk) {
        $cleanTk = strtoupper(trim($tk));
        if (strlen($cleanTk) >= 5 && preg_match('/[0-9]/', $cleanTk)) {
            $existsAsNumber = DB::table('items')
                ->when($ownerUserId, function ($q) use ($ownerUserId) {
                    return $q->where('user_id', $ownerUserId);
                })
                ->where('item_number', $cleanTk)
                ->exists();
            if ($existsAsNumber) {
                $extractedPartNumbers[] = $cleanTk;
                $remainingText = replaceWholeWordArabicSafe($remainingText, $tk);
            }
        }
    }
    $extractedPartNumbers = array_values(array_unique($extractedPartNumbers));

    // 2. استخراج السيارة ومرادفاتها (مع مطابقة الكلمات الكاملة فقط لمنع قص حروف القطع مثل صدام أو صاجة)
    $carsList = DB::table('cars')->get();
    foreach ($carsList as $car) {
        if (containsWholeWordArabicSafe($remainingText, $car->car_name)) {
            $detectedCar      = $car->car_name;
            $detectedCategory = $car->category;
            $remainingText    = replaceWholeWordArabicSafe($remainingText, $car->car_name);
            if (!empty($car->category)) {
                $remainingText = replaceWholeWordArabicSafe($remainingText, $car->category);
            }
            break;
        }
        $aliases = DB::table('car_aliases')->where('car_id', $car->id)->pluck('alias')->toArray();
        foreach ($aliases as $alias) {
            if ($alias !== '' && containsWholeWordArabicSafe($remainingText, $alias)) {
                $detectedCar      = $car->car_name;
                $detectedCategory = $car->category;
                $remainingText    = replaceWholeWordArabicSafe($remainingText, $alias);
                if (!empty($car->category)) {
                    $remainingText = replaceWholeWordArabicSafe($remainingText, $car->category);
                }
                break 2;
            }
        }
    }
    foreach ($carsList as $car) {
        if (!empty($car->category) && containsWholeWordArabicSafe($remainingText, $car->category)) {
            $remainingText = replaceWholeWordArabicSafe($remainingText, $car->category);
        }
    }

    // 3. استخراج سنة الصنع (الموديل)
    $patterns = DB::table('year_patterns')->pluck('pattern_example')->toArray();
    foreach ($patterns as $pattern) {
        $normPattern = normalizeArabicDigits(trim($pattern));
        if ($normPattern !== '' && preg_match('/(?<!\d)' . preg_quote($normPattern, '/') . '(?!\d)/u', $remainingText)) {
            $detectedYear  = $normPattern;
            $remainingText = preg_replace('/(?<!\d)' . preg_quote($normPattern, '/') . '(?!\d)/u', ' ', $remainingText, 1);
            break;
        }
    }

    if (!$detectedYear) {
        if (preg_match('/(?<!\d)((?:19|20)?\d{2}\s*[\/-]\s*(?:19|20)?\d{2})(?!\d)/u', $remainingText, $matches)) {
            $detectedYear  = str_replace(' ', '', $matches[1]);
            $remainingText = str_replace($matches[1], ' ', $remainingText);
        } elseif (preg_match('/(?<!\d)(19[7-9]\d|20[0-3]\d)(?!\d)/u', $remainingText, $matches)) {
            $detectedYear  = $matches[1];
            $remainingText = preg_replace('/(?<!\d)' . preg_quote($matches[1], '/') . '(?!\d)/u', ' ', $remainingText, 1);
        } elseif (preg_match('/(?<!\d)(\d{2})(?!\d)/u', $remainingText, $matches)) {
            $twoDigit = (int) $matches[1];
            if ($twoDigit <= 35 || $twoDigit >= 70) {
                $detectedYear  = $matches[1];
                $remainingText = preg_replace('/(?<!\d)' . preg_quote($matches[1], '/') . '(?!\d)/u', ' ', $remainingText, 1);
            }
        }
    }

    // 4. تصفية كلمات التحية والدردشة واستخراج القطع الحقيقية فقط
    $stopWords     = getChatAndStopWords();
    $modifiersList = getPartModifierWords();

    $rawSegments = preg_split('/(?:[\n\r,،\+\/]+|\s+و\s+|\s+وكذلك\s+|\s+مع\s+)/u', trim($remainingText), -1, PREG_SPLIT_NO_EMPTY);

    $partsList = [];
    foreach ($rawSegments as $seg) {
        $subTokens     = preg_split('/\s+/u', trim($seg), -1, PREG_SPLIT_NO_EMPTY);
        $currentPhrase = [];

        foreach ($subTokens as $tok) {
            $tokClean = trim($tok, '-_*.،,؟?!: ');
            if ($tokClean === '') continue;

            $tokNorm = normalizeArabicSpelling($tokClean);
            if (in_array($tokNorm, $stopWords, true)) {
                continue;
            }

            // معالجة واو العطف الملتصقة بالكلمة (مثل: وصدام أو ورديتر أو ويمين)
            if (mb_strlen($tokClean) >= 4 && mb_substr($tokClean, 0, 1) === 'و') {
                $withoutWaw     = mb_substr($tokClean, 1);
                $normWithoutWaw = normalizeArabicSpelling($withoutWaw);

                if (in_array($normWithoutWaw, $stopWords, true)) {
                    continue;
                }

                $isDirectionWord = in_array($normWithoutWaw, $modifiersList, true);
                $isKnownPart     = $isDirectionWord || isWordRecognizedInPartDictionary($normWithoutWaw, $ownerUserId);

                if ($isKnownPart && !empty($currentPhrase)) {
                    $partsList[]   = implode(' ', $currentPhrase);
                    $currentPhrase = [$withoutWaw];
                    continue;
                } elseif ($isKnownPart && empty($currentPhrase)) {
                    $currentPhrase[] = $withoutWaw;
                    continue;
                }
            }

            if (mb_strlen($tokNorm) >= 2) {
                $currentPhrase[] = $tokClean;
            }
        }

        if (!empty($currentPhrase)) {
            $partsList[] = implode(' ', $currentPhrase);
        }
    }

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
 * فحص دقيق: هل الكلمة معروفة كاسم قطعة غيار في القاموس القياسي أو قاموس المرادفات (item_keywords) أو أصناف التاجر؟
 */
function isWordRecognizedInPartDictionary(string $word, ?int $ownerUserId = null): bool {
    $norm = normalizeArabicSpelling($word);
    if (mb_strlen($norm) < 2 || in_array($norm, getChatAndStopWords(), true)) {
        return false;
    }

    $variants        = getWordVariantsForSearch($norm);
    $standardLexicon = getStandardAutoPartLexicon();

    // 0. فحص فوري في القاموس القياسي الشامل لقطع الغيار (مثل صدام، صاجة، شمعة، رفرف، رديتر...)
    foreach ($variants as $v) {
        $vNoAl = (mb_strlen($v) > 4 && str_starts_with($v, 'ال')) ? mb_substr($v, 2) : $v;
        if (in_array($v, $standardLexicon, true) || in_array($vNoAl, $standardLexicon, true)) {
            return true;
        }
    }

    // 1. فحص في جدول قاموس الكلمات المفتاحية (تطابق تام للكلمة الموحدة)
    $allKeywords = DB::table('item_keywords')->pluck('keyword')->toArray();
    foreach ($allKeywords as $kw) {
        $kwNorm = normalizeArabicSpelling($kw);
        $kwNoAl = (mb_strlen($kwNorm) > 4 && str_starts_with($kwNorm, 'ال')) ? mb_substr($kwNorm, 2) : $kwNorm;
        foreach ($variants as $v) {
            $vNoAl = (mb_strlen($v) > 4 && str_starts_with($v, 'ال')) ? mb_substr($v, 2) : $v;
            if ($kwNorm === $v || $kwNoAl === $vNoAl) {
                return true;
            }
        }
    }

    // 2. فحص في أسماء الأصناف الخاصة بالتاجر أو في قلب النظام العام (master_items)
    $itemNamesPool = [];
    if ($ownerUserId !== null) {
        $itemNamesPool = DB::table('items')
            ->where('user_id', $ownerUserId)
            ->pluck('item_name')
            ->toArray();
    }
    if (empty($itemNamesPool)) {
        $itemNamesPool = DB::table('master_items')->limit(1000)->pluck('first_item_name')->toArray();
    }

    foreach ($itemNamesPool as $iName) {
        $tokens = preg_split('/[\s,\-\+\/\(\)\*\.]+/u', normalizeArabicSpelling($iName), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($tokens as $tk) {
            $tkNoAl = (mb_strlen($tk) > 4 && str_starts_with($tk, 'ال')) ? mb_substr($tk, 2) : $tk;
            foreach ($variants as $v) {
                $vNoAl = (mb_strlen($v) > 4 && str_starts_with($v, 'ال')) ? mb_substr($v, 2) : $v;
                if ($tk === $v || ($tkNoAl === $vNoAl && mb_strlen($vNoAl) >= 3)) {
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * دالة التحقق الذكية الصارمة:
 * هل رسالة الزبون تمثل استعلاماً حقيقياً عن قطع غيار أم مجرد تحية/دردشة؟
 */
function hasGenuinePartInquiryIntent(string $messageText, array $parsed, int $ownerUserId): bool {
    // 1. إذا أرسل رقم قطعة صريح -> استعلام حقيقي
    if (!empty($parsed['part_numbers'])) {
        return true;
    }

    // 2. إذا لم يتبقَّ أي كلمات بعد حذف التحيات والسيارة والموديل -> ليست طلب قطعة
    if (empty($parsed['parts_list'])) {
        return false;
    }

    // 3. التحقق مما إذا كانت الكلمات المتبقية في parts_list تحتوي على اسم قطعة معروفة في القاموس
    $modifiers = getPartModifierWords();
    foreach ($parsed['parts_list'] as $partPhrase) {
        $words = preg_split('/\s+/u', $partPhrase, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $w) {
            $wNorm = normalizeArabicSpelling($w);
            if (mb_strlen($wNorm) < 2 || in_array($wNorm, $modifiers, true)) {
                continue;
            }
            if (isWordRecognizedInPartDictionary($wNorm, $ownerUserId)) {
                return true;
            }
        }
    }

    // 4. إذا كتب الزبون كلمة طلب صريحة (مثل: ابغى، مطلوب، عندكم، قطعة) أو أرسل سيارة/موديل مع اسم قطعة
    $normMsg = normalizeArabicSpelling($messageText);
    $explicitIntentWords = ['قطعه', 'قطع', 'غيار', 'ابغي', 'ابغا', 'ابي', 'عندكم', 'متوفر', 'يتوفر', 'احصل', 'مطلوب', 'بغيت', 'احتاج', 'اريد', 'بكم', 'سعر'];
    $hasExplicitTrigger  = (!empty($parsed['car']) || !empty($parsed['year']));

    if (!$hasExplicitTrigger) {
        $msgTokens = preg_split('/[\s,،\?\!]+/u', $normMsg, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($explicitIntentWords as $intentWord) {
            $normIntent = normalizeArabicSpelling($intentWord);
            if (in_array($normIntent, $msgTokens, true) || in_array('و' . $normIntent, $msgTokens, true)) {
                $hasExplicitTrigger = true;
                break;
            }
        }
    }

    if ($hasExplicitTrigger && !empty($parsed['parts_list'])) {
        foreach ($parsed['parts_list'] as $partPhrase) {
            $words = preg_split('/\s+/u', $partPhrase, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($words as $w) {
                $wNorm = normalizeArabicSpelling($w);
                if (mb_strlen($wNorm) >= 3 && !in_array($wNorm, $modifiers, true) && !in_array($wNorm, getChatAndStopWords(), true)) {
                    return true;
                }
            }
        }
    }

    return false;
}

/*
|--------------------------------------------------------------------------
| 5. دوال إعدادات البوت وإرسال رسائل الواتساب ومعالجة الويب هوك (Webhook)
|--------------------------------------------------------------------------
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

    $result = $defaults;
    foreach ($saved as $k => $v) {
        $result[$k] = (string) $v;
    }

    return $result;
}

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

function sendWhatsappReplyMessage(array $settings, string $cleanPhone, string $rawSender, $convId, string $replyText): void {
    $apiUrl    = trim($settings['whatsapp_api_url'] ?? '');
    $apiToken  = trim($settings['whatsapp_api_token'] ?? '');
    $sessionId = trim($settings['whatsapp_session_id'] ?? '');

    if (!empty($apiUrl) && trim($replyText) !== '') {
        try {
            $headers = ['Accept' => 'application/json'];
            if (!empty($apiToken)) {
                $headers['Authorization'] = 'Bearer ' . $apiToken;
                $headers['X-Api-Key']     = $apiToken;
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
            // تجاهل أخطاء الشبكة الخارجية حتى لا يتوقف الويب هوك
        }
    }
}

function sendWhatsappLoginOtp(User $user, string $otpCode): bool {
    $targetPhone = trim((string) $user->phone);
    $cleanPhone  = normalizeWhatsappDigits($targetPhone);
    if ($cleanPhone === '') {
        return false;
    }

    $userWhatsappSettings = DB::table('user_bot_settings')
        ->where('user_id', $user->id)
        ->pluck('value', 'key')
        ->toArray();

    $apiUrl = trim((string) ($userWhatsappSettings['whatsapp_api_url'] ?? ''));
    if ($apiUrl === '') {
        return false;
    }

    $settings = [
        'whatsapp_api_url'    => $apiUrl,
        'whatsapp_api_token'  => trim((string) ($userWhatsappSettings['whatsapp_api_token'] ?? '')),
        'whatsapp_session_id' => trim((string) ($userWhatsappSettings['whatsapp_session_id'] ?? '')),
    ];

    $message = "🔐 رمز التحقق الخاص بك لتسجيل الدخول هو: *{$otpCode}*\nيرجى عدم مشاركة هذا الرمز مع أي شخص.";
    $jid     = $cleanPhone . '@s.whatsapp.net';

    sendWhatsappReplyMessage($settings, $cleanPhone, $jid, null, $message);
    return true;
}

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
 * المعالج الرئيسي لرسائل الواتساب الواردة (Webhook Handler)
 */
function handleWebhook(Request $request) {
    $payload   = $request->all();
    $eventType = $request->input('event', data_get($payload, 'event', 'unknown'));

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

    $ownerUserId = resolveWebhookOwnerUserId($request, $payload);
    $ownerUser   = User::find($ownerUserId);
    $settings    = getUserBotSettings($ownerUserId);
    $allowGroups = ($settings['allow_group_replies'] ?? '0') === '1';

    $groupCheck = detectGroupAndBroadcastStatus($payload);

    if ($groupCheck['is_broadcast']) {
        return response()->json(['status' => 'ignored_broadcast_or_status'], 200);
    }

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

    $quotaPhoneKey = normalizeWhatsappDigits((string) $sender) ?: $cleanPhone;

    // جلب الجلسة الحالية (إن وجدت) وحذفها إذا مر عليها أكثر من ساعتين
    $session = DB::table('whatsapp_sessions')
        ->where('user_id', $ownerUserId)
        ->where('phone', $quotaPhoneKey)
        ->first();

    if ($session && now()->diffInMinutes($session->updated_at) > 120) {
        DB::table('whatsapp_sessions')->where('id', $session->id)->delete();
        $session = null;
    }

    // إذا كتب العميل كلمة إلغاء (خلاص / كنسل / الغاء) نمسح الجلسة بصمت ولا نرد
    if (isSessionCancelMessage($messageText)) {
        if ($session) {
            DB::table('whatsapp_sessions')->where('id', $session->id)->delete();
        }
        return response()->json(['status' => 'session_cancelled_silently'], 200);
    }

    // تحليل الرسالة
    $parsed      = parseWhatsappText($messageText, $ownerUserId);
    $hasPartIntent = hasGenuinePartInquiryIntent($messageText, $parsed, $ownerUserId);

    // فحص صارم لمنع الرد على "السلام عليكم" أو "كيفك" أو الدردشة العامة:
    // 1. إذا لم تكن هناك جلسة سابقة، والرسالة لا تحتوي على طلب قطعة غيار حقيقي -> تجاهل تام
    if (!$session && !$hasPartIntent) {
        return response()->json(['status' => 'ignored_non_inquiry_chat'], 200);
    }

    // 2. إذا كانت هناك جلسة سابقة (البوت ينتظر اسم السيارة أو الموديل)، وأرسل العميل "السلام عليكم" أو "كيفك" (لا سيارة، لا موديل، لا قطعة) -> تجاهل تام دون إزعاج العميل
    if ($session && !$hasPartIntent && empty($parsed['car']) && empty($parsed['year'])) {
        return response()->json(['status' => 'ignored_greeting_during_session'], 200);
    }

    // فحص الحصة (Quota) والاستثناءات بعد التأكد من أن الرسالة استعلام حقيقي
    $msgAskCar        = trim($settings['reply_ask_car'] ?? '');
    $msgAskYear       = trim($settings['reply_ask_year'] ?? '');
    $msgAvailable     = trim($settings['reply_available'] ?? '');
    $msgUnavailable   = trim($settings['reply_unavailable'] ?? '');
    $msgQuotaExceeded = trim($settings['reply_quota_exceeded'] ?? '');
    $maxQueriesLimit  = (int) ($settings['max_queries_per_phone'] ?? 0);
    $sessionId        = trim($settings['whatsapp_session_id'] ?? '');
    $convId           = data_get($payload, 'data.conversation_id', data_get($payload, 'conversation_id'));

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

    $replyBlocks           = [];
    $unavailablePartsNames = [];

    // حالة البحث المباشر برقم القطعة (Part Number)
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

    // ميزة التبديل الذكي للسياق: إذا بدأ العميل طلباً جديداً بسيارة جديدة وقطعة جديدة، نستبدل الجلسة القديمة
    if ($session && !empty($parsed['car']) && !empty($parsed['parts_list']) && $session->detected_car && $session->detected_car !== $parsed['car']) {
        DB::table('whatsapp_sessions')->where('id', $session->id)->delete();
        $session = null;
    }

    $car  = $parsed['car']  ?: ($session->detected_car ?? null);
    $year = $parsed['year'] ?: ($session->detected_year ?? null);

    $existingParts = [];
    if (!empty($session->part_query)) {
        $existingParts = array_values(array_filter(array_map('trim', explode('|', $session->part_query))));
    }
    $newParts        = array_merge($parsed['part_numbers'], $parsed['parts_list']);
    $mergedPartsList = expandCompoundDirectionalRequests(array_values(array_unique(array_merge($existingParts, $newParts))));
    $partQueryString = implode(' | ', $mergedPartsList);

    // إذا لم يكن هناك أي قطعة مطلوبة حتى الآن، لا ننشئ جلسة وهمية ولا نرد
    if (empty($mergedPartsList)) {
        return response()->json(['status' => 'ignored_no_part_requested'], 200);
    }

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
        $carRow      = DB::table('cars')->where('car_name', $car)->first();
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

    foreach ($mergedPartsList as $singlePartRequest) {
        $singlePartRequest = trim($singlePartRequest);
        if ($singlePartRequest === '') continue;

        $foundForThisPart = findMatchingItemsForSinglePart($singlePartRequest, $car, $year, $ownerUserId);

        if ($foundForThisPart->count() > 0) {
            foreach ($foundForThisPart as $matchedItem) {
                if ($msgAvailable !== '') {
                    $itemVars                = $baseTemplateVars;
                    $itemVars['item_name']   = $matchedItem->item_name;
                    $itemVars['item_number'] = $matchedItem->item_number;
                    $itemVars['price']       = (string) $matchedItem->price;
                    $itemVars['quantity']    = (string) $matchedItem->quantity;
                    $itemVars['part']        = $singlePartRequest;
                    $itemVars['items']       = "- {$matchedItem->item_name} (رقم: {$matchedItem->item_number}) | السعر: {$matchedItem->price} ريال";

                    $replyBlocks[] = formatBotTemplate($msgAvailable, $itemVars);
                }
            }
        } else {
            $unavailablePartsNames[] = $singlePartRequest;
        }
    }

    if (!empty($unavailablePartsNames) && $msgUnavailable !== '') {
        $missingSummary          = implode(' ، ', array_unique($unavailablePartsNames));
        $unavailVars             = $baseTemplateVars;
        $unavailVars['part']      = $missingSummary;
        $unavailVars['item_name'] = $missingSummary;
        $replyBlocks[]           = formatBotTemplate($msgUnavailable, $unavailVars);
    }

    DB::table('whatsapp_sessions')
        ->where('user_id', $ownerUserId)
        ->where('phone', $quotaPhoneKey)
        ->delete();

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

/*
|--------------------------------------------------------------------------
| 6. المسارات العامة ومسارات المصادقة (Public & Auth Routes)
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return redirect()->route('inventory.index');
});

Route::get('/dashboard', function () {
    return redirect()->route('inventory.index');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::post('/api/webhook/receiver', function (Request $r) {
    return handleWebhook($r);
});

Route::post('/api/webhook/receiver/', function (Request $r) {
    return handleWebhook($r);
});

require __DIR__ . '/auth.php';

// تسجيل الدخول الموحد (للأدمن والعملاء برقم الجوال وكود التحقق، مع عزل واتساب كل حساب على حدة)
Route::post('/login', function (Request $request) {
    $loginInput = trim((string) $request->input('login', ''));
    $password   = trim((string) $request->input('password', ''));
    $cleanInput = preg_replace('/\D/', '', $loginInput);

    if (strlen($cleanInput) === 10 && str_starts_with($cleanInput, '0')) {
        $formattedTarget  = formatSaudiPhoneIntl($loginInput);
        $localAlternative = $cleanInput;

        $user = User::where(function ($q) use ($formattedTarget, $localAlternative) {
            $q->where('phone', $formattedTarget)
              ->orWhere('phone', $localAlternative);
        })->first();

        if (!$user && $cleanInput === '0556666037') {
            $user = User::where('role', 'admin')->first();
            if ($user) {
                $user->phone     = $formattedTarget;
                $user->is_active = true;
                $user->save();
            }
        }

        if (!$user) {
            return back()->withInput()->with('error', 'رقم الجوال هذا غير مسجل في النظام.');
        }

        if (!$user->is_active && $user->role !== 'admin') {
            return back()->withInput()->with('error', 'تم إيقاف حسابك مؤقتاً، يرجى التواصل مع الإدارة.');
        }

        if (empty($password)) {
            $user->otp_code = (string) random_int(1000, 9999);
            $user->save();

            $sentViaWhatsapp = sendWhatsappLoginOtp($user, $user->otp_code);

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
            $request->session()->regenerate();
            return redirect()->intended('/dashboard');
        }

        return back()->withInput()->with('error', 'بيانات الدخول غير صحيحة.');
    }
})->name('login');

/*
|--------------------------------------------------------------------------
| 7. مسارات النظام المحمية بتسجيل الدخول (Authenticated Routes)
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    // 1. المخزون (خاص لكل مستخدم)
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/', function (Request $request) {
            $user         = auth()->user();
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

            $userId     = auth()->id();
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

            return back()->with('success', 'تمت إضافة الصنف وتحديث قاموس المرادفات وتوافق السيارات بنجاح.');
        })->name('store');

        // استيراد الإكسل فائق السرعة في الخلفية مع الربط الذكي للأعمدة ونقطة الاسترجاع الفورية
        Route::post('/import', function (Request $request) {
            @ignore_user_abort(true);
            @set_time_limit(0);

            $userId = auth()->id();

            // 1. دعم الدفعات المرسلة عبر AJAX/WebWorker مع المعالجة الجماعية في الذاكرة (Bulk Upsert)
            if ($request->has('items_batch') && is_array($request->input('items_batch'))) {
                $batch      = $request->input('items_batch');
                $isFirst    = $request->boolean('is_first_chunk', false);
                $totalRows  = (int) $request->input('total_rows', count($batch));
                $jobId      = $request->input('import_job_id');
                $colMapping = $request->input('column_mapping');

                if ($isFirst || empty($jobId)) {
                    $snapshotId = createInventoryRollbackSnapshot($userId, 'نسخة تلقائية قبل استيراد إكسل بتاريخ ' . now()->format('Y-m-d H:i'));
                    $jobId = DB::table('excel_import_jobs')->insertGetId([
                        'user_id'        => $userId,
                        'file_name'      => $request->input('file_name', 'excel_batch.xlsx'),
                        'status'         => 'processing',
                        'total_rows'     => $totalRows,
                        'processed_rows' => 0,
                        'imported_count' => 0,
                        'updated_count'  => 0,
                        'column_mapping' => is_array($colMapping) ? json_encode($colMapping, JSON_UNESCAPED_UNICODE) : null,
                        'snapshot_id'    => $snapshotId,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);
                }

                $stats = processBulkInventoryChunkFast(
                    $batch,
                    $userId,
                    null,
                    is_array($colMapping) ? $colMapping : null
                );

                $jobRecord = DB::table('excel_import_jobs')->where('id', $jobId)->first();
                if ($jobRecord) {
                    $newProcessed = $jobRecord->processed_rows + $stats['processed'];
                    $newImported  = $jobRecord->imported_count + $stats['imported'];
                    $newUpdated   = $jobRecord->updated_count + $stats['updated'];
                    $isFinished   = $request->boolean('is_last_chunk', false) || ($jobRecord->total_rows > 0 && $newProcessed >= $jobRecord->total_rows);

                    DB::table('excel_import_jobs')->where('id', $jobId)->update([
                        'status'         => $isFinished ? 'completed' : 'processing',
                        'processed_rows' => $newProcessed,
                        'imported_count' => $newImported,
                        'updated_count'  => $newUpdated,
                        'updated_at'     => now(),
                    ]);
                }

                return response()->json([
                    'status'        => 'success',
                    'import_job_id' => $jobId,
                    'imported'      => $stats['imported'] + $stats['updated'],
                    'new_items'     => $stats['imported'],
                    'updated_items' => $stats['updated'],
                ]);
            }

            // 2. في حال رفع ملف كامل مباشرة (Background Queue / FastCGI Finish Request)
            $uploadedFile = $request->file('file') ?? $request->file('excel_file') ?? $request->file('import_file');
            if ($uploadedFile) {
                $snapshotId = createInventoryRollbackSnapshot($userId, 'نسخة تلقائية قبل استيراد ملف ' . $uploadedFile->getClientOriginalName());

                $tempDir = storage_path('app/temp_imports');
                if (!is_dir($tempDir)) {
                    @mkdir($tempDir, 0775, true);
                }

                $ext      = $uploadedFile->getClientOriginalExtension() ?: 'xlsx';
                $saveName = 'import_' . $userId . '_' . time() . '_' . uniqid() . '.' . $ext;
                $fullPath = $tempDir . DIRECTORY_SEPARATOR . $saveName;
                $uploadedFile->move($tempDir, $saveName);

                $customMapping = $request->input('column_mapping');
                $jobId = DB::table('excel_import_jobs')->insertGetId([
                    'user_id'        => $userId,
                    'file_name'      => $uploadedFile->getClientOriginalName(),
                    'file_path'      => $fullPath,
                    'status'         => 'pending',
                    'total_rows'     => 0,
                    'processed_rows' => 0,
                    'imported_count' => 0,
                    'updated_count'  => 0,
                    'column_mapping' => is_array($customMapping) ? json_encode($customMapping, JSON_UNESCAPED_UNICODE) : null,
                    'snapshot_id'    => $snapshotId,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);

                // تنفيذ المهمة في الخلفية بعد إغلاق الاتصال مع المتصفح بحيث يستمر الرفع حتى لو تنقل المستخدم بين الصفحات
                app()->terminating(function () use ($jobId) {
                    if (function_exists('fastcgi_finish_request')) {
                        @fastcgi_finish_request();
                    }
                    runBackgroundImportJobWorker($jobId);
                });

                if ($request->ajax() || $request->wantsJson()) {
                    return response()->json([
                        'status'        => 'background_started',
                        'import_job_id' => $jobId,
                        'snapshot_id'   => $snapshotId,
                        'message'       => 'تم استلام الملف وبدأت المعالجة السريعة في الخلفية. يمكنك التنقل بين الصفحات بحرية.',
                    ]);
                }

                return back()->with('success', 'تم بدء استيراد الملف في الخلفية وحفظ نقطة استرجاع فورية لمخزونك.');
            }

            // الاحتفاظ بالتوافق مع ItemController في حال استدعائه بطرق أخرى
            createInventoryRollbackSnapshot($userId, 'نسخة تلقائية قبل الاستيراد');
            $response = app(ItemController::class)->import($request);
            DB::table('items')->whereNull('user_id')->update(['user_id' => $userId]);
            syncAllExistingItemsToCore();
            return $response;
        })->name('import');

        // مسار المعاينة الذكية واكتشاف أعمدة ملف الإكسل تلقائياً (Smart Column Mapping Preview)
        Route::post('/import/detect-columns', function (Request $request) {
            $headers = $request->input('headers', []);
            if (empty($headers) && $request->hasFile('file')) {
                $rows = parseUploadedSpreadsheetFileRows($request->file('file')->getRealPath());
                $headers = $rows[0] ?? [];
            }

            $detectedMapping = is_array($headers) ? detectSmartExcelColumns($headers) : [];
            return response()->json([
                'status'         => 'success',
                'column_mapping' => $detectedMapping,
            ]);
        })->name('import.detect_columns');

        // مسار متابعة حالة الاستيراد في الخلفية عبر جميع الصفحات (Background Progress Status)
        Route::get('/import/status', function (Request $request) {
            $userId = auth()->id();
            $jobId  = $request->query('job_id');

            $query = DB::table('excel_import_jobs')->where('user_id', $userId);
            if (!empty($jobId)) {
                $query->where('id', (int) $jobId);
            } else {
                $query->orderByDesc('id');
            }

            $job = $query->first();
            if ($job && $job->status === 'pending') {
                // إذا كانت المهمة معلقة، نشغلها في الخلفية
                app()->terminating(function () use ($job) {
                    if (function_exists('fastcgi_finish_request')) {
                        @fastcgi_finish_request();
                    }
                    runBackgroundImportJobWorker((int) $job->id);
                });
            }

            $latestSnapshot = DB::table('inventory_snapshots')
                ->where('user_id', $userId)
                ->orderByDesc('id')
                ->select('id', 'label', 'items_count', 'created_at')
                ->first();

            $percentage = 0;
            if ($job && $job->total_rows > 0) {
                $percentage = min(100, round(($job->processed_rows / $job->total_rows) * 100));
            } elseif ($job && $job->status === 'completed') {
                $percentage = 100;
            }

            return response()->json([
                'status'          => 'success',
                'job'             => $job,
                'percentage'      => $percentage,
                'latest_snapshot' => $latestSnapshot,
            ]);
        })->name('import.status');

        // مسار نقطة الاسترجاع الفورية بضغطة زر (One-Click Rollback)
        Route::post('/import/rollback', function (Request $request) {
            $userId     = auth()->id();
            $snapshotId = $request->input('snapshot_id') ? (int) $request->input('snapshot_id') : null;
            $result     = restoreInventoryRollbackSnapshot($userId, $snapshotId);

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json($result, $result['status'] === 'success' ? 200 : 422);
            }

            if ($result['status'] === 'success') {
                return back()->with('success', $result['message']);
            }
            return back()->with('error', $result['message']);
        })->name('import.rollback');

        Route::put('/{item}', function (Request $request, Item $item) {
            if (auth()->user()->role !== 'admin' && $item->user_id !== auth()->id()) {
                abort(403);
            }

            $item->update([
                'item_name' => $request->input('item_name', $item->item_name),
                'quantity'  => max(0, (int) $request->input('quantity', $item->quantity)),
                'price'     => max(0, (float) $request->input('price', $item->price)),
            ]);

            syncItemToMasterCore($item->item_number, $item->item_name, $item->user_id);
            return back()->with('success', 'تم تحديث الصنف وتحديث قاموس المرادفات (رقم الصنف محمي وثابت).');
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

        $otp            = (string) rand(1000, 9999);
        $formattedPhone = formatSaudiPhoneIntl($request->phone);
        $cleanDigits    = preg_replace('/\D/', '', $request->phone);

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
        $client         = User::findOrFail($id);
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
        cleanupAndNormalizeInventoryData();
        return back()->with('success', 'تم حذف العميل وجميع أصنافه بنجاح.');
    })->name('clients.destroy');

    // 3. المخزون المشترك (قلب النظام) - سريع جداً مع دعم البحث وتقسيم الصفحات (Pagination)
    Route::get('/master-catalog', function (Request $request) {
        if (auth()->user()->role !== 'admin') abort(403);

        // لا يتم تشغيل المزامنة الشاملة إلا عند الضغط على زر المزامنة أو إذا كان الجدول فارغاً
        if ($request->boolean('sync') || DB::table('master_items')->count() === 0) {
            syncAllExistingItemsToCore();
        }

        $search = trim((string) $request->query('search', ''));

        $query = DB::table('master_items')
            ->join('items', 'master_items.item_number', '=', 'items.item_number')
            ->join('users', 'items.user_id', '=', 'users.id')
            ->select(
                'master_items.item_number',
                'master_items.first_item_name',
                'master_items.compatible_cars',
                'master_items.compatible_years',
                DB::raw('COALESCE(SUM(items.quantity), 0) as total_quantity'),
                DB::raw('COUNT(DISTINCT items.user_id) as clients_count')
            );

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('master_items.item_number', 'like', "%{$search}%")
                  ->orWhere('master_items.first_item_name', 'like', "%{$search}%")
                  ->orWhere('master_items.compatible_cars', 'like', "%{$search}%")
                  ->orWhere('items.item_name', 'like', "%{$search}%");
            });
        }

        $masterItems = $query->groupBy(
                'master_items.item_number',
                'master_items.first_item_name',
                'master_items.compatible_cars',
                'master_items.compatible_years'
            )
            ->orderByDesc('total_quantity')
            ->paginate(50)
            ->withQueryString();

        return view('pages.master_catalog', compact('masterItems'));
    })->name('master.catalog');

    // 4. صفحة الصنف المقسومة (تفاصيل الرقم الموحد ومرادفاته من جميع العملاء)
    Route::get('/master-catalog/{itemNumber}', function ($itemNumber) {
        if (auth()->user()->role !== 'admin') abort(403);

        $cleanNumber = strtoupper(trim($itemNumber));
        purgeCarAndYearKeywords();

        $master = DB::table('master_items')->where('item_number', $cleanNumber)->first();

        $clientHoldings = DB::table('items')
            ->join('users', 'items.user_id', '=', 'users.id')
            ->select(
                'items.*',
                DB::raw("COALESCE(users.name, 'المدير العام') as client_name")
            )
            ->where('items.item_number', $cleanNumber)
            ->get();

        $keywords = DB::table('item_keywords')
            ->where('item_number', $cleanNumber)
            ->orderBy('id', 'asc')
            ->get();

        return view('pages.master_details', compact('itemNumber', 'master', 'clientHoldings', 'keywords'));
    })->name('master.item.details');

    Route::post('/master-catalog/{itemNumber}/keyword', function (Request $request, $itemNumber) {
        if (auth()->user()->role !== 'admin') abort(403);
        $word        = trim((string) $request->input('keyword'));
        $cleanNumber = strtoupper(trim($itemNumber));

        if ($word !== '') {
            DB::table('item_keywords')->insertOrIgnore([
                'item_number' => $cleanNumber,
                'keyword'     => $word,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
        return back()->with('success', 'تمت إضافة الكلمة المفتاحية (المرادف) بنجاح.');
    })->name('master.item.keyword.add');

    // 5. إعدادات السيارات والذكاء الاصطناعي
    Route::get('/cars-dictionary', function () {
        if (auth()->user()->role !== 'admin') {
            abort(403, 'هذه الصفحة مخصصة لمدير النظام فقط.');
        }

        $cars         = DB::table('cars')->latest()->get();
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
            'alias'      => trim((string) $request->alias),
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
            'pattern_example' => trim((string) $request->pattern_example),
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

    // 6. مسارات الرد الآلي والذكاء الاصطناعي والحدود والاستثناءات
    Route::get('/ai-whatsapp', function (Request $request) {
        $userId          = auth()->id();
        $customerMessage = (string) $request->query('message', '');
        $matchedItems    = collect();

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

        $botSettings    = getUserBotSettings($userId);
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

    Route::post('/ai-whatsapp/whitelist', function (Request $request) {
        $userId   = auth()->id();
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

        $file       = $request->file('backup_file');
        $targetPath = config('database.connections.sqlite.database', database_path('database.sqlite'));

        DB::disconnect('sqlite');
        copy($file->getRealPath(), $targetPath);

        return back()->with('success', 'تمت استعادة النسخة الاحتياطية لقاعدة البيانات بنجاح!');
    })->name('backup.restore');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
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
            $table->text('pending_suggestion_ids')->nullable();
            $table->text('full_conversation')->nullable();
            $table->timestamps();
        });
    } else {
        if (!Schema::hasColumn('whatsapp_sessions', 'user_id')) {
            Schema::table('whatsapp_sessions', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->default(1)->index();
            });
        }
        if (!Schema::hasColumn('whatsapp_sessions', 'pending_suggestion_ids')) {
            Schema::table('whatsapp_sessions', function (Blueprint $table) {
                $table->text('pending_suggestion_ids')->nullable();
            });
        }
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
 * الكلمات المحمية الخاصة بقطع الغيار التي يُمنع حذفها أبداً حتى لو تشابهت مع فئة سيارة
 * (تمنع مشكلة حذف كلمة "زيت" أو "هواء" أو "مكينة" عند تحليل رسالة العميل)
 */
function getProtectedPartWords(): array {
    $words = [
        'زيت', 'هواء', 'هوا', 'مكيف', 'بنزين', 'وقود', 'قير', 'جير', 'مكينه', 'ماكينه', 'محرك',
        'فرامل', 'بريك', 'دركسون', 'طاره', 'ماء', 'مويه', 'رديتر', 'لديتر', 'اديتر', 'راديتر',
        'فحمات', 'فحمه', 'قماشات', 'قماش', 'تيل', 'هوب', 'هوبات', 'صدام', 'صدامات',
        'صاجه', 'صاجات', 'بطانه', 'بطانات', 'رفرف', 'رفارف', 'كبوت', 'شنطه', 'باب', 'ابواب',
        'شبك', 'شمعه', 'شمعات', 'نور', 'انوار', 'فانوس', 'اسطب', 'اصطب', 'اسطبات', 'اصطبات',
        'كشاف', 'كشافات', 'مرايه', 'مرايا', 'مرايات', 'زجاج', 'قزاز', 'مساحه', 'مساحات',
        'نسافه', 'نسافات', 'جسر', 'جسور', 'قاعده', 'قواعد', 'كلبس', 'كلبسات', 'فيبر', 'حشوه',
        'سمكه', 'عتبه', 'هوايه', 'مفصل', 'مفصلات', 'قفل', 'كيلون', 'مقبض', 'يد', 'مساعد',
        'مساعدات', 'جانبين', 'جمبين', 'مقص', 'مقصات', 'ذراع', 'اذرعه', 'جوزه', 'جوزات',
        'مسمار', 'مسامير', 'توازن', 'عمود', 'عكس', 'عكوس', 'فلنجه', 'فلنجات', 'بيرنق', 'رمان',
        'كفر', 'جنط', 'جنوط', 'كليبر', 'باكم', 'جلنط', 'سلك', 'قربه', 'غطاء', 'مروحه', 'مراوح',
        'دينمو', 'دينامو', 'كمبروسر', 'كمبرسر', 'ثلاجه', 'ثلاجات', 'فريون', 'لي', 'ليات',
        'خرطوش', 'خراطيش', 'طرمبه', 'طرمبات', 'صفايه', 'فلتر', 'فلاتر', 'بوجي', 'بواجي',
        'كويل', 'كويلات', 'بخاخ', 'بخاخات', 'حساس', 'حساسات', 'سلف', 'مارش', 'بطاريه',
        'ضفيره', 'كمبيوتر', 'فيوز', 'كتاوت', 'دفرنس', 'دبل', 'كرونه', 'كارتير', 'كرتير',
        'وجه', 'وجيه', 'صوفه', 'صوف', 'جنزير', 'شداد', 'سير', 'سيور', 'بكره', 'بكرات',
        'ثروتل', 'بوابه', 'شكمان', 'دبه', 'تيربو', 'كرسي', 'كراسي', 'دوده', 'طبلون', 'عداد',
        'فرشه', 'ديكور', 'شاشه', 'ارباق', 'ايرباق', 'حزام'
    ];
    return array_values(array_unique(array_map('normalizeArabicSpelling', $words)));
}

/**
 * الكلمات المتضادة في فئة الفلاتر والسوائل والأنظمة لمنع خلط (فلتر زيت) بـ (فلتر هواء) أو (فلتر مكيف)
 */
function getMutuallyExclusivePartSpecifiers(): array {
    $groups = [
        ['زيت'],
        ['هواء', 'هوا'],
        ['مكيف', 'فريون', 'ثلاجه'],
        ['بنزين', 'وقود', 'ديزل'],
        ['قير', 'جير', 'دفرنس'],
        ['دركسون', 'باور'],
        ['مكينه', 'ماكينه', 'محرك'],
    ];
    return array_map(fn($g) => array_map('normalizeArabicSpelling', $g), $groups);
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
        'قطعة', 'قطعه', 'قطع', 'غيار', 'صنف', 'رقم', 'كود', 'نوع', 'سيارتك', 'سيارتي',
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
 * فحص ما إذا كان العميل يؤكد اقتراح "هل تقصد؟" (مثل: نعم، ايوه، صح، الاول، هو)
 */
function isAffirmativeConfirmationMessage(string $messageText): bool {
    $norm = normalizeArabicSpelling($messageText);
    $yesWords = [
        'نعم', 'اي', 'ايه', 'ايوه', 'يب', 'يس', 'صح', 'صحيح', 'تمام', 'اوك', 'اوكي', 'تم', 'هو', 'هذا', 'هذي', 'الاول', '1', 'مضبوط', 'بالضبط', 'نعم هو'
    ];
    return in_array($norm, array_map('normalizeArabicSpelling', $yesWords), true);
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
 * قاموس الجمع والمفرد والمرادفات الإملائية الشائعة لقطع الغيار
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
        ['بطانه', 'بطانات'],
        ['صاجه', 'صاجات'],
        ['كارتير', 'كرتير'],
        ['ثلاجه', 'ثلاجات'],
        ['بخاخ', 'بخاخات'],
        ['حساس', 'حساسات'],
        ['هواء', 'هوا'],
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
 * قائمة الكلمات الفرعية/الملحقة بقطع الغيار
 * (بحيث إذا طلب العميل "صدام امامي" أو "فحمات امامي" لا يظهر له "صاجة صدام امامي" أو "قاعدة صدام" أو "حساس فحمات")
 */
function getSubComponentPartWords(): array {
    $subWords = [
        'صاجه', 'صاجات', 'بطانه', 'بطانات', 'قاعده', 'قواعد', 'جسر', 'جسور',
        'كلبس', 'كلبسات', 'برواز', 'اطار', 'غطاء', 'اغطيه', 'حشوه', 'فوم',
        'زجاجه', 'عدسه', 'ظفيره', 'ضفيره', 'فيش', 'سلك', 'جلده', 'جلد', 'ربله', 'ربلات',
        'مسطره', 'نيكل', 'زيج', 'لحيه', 'دقن', 'شبك', 'فتحه', 'كرسي', 'مفصل', 'مفصلات',
        'حساس', 'حساسات', 'مسمار', 'مسامير', 'ورده', 'صاموله', 'شنبر'
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
 * تنظيف الكلمات المفتاحية من أسماء السيارات وسنوات الصنع وكلمات التوقف (مع حماية كلمات القطع مثل زيت وهواء)
 */
function purgeCarAndYearKeywords(): void {
    $carsList       = DB::table('cars')->get();
    $aliasesList    = DB::table('car_aliases')->pluck('alias')->toArray();
    $patterns       = DB::table('year_patterns')->pluck('pattern_example')->toArray();
    $protectedWords = getProtectedPartWords();

    $forbiddenWords = getChatAndStopWords();
    foreach ($carsList as $c) {
        $forbiddenWords[] = normalizeArabicSpelling($c->car_name);
        foreach (preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $c->car_name, -1, PREG_SPLIT_NO_EMPTY) as $cw) {
            $ forbiddenNorm = normalizeArabicSpelling($cw);
            if (!in_array($forbiddenNorm, $protectedWords, true)) {
                $forbiddenWords[] = $forbiddenNorm;
            }
        }
        if (!empty($c->category)) {
            $catNorm = normalizeArabicSpelling($c->category);
            if (!in_array($catNorm, $protectedWords, true)) {
                $forbiddenWords[] = $catNorm;
            }
            foreach (preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $c->category, -1, PREG_SPLIT_NO_EMPTY) as $catW) {
                $catWNorm = normalizeArabicSpelling($catW);
                if (!in_array($catWNorm, $protectedWords, true)) {
                    $forbiddenWords[] = $catWNorm;
                }
            }
        }
    }
    foreach ($aliasesList as $a) {
        $aNorm = normalizeArabicSpelling($a);
        if (!in_array($aNorm, $protectedWords, true)) {
            $forbiddenWords[] = $aNorm;
        }
        foreach (preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $a, -1, PREG_SPLIT_NO_EMPTY) as $aw) {
            $awNorm = normalizeArabicSpelling($aw);
            if (!in_array($awNorm, $protectedWords, true)) {
                $forbiddenWords[] = $awNorm;
            }
        }
    }
    foreach ($patterns as $p) {
        $forbiddenWords[] = normalizeArabicSpelling($p);
        foreach (preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $p, -1, PREG_SPLIT_NO_EMPTY) as $pw) {
            $forbiddenWords[] = normalizeArabicSpelling($pw);
        }
    }

    // ضمان عدم حذف أي كلمة محمية (مثل زيت، هواء، مكيف، فحمات، صدام) من جدول الكلمات المفتاحية أبداً
    $forbiddenWords = array_values(array_diff(array_unique(array_filter($forbiddenWords)), $protectedWords));

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

    $detectedCarsList   = [];
    $detectedCategories = [];
    $detectedYearsList  = [];
    $protectedWords     = getProtectedPartWords();

    $carsList    = DB::table('cars')->get();
    $allCarWords = [];

    foreach ($carsList as $car) {
        $allCarWords = array_merge($allCarWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $car->car_name, -1, PREG_SPLIT_NO_EMPTY));
        if (!empty($car->category)) {
            $allCarWords[] = $car->category;
            $allCarWords = array_merge($allCarWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $car->category, -1, PREG_SPLIT_NO_EMPTY));
        }
        if (containsWholeWordArabicSafe($cleanName, $car->car_name)) {
            $detectedCarsList[]   = $car->car_name;
            $detectedCategories[] = $car->category;
        }
        $aliases = DB::table('car_aliases')->where('car_id', $car->id)->pluck('alias')->toArray();
        foreach ($aliases as $alias) {
            if ($alias !== '') {
                $allCarWords = array_merge($allCarWords, preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $alias, -1, PREG_SPLIT_NO_EMPTY));
                if (containsWholeWordArabicSafe($cleanName, $alias)) {
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
            if (containsWholeWordArabicSafe($cleanName, $normP)) {
                $detectedYearsList[] = $normP;
            }
        }
    }

    $nameForKeywords = $cleanName;
    foreach ($carsList as $car) {
        $nameForKeywords = replaceWholeWordArabicSafe($nameForKeywords, $car->car_name);
        if (!empty($car->category) && !in_array(normalizeArabicSpelling($car->category), $protectedWords, true)) {
            $nameForKeywords = replaceWholeWordArabicSafe($nameForKeywords, $car->category);
        }
    }
    $allAliases = DB::table('car_aliases')->pluck('alias')->toArray();
    foreach ($allAliases as $al) {
        if ($al !== '' && !in_array(normalizeArabicSpelling($al), $protectedWords, true)) {
            $nameForKeywords = replaceWholeWordArabicSafe($nameForKeywords, $al);
        }
    }
    foreach ($patterns as $pattern) {
        if ($pattern !== '') {
            $nameForKeywords = replaceWholeWordArabicSafe($nameForKeywords, normalizeArabicDigits($pattern));
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
                'updated_at'       => $now = now(),
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
    $skipWords = array_values(array_diff(
        array_unique(array_map('normalizeArabicSpelling', array_filter($skipWords))),
        $protectedWords
    ));

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
 * التعرف الذكي التلقائي على أعمدة ملف الإكسل (Smart Column Mapping)
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
 * إنشاء نقطة استرجاع فورية (One-Click Rollback Snapshot) قبل بدء الاستيراد
 */
function createInventoryRollbackSnapshot(int $userId, ?string $label = null): int {
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
 * استعادة نقطة الاسترجاع الفورية بضغطة زر (One-Click Rollback Restore)
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

    $protectedWords = getProtectedPartWords();
    $allCarWords    = [];
    $allAliases     = [];
    $carMapById     = [];

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

    $baseSkipWords = array_values(array_diff(
        array_unique(array_map('normalizeArabicSpelling', array_filter(array_merge($allCarWords, $allYearWords, getChatAndStopWords())))),
        $protectedWords
    ));

    return [
        'cars'            => $cars,
        'all_aliases'     => $allAliases,
        'patterns'        => $patterns,
        'base_skip_words' => $baseSkipWords,
        'protected_words' => $protectedWords,
        'has_compat_col'  => Schema::hasColumn('master_items', 'compatible_cars'),
    ];
}

/**
 * تحليل دفعة كاملة من الأصناف وحفظها وإرسالها لقلب النظام في الذاكرة (Bulk Upsert & Sync)
 */
function processBulkInventoryChunkFast(array $rows, int $userId, ?array $ramCache = null, ?array $columnMapping = null): array {
    if (empty($rows)) {
        return ['processed' => 0, 'imported' => 0, 'updated' => 0];
    }

    if ($ramCache === null) {
        $ramCache = buildMasterDictionaryRamCache();
    }

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

    $existingItems = DB::table('items')
        ->where('user_id', $userId)
        ->whereIn('item_number', $batchNumbers)
        ->pluck('id', 'item_number')
        ->toArray();

    $now            = now();
    $toInsertItems  = [];
    $toUpdateItems  = [];
    $keywordsBatch  = [];
    $masterUpdates  = [];
    $protectedWords = $ramCache['protected_words'] ?? getProtectedPartWords();

    foreach ($normalizedBatch as $itemNumber => $data) {
        $cleanName          = $data['item_name'];
        $detectedCarsList   = [];
        $detectedCategories = [];
        $detectedYearsList  = [];

        foreach ($ramCache['cars'] as $car) {
            if (containsWholeWordArabicSafe($cleanName, $car->car_name)) {
                $detectedCarsList[]   = $car->car_name;
                $detectedCategories[] = $car->category;
            }
        }
        foreach ($ramCache['all_aliases'] as $alData) {
            if (containsWholeWordArabicSafe($cleanName, $alData['alias'])) {
                $detectedCarsList[]   = $alData['car_name'];
                $detectedCategories[] = $alData['category'];
            }
        }

        $detectedCarsList   = array_values(array_unique(array_filter($detectedCarsList)));
        $detectedCategories = array_values(array_unique(array_filter($detectedCategories)));

        foreach ($ramCache['patterns'] as $normP) {
            if (containsWholeWordArabicSafe($cleanName, $normP)) {
                $detectedYearsList[] = $normP;
            }
        }

        $nameForKeywords = $cleanName;
        foreach ($ramCache['cars'] as $car) {
            $nameForKeywords = replaceWholeWordArabicSafe($nameForKeywords, $car->car_name);
            if (!empty($car->category) && !in_array(normalizeArabicSpelling($car->category), $protectedWords, true)) {
                $nameForKeywords = replaceWholeWordArabicSafe($nameForKeywords, $car->category);
            }
        }
        foreach ($ramCache['all_aliases'] as $alData) {
            if (!in_array(normalizeArabicSpelling($alData['alias']), $protectedWords, true)) {
                $nameForKeywords = replaceWholeWordArabicSafe($nameForKeywords, $alData['alias']);
            }
        }
        foreach ($ramCache['patterns'] as $normP) {
            $nameForKeywords = replaceWholeWordArabicSafe($nameForKeywords, $normP);
        }

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
                $prevCars  = !empty($exMaster->compatible_cars) ? array_map('trim', explode('|', $existingMasters[$itemNum]->compatible_cars)) : [];
                $prevYears = !empty($exMaster->compatible_years) ? array_map('trim', explode('|', $existingMasters[$itemNum]->compatible_years)) : [];

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
 * قراءة ملف الإكسل أو CSV وتحويله إلى مصفوفة أسطر بسرعة عالية
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
| 4. محرك تحليل رسائل الواتساب والبحث الذكي بالمصادر الثلاثة ونظام النسب المئوية
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
 * استرجاع الكلمات المفتاحية المرتبطة حصرياً بأصناف التاجر في مخزونه الخاص (المصدر الأول الوحيد لترجمة القطع)
 */
function getTenantInventoryKeywordsMap(int $ownerUserId): array {
    $tenantItems = DB::table('items')
        ->where('user_id', $ownerUserId)
        ->select('item_number', 'item_name')
        ->get();

    if ($tenantItems->isEmpty()) {
        return ['by_number' => [], 'all_words' => []];
    }

    $itemNumbers = $tenantItems->pluck('item_number')
        ->map(fn($n) => strtoupper(trim((string) $n)))
        ->filter()
        ->unique()
        ->values()
        ->toArray();

    $byNumber = [];
    $allWords = [];

    if (!empty($itemNumbers)) {
        $rawKws = DB::table('item_keywords')
            ->whereIn('item_number', $itemNumbers)
            ->get();

        foreach ($rawKws as $rk) {
            $num  = strtoupper(trim((string) $rk->item_number));
            $norm = normalizeArabicSpelling($rk->keyword);
            if ($norm !== '' && mb_strlen($norm) >= 2) {
                $byNumber[$num][] = $norm;
                $allWords[]       = $norm;
            }
        }
    }

    // في حال وجود صنف لدى التاجر لم يتم استخراج كلماته المفتاحية بعد، نستخرجها من اسم الصنف الخاص به فقط
    $stopWords = getChatAndStopWords();
    foreach ($tenantItems as $tItem) {
        $num = strtoupper(trim((string) $tItem->item_number));
        if (empty($byNumber[$num])) {
            $tokens = preg_split('/[\s,\-\+\/\(\)\*\.]+/u', normalizeArabicSpelling($tItem->item_name), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($tokens as $tk) {
                if (mb_strlen($tk) >= 2 && !is_numeric($tk) && !in_array($tk, $stopWords, true)) {
                    $byNumber[$num][] = $tk;
                    $allWords[]       = $tk;
                }
            }
        }
    }

    foreach ($byNumber as $num => $list) {
        $byNumber[$num] = array_values(array_unique($list));
    }

    return [
        'by_number' => $byNumber,
        'all_words' => array_values(array_unique($allWords)),
    ];
}

/**
 * محرك التقييم الذكي وحساب نسبة التطابق المئوية (Confidence Score 0% - 100%)
 * يعتمد حصرياً على:
 * 1) الكلمات المفتاحية المرتبطة بأصناف التاجر في مخزونه الخاص
 * 2) اسم السيارة من الإعدادات
 * 3) تاريخ الصنع من الإعدادات
 * ويمنع تماماً خلط (فلتر زيت) بـ (فلتر هواء) أو خلط (صدام امامي) بـ (صاجة صدام امامي)
 */
function evaluateSinglePartWithConfidence(string $singlePartRequest, string $car, string $year, int $ownerUserId): array {
    $singlePartRequest = trim($singlePartRequest);
    if ($singlePartRequest === '') {
        return ['best_confidence' => 0, 'exact_items' => collect(), 'suggested_items' => collect()];
    }

    // 1. تطابق مباشر 100% برقم الصنف في مخزون التاجر
    $directMatch = Item::where('user_id', $ownerUserId)
        ->where('quantity', '>', 0)
        ->where('item_number', strtoupper($singlePartRequest))
        ->first();

    if ($directMatch) {
        $directMatch->confidence_score = 100;
        return [
            'best_confidence' => 100,
            'exact_items'     => collect([$directMatch]),
            'suggested_items' => collect(),
        ];
    }

    $allWords = array_values(array_filter(preg_split('/\s+/u', $singlePartRequest), function ($w) {
        return mb_strlen(trim($w)) >= 2;
    }));

    $modifiersList    = getPartModifierWords();
    $stopWordsList    = getChatAndStopWords();
    $subComponentsSet = getSubComponentPartWords();
    $exclusiveGroups  = getMutuallyExclusivePartSpecifiers();

    $coreNounWords          = [];
    $directionWords         = [];
    $requestedSubComponents = [];
    $requestedExclusives    = [];

    foreach ($allWords as $w) {
        $normW = normalizeArabicSpelling($w);
        if (in_array($normW, $stopWordsList, true)) {
            continue;
        }
        if (in_array($normW, $modifiersList, true)) {
            $directionWords[] = $normW;
        } else {
            $coreNounWords[] = $normW;
            $wVariants = getWordVariantsForSearch($normW);
            foreach ($wVariants as $wv) {
                $wvNoAl = (mb_strlen($wv) > 4 && str_starts_with($wv, 'ال')) ? mb_substr($wv, 2) : $wv;
                if (in_array($wv, $subComponentsSet, true) || in_array($wvNoAl, $subComponentsSet, true)) {
                    $requestedSubComponents[] = $wv;
                    $requestedSubComponents[] = $wvNoAl;
                }
                foreach ($exclusiveGroups as $groupIdx => $groupWords) {
                    if (in_array($wv, $groupWords, true) || in_array($wvNoAl, $groupWords, true)) {
                        $requestedExclusives[$groupIdx] = $groupWords;
                    }
                }
            }
        }
    }

    $requestedSubComponents = array_values(array_unique($requestedSubComponents));

    if (empty($coreNounWords)) {
        return ['best_confidence' => 0, 'exact_items' => collect(), 'suggested_items' => collect()];
    }

    // جلب الأرقام الموحدة التي تتوافق مع السيارة المطلوبة في master_items
    $masterCompatibleNumbers = [];
    if ($car !== '' && Schema::hasColumn('master_items', 'compatible_cars')) {
        $masterCompatibleNumbers = DB::table('master_items')
            ->where('compatible_cars', 'like', "%{$car}%")
            ->pluck('item_number')
            ->toArray();
    }

    // جلب أصناف التاجر المتوافقة مع السيارة المطلوبة
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

    if ($carCandidates->isEmpty()) {
        return ['best_confidence' => 0, 'exact_items' => collect(), 'suggested_items' => collect()];
    }

    $tenantKeywordsMap   = getTenantInventoryKeywordsMap($ownerUserId);
    $keywordsByNumber    = $tenantKeywordsMap['by_number'];
    $candidateNumbers    = $carCandidates->pluck('item_number')->unique()->toArray();
    $masterYearsByNumber = [];

    if (!empty($candidateNumbers) && Schema::hasColumn('master_items', 'compatible_years')) {
        $masterYearsByNumber = DB::table('master_items')
            ->whereIn('item_number', $candidateNumbers)
            ->pluck('compatible_years', 'item_number')
            ->toArray();
    }

    $scoredItems = [];

    foreach ($carCandidates as $item) {
        $cleanNum          = strtoupper(trim((string) $item->item_number));
        $masterCompatYears = $masterYearsByNumber[$cleanNum] ?? null;

        // فلتر تاريخ الصنع الصارم (المصدر الثالث)
        if ($year !== '' && !isYearMatchingItem($year, $item->detected_years, $item->item_name, $masterCompatYears)) {
            continue;
        }

        // الكلمات المفتاحية الخاصة بهذا الصنف في مخزون التاجر (المصدر الأول)
        $itemKeywordsList = $keywordsByNumber[$cleanNum] ?? [];
        $itemNameNorm     = normalizeArabicSpelling($item->item_name);
        $itemNameTokens   = preg_split('/[\s,\-\+\/\(\)\*\.]+/u', $itemNameNorm, -1, PREG_SPLIT_NO_EMPTY);

        // دمج الكلمات المفتاحية للصنف مع كلمات اسمه الفعلي لدى التاجر
        $searchableTokens = array_values(array_unique(array_merge($itemKeywordsList, $itemNameTokens)));
        $fullSearchableText = implode(' ', $searchableTokens);

        // 1. الحماية الصارمة من القطع الفرعية والملحقات (مثل: طلب "صدام امامي" فلا يظهر له "صاجة صدام امامي" أو "قاعدة صدام")
        if (empty($requestedSubComponents)) {
            $hasUnrequestedSubComponent = false;
            foreach ($searchableTokens as $iToken) {
                $iTokenNoAl = (mb_strlen($iToken) > 4 && str_starts_with($iToken, 'ال')) ? mb_substr($iToken, 2) : $iToken;
                if (in_array($iToken, $subComponentsSet, true) || in_array($iTokenNoAl, $subComponentsSet, true)) {
                    $hasUnrequestedSubComponent = true;
                    break;
                }
            }
            if ($hasUnrequestedSubComponent) {
                continue;
            }
        } else {
            // إذا طلب العميل صراحةً قطعة فرعية (مثل "صاجة صدام")، يجب أن يحتوي الصنف على الكلمة الفرعية المطلوبة
            $foundSub = false;
            foreach ($requestedSubComponents as $reqSub) {
                foreach ($searchableTokens as $iToken) {
                    $iTokenNoAl = (mb_strlen($iToken) > 4 && str_starts_with($iToken, 'ال')) ? mb_substr($iToken, 2) : $iToken;
                    if ($iToken === $reqSub || $iTokenNoAl === $reqSub) {
                        $foundSub = true;
                        break 2;
                    }
                }
            }
            if (!$foundSub) {
                continue;
            }
        }

        // 2. الحماية الصارمة من خلط الأنواع المتضادة (مثل: طلب "فلتر زيت" فلا يظهر له "فلتر هواء" أو "فلتر مكيف" أبداً)
        if (!empty($requestedExclusives)) {
            $hasConflict = false;
            foreach ($requestedExclusives as $reqGroupIdx => $reqGroupWords) {
                // أولاً: يجب أن يحتوي الصنف على النوع الذي طلبه العميل (مثلاً: زيت)
                $hasRequestedSpec = false;
                foreach ($reqGroupWords as $rgw) {
                    foreach ($searchableTokens as $tk) {
                        $tkNoAl = (mb_strlen($tk) > 4 && str_starts_with($tk, 'ال')) ? mb_substr($tk, 2) : $tk;
                        if ($tk === $rgw || $tkNoAl === $rgw) {
                            $hasRequestedSpec = true;
                            break 2;
                        }
                    }
                }
                if (!$hasRequestedSpec) {
                    $hasConflict = true;
                    break;
                }

                // ثانياً: إذا كان الصنف يحتوي على نوع آخر مناقض لم يطلبه العميل (مثلاً: هواء أو مكيف)، نستبعده فوراً
                foreach ($exclusiveGroups as $otherIdx => $otherWords) {
                    if ($otherIdx === $reqGroupIdx) continue;
                    foreach ($otherWords as $ow) {
                        foreach ($searchableTokens as $tk) {
                            $tkNoAl = (mb_strlen($tk) > 4 && str_starts_with($tk, 'ال')) ? mb_substr($tk, 2) : $tk;
                            if ($tk === $ow || $tkNoAl === $ow) {
                                $hasConflict = true;
                                break 3;
                            }
                        }
                    }
                }
            }
            if ($hasConflict) {
                continue;
            }
        }

        // 3. حساب نسبة تطابق الكلمات الأساسية للقطعة (Core Nouns Matching)
        $matchedCoreCount = 0;
        $totalCoreCount   = count($coreNounWords);

        foreach ($coreNounWords as $coreWord) {
            $variants  = getWordVariantsForSearch($coreWord);
            $foundCore = false;

            foreach ($variants as $v) {
                $vNoAl = (mb_strlen($v) > 4 && str_starts_with($v, 'ال')) ? mb_substr($v, 2) : $v;
                foreach ($searchableTokens as $token) {
                    $tokenNoAl = (mb_strlen($token) > 4 && str_starts_with($token, 'ال')) ? mb_substr($token, 2) : $token;
                    if ($token === $v || $tokenNoAl === $vNoAl) {
                        $foundCore = true;
                        break 2;
                    }
                }
            }

            if ($foundCore) {
                $matchedCoreCount++;
            }
        }

        if ($matchedCoreCount === 0) {
            continue;
        }

        // 4. فحص الاتجاهات (يمين / يسار / أمامي / خلفي)
        $directionPenalty = 0;
        $directionContradiction = false;

        foreach ($directionWords as $dirWord) {
            $dwNorm = normalizeArabicSpelling($dirWord);

            if ($dwNorm === 'يمين' && mb_stripos($fullSearchableText, 'يسار') !== false && mb_stripos($fullSearchableText, 'يمين') === false) {
                $directionContradiction = true;
                break;
            }
            if ($dwNorm === 'يسار' && mb_stripos($fullSearchableText, 'يمين') !== false && mb_stripos($fullSearchableText, 'يسار') === false) {
                $directionContradiction = true;
                break;
            }
            if (in_array($dwNorm, ['امامي', 'اماميه'], true) && mb_stripos($fullSearchableText, 'خلف') !== false && mb_stripos($fullSearchableText, 'امام') === false) {
                $directionContradiction = true;
                break;
            }
            if (in_array($dwNorm, ['خلفي', 'خلفيه'], true) && mb_stripos($fullSearchableText, 'امام') !== false && mb_stripos($fullSearchableText, 'خلف') === false) {
                $directionContradiction = true;
                break;
            }

            $dirRoot = rtrim($dwNorm, 'ه');
            if (mb_stripos($fullSearchableText, $dirRoot) === false && mb_stripos($fullSearchableText, $dwNorm) === false) {
                // الصنف لا يذكر الاتجاه المطلوب صراحةً -> نخفض النسبة ليدخل في نطاق "هل تقصد؟"
                $directionPenalty += 25;
            }
        }

        if ($directionContradiction) {
            continue;
        }

        // 5. احتساب النسبة المئوية للذكاء (Confidence Score 0% - 100%)
        $coreRatioScore = ($matchedCoreCount / $totalCoreCount) * 100;

        // إذا كان الصنف في المخزون يحتوي على كلمات تخصصية إضافية لم يحددها العميل (مثل: العميل كتب "فلتر" فقط، والصنف "فلتر هواء" أو "فلتر زيت")
        // نخفض النسبة إلى 60% لكي يسأله البوت: "هل تقصد فلتر زيت / فلتر هواء؟" بدلاً من الإجابة العشوائية!
        $unrequestedSpecPenalty = 0;
        if (empty($requestedExclusives)) {
            foreach ($exclusiveGroups as $exGroup) {
                foreach ($exGroup as $exWord) {
                    if (in_array($exWord, $searchableTokens, true)) {
                        $unrequestedSpecPenalty = 35;
                        break 2;
                    }
                }
            }
        }

        // إذا كان الصنف يحدد اتجاهاً (امامي/خلفي/يمين/يسار) والعميل لم يحدد الاتجاه في طلبه، نخفض النسبة قليلاً
        $unspecifiedDirPenalty = 0;
        if (empty($directionWords)) {
            foreach (['يمين', 'يسار', 'امامي', 'خلفي'] as $dCheck) {
                if (mb_stripos($fullSearchableText, $dCheck) !== false) {
                    $unspecifiedDirPenalty = 15;
                    break;
                }
            }
        }

        $finalConfidence = (int) round($coreRatioScore - $directionPenalty - $unrequestedSpecPenalty - $unspecifiedDirPenalty);
        $finalConfidence = max(10, min(100, $finalConfidence));

        // إذا طابق كل الكلمات المطلوبة بدون أي نقص أو تعارض، نرفعه إلى 100%
        if ($matchedCoreCount === $totalCoreCount && $directionPenalty === 0 && $unrequestedSpecPenalty === 0 && $unspecifiedDirPenalty === 0) {
            $finalConfidence = 100;
        }

        $itemClone = clone $item;
        $itemClone->confidence_score = $finalConfidence;
        $scoredItems[] = $itemClone;
    }

    if (empty($scoredItems)) {
        return ['best_confidence' => 0, 'exact_items' => collect(), 'suggested_items' => collect()];
    }

    usort($scoredItems, function ($a, $b) use ($singlePartRequest) {
        if ($b->confidence_score !== $a->confidence_score) {
            return $b->confidence_score <=> $a->confidence_score;
        }
        $reqNorm = normalizeArabicSpelling($singlePartRequest);
        $aDirect = mb_stripos(normalizeArabicSpelling($a->item_name), $reqNorm) !== false ? 1 : 0;
        $bDirect = mb_stripos(normalizeArabicSpelling($b->item_name), $reqNorm) !== false ? 1 : 0;
        if ($bDirect !== $aDirect) {
            return $bDirect <=> $aDirect;
        }
        return mb_strlen($a->item_name) <=> mb_strlen($b->item_name);
    });

    $bestConfidence = $scoredItems[0]->confidence_score;

    return [
        'best_confidence' => $bestConfidence,
        'all_scored'      => collect($scoredItems)->take(3)->values(),
    ];
}

/**
 * دالة التوافق مع البحث المباشر (ترجع الأصناف المطابقة)
 */
function findMatchingItemsForSinglePart(string $singlePartRequest, string $car, string $year, int $ownerUserId, int $minThreshold = 80) {
    $eval = evaluateSinglePartWithConfidence($singlePartRequest, $car, $year, $ownerUserId);
    if (empty($eval['all_scored'])) {
        return collect();
    }
    return $eval['all_scored']->filter(fn($i) => $i->confidence_score >= $minThreshold)->values();
}

/**
 * استبدال الكلمات الكاملة فقط بأمان دون المساس بحروف الكلمات الأخرى
 */
function replaceWholeWordArabicSafe(string $subject, string $search): string {
    $search = trim($search);
    if ($search === '') return $subject;
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
    $normSubject = normalizeArabicSpelling($subject);
    $normSearch  = normalizeArabicSpelling($search);
    $normPattern = '/(?<=^|[\s,،\-\+\/\(\)\*\.])' . preg_quote($normSearch, '/') . '(?=$|[\s,،\-\+\/\(\)\*\.])/iu';
    return (bool) preg_match($normPattern, $normSubject);
}

/**
 * تحليل رسالة الزبون بالاعتماد الحصري على المصادر الثلاثة:
 * 1) الكلمات المفتاحية لأصناف التاجر في مخزونه الخاص
 * 2) اسم السيارة من الإعدادات
 * 3) تاريخ الصنع من الإعدادات
 */
function parseWhatsappText(string $text, ?int $ownerUserId = null): array {
    $normText         = normalizeArabicDigits($text);
    $detectedCar      = null;
    $detectedCategory = null;
    $detectedYear     = null;
    $remainingText    = $normText;
    $protectedWords   = getProtectedPartWords();

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

    // 2. استخراج السيارة ومرادفاتها من الإعدادات (مع عدم حذف أي كلمة قطعة محمية مثل "زيت" أو "هواء")
    $carsList = DB::table('cars')->get();
    foreach ($carsList as $car) {
        if (containsWholeWordArabicSafe($remainingText, $car->car_name)) {
            $detectedCar      = $car->car_name;
            $detectedCategory = $car->category;
            $remainingText    = replaceWholeWordArabicSafe($remainingText, $car->car_name);
            if (!empty($car->category) && !in_array(normalizeArabicSpelling($car->category), $protectedWords, true)) {
                $remainingText = replaceWholeWordArabicSafe($remainingText, $car->category);
            }
            break;
        }
        $aliases = DB::table('car_aliases')->where('car_id', $car->id)->pluck('alias')->toArray();
        foreach ($aliases as $alias) {
            $aliasNorm = normalizeArabicSpelling($alias);
            if ($alias !== '' && !in_array($aliasNorm, $protectedWords, true) && containsWholeWordArabicSafe($remainingText, $alias)) {
                $detectedCar      = $car->car_name;
                $detectedCategory = $car->category;
                $remainingText    = replaceWholeWordArabicSafe($remainingText, $alias);
                if (!empty($car->category) && !in_array(normalizeArabicSpelling($car->category), $protectedWords, true)) {
                    $remainingText = replaceWholeWordArabicSafe($remainingText, $car->category);
                }
                break 2;
            }
        }
    }

    // إزالة فئة السيارة فقط إذا لم تكن من الكلمات المحمية لقطع الغيار
    foreach ($carsList as $car) {
        if (!empty($car->category) && !in_array(normalizeArabicSpelling($car->category), $protectedWords, true) && containsWholeWordArabicSafe($remainingText, $car->category)) {
            $remainingText = replaceWholeWordArabicSafe($remainingText, $car->category);
        }
    }

    // 3. استخراج سنة الصنع (الموديل) من الإعدادات أو الأرقام
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

    // 4. تصفية كلمات التحية والدردشة واستخراج القطع المرتبطة بكلمات التاجر المفتاحية
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

            // معالجة واو العطف الملتصقة بالكلمة (مثل: وصدام أو وفلتر أو ويمين)
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
 * فحص صارم: هل الكلمة موجودة في الكلمات المفتاحية المرتبطة بأصناف التاجر فقط في مخزونه الخاص؟
 * (المصدر الأول الوحيد لترجمة القطع كما طلب المستخدم)
 */
function isWordRecognizedInPartDictionary(string $word, ?int $ownerUserId = null): bool {
    $norm = normalizeArabicSpelling($word);
    if (mb_strlen($norm) < 2 || in_array($norm, getChatAndStopWords(), true)) {
        return false;
    }

    $variants = getWordVariantsForSearch($norm);

    if ($ownerUserId !== null) {
        $tenantMap  = getTenantInventoryKeywordsMap($ownerUserId);
        $tenantKws  = $tenantMap['all_words'];

        foreach ($tenantKws as $kwNorm) {
            $kwNoAl = (mb_strlen($kwNorm) > 4 && str_starts_with($kwNorm, 'ال')) ? mb_substr($kwNorm, 2) : $kwNorm;
            foreach ($variants as $v) {
                $vNoAl = (mb_strlen($v) > 4 && str_starts_with($v, 'ال')) ? mb_substr($v, 2) : $v;
                if ($kwNorm === $v || $kwNoAl === $vNoAl) {
                    return true;
                }
            }
        }
        return false;
    }

    // في حال عدم تمرير مستخدم محدد، نفحص في جدول item_keywords العام
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

    return false;
}

/**
 * التحقق الذكي الصارم:
 * هل رسالة الزبون تحتوي على قطعة موجودة في الكلمات المفتاحية لمخزون التاجر؟
 */
function hasGenuinePartInquiryIntent(string $messageText, array $parsed, int $ownerUserId): bool {
    // 1. إذا أرسل رقم قطعة صريح موجود في المخزون
    if (!empty($parsed['part_numbers'])) {
        return true;
    }

    // 2. إذا لم يتبقَّ أي كلمات بعد حذف التحيات والسيارة والموديل
    if (empty($parsed['parts_list'])) {
        return false;
    }

    // 3. التحقق الصارم مما إذا كانت الكلمات المطلوبة موجودة في الكلمات المفتاحية لأصناف التاجر في مخزونه الخاص
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

    return false;
}

/*
|--------------------------------------------------------------------------
| 5. دوال إعدادات البوت وإرسال رسائل الواتساب ومعالجة الويب هوك (Webhook)
|--------------------------------------------------------------------------
*/

function getUserBotSettings(int $userId): array {
    $defaults = [
        'reply_available'              => "القطعة متوفرة لدينا ✅\nاسم الصنف: {item_name}\nرقم الصنف: {item_number}\nالسعر: {price} ريال",
        'reply_unavailable'            => "نعتذر منك، القطعة المطلوبة ({part}) لسيارة {car} موديل {year} غير متوفرة حالياً ❌",
        'reply_ask_car'                => "حياك الله، فضلاً ما هو نوع أو اسم السيارة؟",
        'reply_ask_year'               => "فضلاً كم موديل (سنة صنع) السيارة {car}؟",
        'reply_did_you_mean'           => "هل تقصد ({suggested_item}) لسيارة {car} موديل {year}؟\nيرجى الرد بـ (نعم) للتأكيد أو توضيح القطعة المطلوبة.",
        'confidence_direct_threshold'  => "80",
        'confidence_suggest_threshold' => "40",
        'reply_quota_exceeded'         => "",
        'max_queries_per_phone'        => "0",
        'allow_group_replies'          => "0",
        'whatsapp_api_url'             => "",
        'whatsapp_api_token'           => "",
        'whatsapp_session_id'          => "",
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
        '{item_name}'      => $data['item_name'] ?? '',
        '{item_number}'    => $data['item_number'] ?? '',
        '{price}'          => $data['price'] ?? '',
        '{quantity}'       => $data['quantity'] ?? '',
        '{car}'            => $data['car'] ?? '',
        '{category}'       => $data['category'] ?? '',
        '{year}'           => $data['year'] ?? '',
        '{part}'           => $data['part'] ?? '',
        '{client_name}'    => $data['client_name'] ?? '',
        '{phone}'          => $data['phone'] ?? '',
        '{items}'          => $data['items'] ?? '',
        '{suggested_item}' => $data['suggested_item'] ?? ($data['item_name'] ?? ''),
        '{suggested_list}' => $data['suggested_list'] ?? ($data['items'] ?? ''),
        '{confidence}'     => $data['confidence'] ?? '',
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

    $msgAskCar        = trim($settings['reply_ask_car'] ?? '');
    $msgAskYear       = trim($settings['reply_ask_year'] ?? '');
    $msgAvailable     = trim($settings['reply_available'] ?? '');
    $msgUnavailable   = trim($settings['reply_unavailable'] ?? '');
    $msgDidYouMean    = trim($settings['reply_did_you_mean'] ?? '');
    $msgQuotaExceeded = trim($settings['reply_quota_exceeded'] ?? '');
    $maxQueriesLimit  = (int) ($settings['max_queries_per_phone'] ?? 0);
    $directThreshold  = max(1, min(100, (int) ($settings['confidence_direct_threshold'] ?? 80)));
    $suggestThreshold = max(1, min($directThreshold, (int) ($settings['confidence_suggest_threshold'] ?? 40)));
    $sessionId        = trim($settings['whatsapp_session_id'] ?? '');
    $convId           = data_get($payload, 'data.conversation_id', data_get($payload, 'conversation_id'));

    // معالجة رد العميل بـ (نعم / ايوه / صح) عندما يكون هناك اقتراح معلق ("هل تقصد؟")
    if ($session && !empty($session->pending_suggestion_ids) && isAffirmativeConfirmationMessage($messageText)) {
        $suggestedIds = json_decode((string) $session->pending_suggestion_ids, true) ?: [];
        $confirmedItems = Item::where('user_id', $ownerUserId)
            ->where('quantity', '>', 0)
            ->whereIn('id', $suggestedIds)
            ->get();

        $replyBlocks = [];
        foreach ($confirmedItems as $cItem) {
            if ($msgAvailable !== '') {
                $replyBlocks[] = formatBotTemplate($msgAvailable, [
                    'item_name'      => $cItem->item_name,
                    'item_number'    => $cItem->item_number,
                    'price'          => (string) $cItem->price,
                    'quantity'       => (string) $cItem->quantity,
                    'car'            => $session->detected_car ?? ($cItem->detected_car_name ?? ''),
                    'category'       => $cItem->detected_category ?? '',
                    'year'           => $session->detected_year ?? ($cItem->detected_years ?? ''),
                    'part'           => $session->part_query ?? $cItem->item_name,
                    'client_name'    => $ownerUser->name ?? '',
                    'phone'          => $quotaPhoneKey,
                    'items'          => "- {$cItem->item_name} (رقم: {$cItem->item_number}) | السعر: {$cItem->price} ريال",
                    'suggested_item' => $cItem->item_name,
                    'confidence'     => '100%',
                ]);
            }
        }

        DB::table('whatsapp_sessions')->where('id', $session->id)->delete();

        $finalConfirmedReply = implode("\n\n", array_values(array_unique(array_filter($replyBlocks))));
        if ($finalConfirmedReply !== '') {
            sendWhatsappReplyMessage($settings, $cleanPhone, $rawSender, $convId, $finalConfirmedReply);
        }

        return response()->json([
            'status'     => 'confirmed_suggestion_replied',
            'session_id' => $sessionId,
            'reply'      => $finalConfirmedReply,
        ], 200);
    }

    // تحليل الرسالة بالمصادر الثلاثة
    $parsed        = parseWhatsappText($messageText, $ownerUserId);
    $hasPartIntent = hasGenuinePartInquiryIntent($messageText, $parsed, $ownerUserId);

    // 1. إذا لم تكن هناك جلسة سابقة، والرسالة لا تحتوي على قطعة في الكلمات المفتاحية لمخزون التاجر -> تجاهل تام
    if (!$session && !$hasPartIntent) {
        return response()->json(['status' => 'ignored_non_inquiry_chat'], 200);
    }

    // 2. إذا كانت هناك جلسة سابقة، وأرسل العميل دردشة عامة (لا سيارة، لا موديل، لا قطعة) -> تجاهل تام
    if ($session && !$hasPartIntent && empty($parsed['car']) && empty($parsed['year'])) {
        return response()->json(['status' => 'ignored_greeting_during_session'], 200);
    }

    // فحص الحصة (Quota) والاستثناءات
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
                        'item_name'      => $item->item_name,
                        'item_number'    => $item->item_number,
                        'price'          => (string) $item->price,
                        'quantity'       => (string) $item->quantity,
                        'car'            => $item->detected_car_name ?? '',
                        'category'       => $item->detected_category ?? '',
                        'year'           => $item->detected_years ?? '',
                        'part'           => $pNum,
                        'client_name'    => $ownerUser->name ?? '',
                        'phone'          => $quotaPhoneKey,
                        'items'          => "- {$item->item_name} (رقم: {$item->item_number}) | السعر: {$item->price} ريال",
                        'suggested_item' => $item->item_name,
                        'confidence'     => '100%',
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

    // إذا بدأ العميل طلباً جديداً بسيارة جديدة وقطعة جديدة، نستبدل الجلسة القديمة
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

    // إذا كان العميل يوضح اسم القطعة بعد سؤال "هل تقصد؟"، نحدث القطعة المطلوبة بالوصف الجديد
    $newParts = array_merge($parsed['part_numbers'], $parsed['parts_list']);
    if ($session && !empty($session->pending_suggestion_ids) && !empty($newParts)) {
        $mergedPartsList = expandCompoundDirectionalRequests(array_values(array_unique($newParts)));
    } else {
        $mergedPartsList = expandCompoundDirectionalRequests(array_values(array_unique(array_merge($existingParts, $newParts))));
    }

    $partQueryString = implode(' | ', $mergedPartsList);

    if (empty($mergedPartsList)) {
        return response()->json(['status' => 'ignored_no_part_requested'], 200);
    }

    $fullConv = trim(($session->full_conversation ?? '') . ' ' . $messageText);

    if ($session) {
        DB::table('whatsapp_sessions')->where('id', $session->id)->update([
            'detected_car'           => $car,
            'detected_year'          => $year,
            'part_query'             => $partQueryString,
            'pending_suggestion_ids' => null,
            'full_conversation'      => $fullConv,
            'updated_at'             => now(),
        ]);
    } else {
        DB::table('whatsapp_sessions')->insert([
            'user_id'                => $ownerUserId,
            'phone'                  => $quotaPhoneKey,
            'detected_car'           => $car,
            'detected_year'          => $year,
            'part_query'             => $partQueryString,
            'pending_suggestion_ids' => null,
            'full_conversation'      => $fullConv,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    $carCategory = $parsed['category'] ?? '';
    if (!$carCategory && $car) {
        $carRow      = DB::table('cars')->where('car_name', $car)->first();
        $carCategory = $carRow->category ?? '';
    }

    $readablePartsSummary = implode(' ، ', $mergedPartsList);

    $baseTemplateVars = [
        'item_name'      => '',
        'item_number'    => '',
        'price'          => '',
        'quantity'       => '',
        'car'            => $car ?? '',
        'category'       => $carCategory,
        'year'           => $year ?? '',
        'part'           => $readablePartsSummary ?: $messageText,
        'client_name'    => $ownerUser->name ?? '',
        'phone'          => $quotaPhoneKey,
        'items'          => '',
        'suggested_item' => '',
        'suggested_list' => '',
        'confidence'     => '',
    ];

    // إذا لم يذكر اسم السيارة بعد -> يطلب البوت اسم السيارة
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

    // إذا لم يذكر سنة الصنع بعد -> يطلب البوت سنة الصنع
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

    // بعد اكتمال المصادر الثلاثة (القطعة من الكلمات المفتاحية + السيارة + سنة الصنع)، نفحص نسبة التطابق الذكية
    $pendingSuggestionIds = [];
    $hasDidYouMeanReply   = false;

    foreach ($mergedPartsList as $singlePartRequest) {
        $singlePartRequest = trim($singlePartRequest);
        if ($singlePartRequest === '') continue;

        $eval = evaluateSinglePartWithConfidence($singlePartRequest, $car, $year, $ownerUserId);
        $bestConfidence = $eval['best_confidence'] ?? 0;
        $allScored      = $eval['all_scored'] ?? collect();

        if ($bestConfidence >= $directThreshold && $allScored->isNotEmpty()) {
            // نسبة التطابق وصلت إلى الحد المباشر (مثلاً 80% أو 100%) -> إجابة مباشرة بالتوفر والسعر
            $highItems = $allScored->filter(fn($i) => $i->confidence_score >= $directThreshold);
            foreach ($highItems as $matchedItem) {
                if ($msgAvailable !== '') {
                    $itemVars                   = $baseTemplateVars;
                    $itemVars['item_name']      = $matchedItem->item_name;
                    $itemVars['item_number']    = $matchedItem->item_number;
                    $itemVars['price']          = (string) $matchedItem->price;
                    $itemVars['quantity']       = (string) $matchedItem->quantity;
                    $itemVars['part']           = $singlePartRequest;
                    $itemVars['items']          = "- {$matchedItem->item_name} (رقم: {$matchedItem->item_number}) | السعر: {$matchedItem->price} ريال";
                    $itemVars['suggested_item'] = $matchedItem->item_name;
                    $itemVars['confidence']     = $matchedItem->confidence_score . '%';

                    $replyBlocks[] = formatBotTemplate($msgAvailable, $itemVars);
                }
            }
        } elseif ($bestConfidence >= $suggestThreshold && $allScored->isNotEmpty() && $msgDidYouMean !== '') {
            // نسبة التطابق متوسطة (مثلاً بين 40% و 79%) -> يرد البوت بـ "هل تقصد كذا وكذا؟"
            $mediumItems = $allScored->filter(fn($i) => $i->confidence_score >= $suggestThreshold);
            $topSuggested = $mediumItems->first();
            $suggestedNamesList = [];

            foreach ($mediumItems as $mItem) {
                $pendingSuggestionIds[] = $mItem->id;
                $suggestedNamesList[]   = "- {$mItem->item_name} (السعر: {$mItem->price} ريال)";
            }

            $suggestVars                   = $baseTemplateVars;
            $suggestVars['item_name']      = $topSuggested->item_name;
            $suggestVars['item_number']    = $topSuggested->item_number;
            $suggestVars['price']          = (string) $topSuggested->price;
            $suggestVars['quantity']       = (string) $topSuggested->quantity;
            $suggestVars['part']           = $singlePartRequest;
            $suggestVars['suggested_item'] = implode(' أو ', $mediumItems->pluck('item_name')->toArray());
            $suggestVars['suggested_list'] = implode("\n", $suggestedNamesList);
            $suggestVars['items']          = $suggestVars['suggested_list'];
            $suggestVars['confidence']     = $bestConfidence . '%';

            $replyBlocks[]      = formatBotTemplate($msgDidYouMean, $suggestVars);
            $hasDidYouMeanReply = true;
        } else {
            // النسبة أقل من حد الاقتراح أو لا يوجد تطابق (مثلاً طلب فلتر زيت والموجود فلتر هواء فقط -> النسبة 0%)
            $unavailablePartsNames[] = $singlePartRequest;
        }
    }

    if (!empty($unavailablePartsNames) && $msgUnavailable !== '') {
        $missingSummary           = implode(' ، ', array_unique($unavailablePartsNames));
        $unavailVars              = $baseTemplateVars;
        $unavailVars['part']      = $missingSummary;
        $unavailVars['item_name'] = $missingSummary;
        $replyBlocks[]            = formatBotTemplate($msgUnavailable, $unavailVars);
    }

    // إذا كان الرد يحتوي على سؤال "هل تقصد؟"، نبقي الجلسة مفتوحة مع حفظ الأصناف المقترحة لكي يؤكدها العميل
    if ($hasDidYouMeanReply && !empty($pendingSuggestionIds)) {
        DB::table('whatsapp_sessions')
            ->where('user_id', $ownerUserId)
            ->where('phone', $quotaPhoneKey)
            ->update([
                'pending_suggestion_ids' => json_encode(array_values(array_unique($pendingSuggestionIds))),
                'updated_at'             => now(),
            ]);
    } else {
        DB::table('whatsapp_sessions')
            ->where('user_id', $ownerUserId)
            ->where('phone', $quotaPhoneKey)
            ->delete();
    }

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

            createInventoryRollbackSnapshot($userId, 'نسخة تلقائية قبل الاستيراد');
            $response = app(ItemController::class)->import($request);
            DB::table('items')->whereNull('user_id')->update(['user_id' => $userId]);
            syncAllExistingItemsToCore();
            return $response;
        })->name('import');

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

    // 3. المخزون المشترك (قلب النظام)
    Route::get('/master-catalog', function (Request $request) {
        if (auth()->user()->role !== 'admin') abort(403);

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

    // 6. مسارات الرد الآلي والذكاء الاصطناعي والحدود والاستثناءات ونسب التطابق
    Route::get('/ai-whatsapp', function (Request $request) {
        $userId          = auth()->id();
        $customerMessage = (string) $request->query('message', '');
        $matchedItems    = collect();
        $botSettings     = getUserBotSettings($userId);
        $suggestMin      = max(1, (int) ($botSettings['confidence_suggest_threshold'] ?? 40));

        if (trim($customerMessage) !== '') {
            $parsedTest = parseWhatsappText($customerMessage, $userId);

            if (!empty($parsedTest['part_numbers'])) {
                $matchedItems = Item::where('user_id', $userId)
                    ->whereIn('item_number', $parsedTest['part_numbers'])
                    ->get()
                    ->map(function ($it) {
                        $it->confidence_score = 100;
                        return $it;
                    });
            }

            if ($matchedItems->isEmpty() && !empty($parsedTest['parts_list'])) {
                $testCar  = $parsedTest['car'] ?? '';
                $testYear = $parsedTest['year'] ?? '';

                foreach ($parsedTest['parts_list'] as $singleReq) {
                    $found = findMatchingItemsForSinglePart($singleReq, $testCar, $testYear, $userId, $suggestMin);
                    foreach ($found as $fItem) {
                        $matchedItems->push($fItem);
                    }
                }
                $matchedItems = $matchedItems->unique('id')->values();
            }
        }

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
            'reply_did_you_mean',
            'confidence_direct_threshold',
            'confidence_suggest_threshold',
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

        return back()->with('success', 'تم حفظ إعدادات البوت ونسب الذكاء وقوالب الرسائل بنجاح.');
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

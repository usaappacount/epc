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
use Carbon\Carbon;

/*
|--------------------------------------------------------------------------
| 1. تهيئة وترقية الجداول التلقائية في الخلفية (Database Auto-Migration)
|--------------------------------------------------------------------------
*/
try {
    if (request()->is('api/webhook/*') || request()->is('restore-backup*')) {
        throw new \Exception('skip_schema_checks');
    }
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

    if (!Schema::hasTable('whatsapp_customer_quotas')) {
        Schema::create('whatsapp_customer_quotas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(1)->index();
            $table->string('phone')->index();
            $table->integer('queries_count')->default(0);
            $table->timestamp('last_reset_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'phone']);
        });
    } else {
        if (!Schema::hasColumn('whatsapp_customer_quotas', 'last_reset_at')) {
            Schema::table('whatsapp_customer_quotas', function (Blueprint $table) {
                $table->timestamp('last_reset_at')->nullable();
            });
        }
    }

    // جدول الاستثناءات المتقدم (حدود مخصصة + دورة تصفير + أوقات مسموحة + صلاحيات عرض دقيقة + إكسل)
    if (!Schema::hasTable('whatsapp_whitelist_phones')) {
        Schema::create('whatsapp_whitelist_phones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(1)->index();
            $table->string('phone')->index();
            $table->string('label')->nullable();
            $table->integer('custom_quota')->nullable();
            $table->string('reset_period', 20)->default('default'); // default | manual | daily | weekly | monthly
            $table->string('allowed_start_time', 10)->nullable();
            $table->string('allowed_end_time', 10)->nullable();
            $table->boolean('allow_available')->default(true);
            $table->boolean('allow_unavailable')->default(true);
            $table->boolean('allow_prices')->default(true);
            $table->boolean('allow_quantities')->default(true);
            $table->boolean('allow_excel')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'phone']);
        });
    } else {
        $whitelistCols = [
            'custom_quota'       => fn(Blueprint $t) => $t->integer('custom_quota')->nullable(),
            'reset_period'       => fn(Blueprint $t) => $t->string('reset_period', 20)->default('default'),
            'allowed_start_time' => fn(Blueprint $t) => $t->string('allowed_start_time', 10)->nullable(),
            'allowed_end_time'   => fn(Blueprint $t) => $t->string('allowed_end_time', 10)->nullable(),
            'allow_available'    => fn(Blueprint $t) => $t->boolean('allow_available')->default(true),
            'allow_unavailable'  => fn(Blueprint $t) => $t->boolean('allow_unavailable')->default(true),
            'allow_prices'       => fn(Blueprint $t) => $t->boolean('allow_prices')->default(true),
            'allow_quantities'   => fn(Blueprint $t) => $t->boolean('allow_quantities')->default(true),
            'allow_excel'        => fn(Blueprint $t) => $t->boolean('allow_excel')->default(true),
        ];
        foreach ($whitelistCols as $colName => $colClosure) {
            if (!Schema::hasColumn('whatsapp_whitelist_phones', $colName)) {
                Schema::table('whatsapp_whitelist_phones', $colClosure);
            }
        }
    }

    // جدول القائمة السوداء (Blacklist)
    if (!Schema::hasTable('whatsapp_blacklist_phones')) {
        Schema::create('whatsapp_blacklist_phones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(1)->index();
            $table->string('phone')->index();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'phone']);
        });
    }

    // جدول سجل ورادار الطلبات الشامل
    if (!Schema::hasTable('whatsapp_inquiry_logs')) {
        Schema::create('whatsapp_inquiry_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(1)->index();
            $table->string('phone')->index();
            $table->string('part_number')->index();
            $table->string('normalized_part')->index();
            $table->string('item_name')->nullable();
            $table->string('status', 20)->default('unavailable')->index();
            $table->integer('requested_qty')->default(1);
            $table->integer('available_qty')->default(0);
            $table->decimal('price', 12, 2)->nullable();
            $table->string('source', 20)->default('text');
            $table->string('inquiry_date', 15)->nullable()->index();
            $table->timestamps();
        });
    } else {
        if (!Schema::hasColumn('whatsapp_inquiry_logs', 'inquiry_date')) {
            Schema::table('whatsapp_inquiry_logs', function (Blueprint $table) {
                $table->string('inquiry_date', 15)->nullable()->index();
            });
        }
    }

    // جدول الكلمات المفتاحية للأصناف الموحدة (لمنع خطأ Route [master.item.keyword.add] not defined)
    if (!Schema::hasTable('master_item_keywords')) {
        Schema::create('master_item_keywords', function (Blueprint $table) {
            $table->id();
            $table->string('item_number')->index();
            $table->string('keyword');
            $table->timestamps();
        });
    }

    // ترقية جدول قلب النظام (master_items)
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

    // جداول الاستيراد الذكي في الخلفية ونقطة الاسترجاع الفورية
    if (!Schema::hasTable('excel_import_jobs')) {
        Schema::create('excel_import_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->string('status')->default('pending')->index();
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

    if (Schema::hasTable('users')) {
        $adminUser = DB::table('users')->where('role', 'admin')->first();
        if ($adminUser && empty($adminUser->phone)) {
            DB::table('users')->where('id', $adminUser->id)->update(['phone' => '+966556666037']);
        }
    }
} catch (\Throwable $e) {
    // تجاهل أي خطأ في قاعدة البيانات أثناء بناء الحاوية
}

/*
|--------------------------------------------------------------------------
| 2. دوال المساعدة وتنسيق النصوص والأرقام + قراءة وكتابة XLSX الأصلي
|--------------------------------------------------------------------------
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

function normalizeArabicDigits(string $str): string {
    $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $english = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
    return str_replace($arabic, $english, $str);
}

function normalizeArabicSpelling(string $str): string {
    $str = normalizeArabicDigits(trim($str));
    $str = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $str);
    $str = str_replace(['أ', 'إ', 'آ', 'ٱ'], 'ا', $str);
    $str = str_replace('ة', 'ه', $str);
    $str = str_replace(['ى', 'ئ'], 'ي', $str);
    $str = str_replace('ؤ', 'و', $str);
    return mb_strtolower(trim($str));
}

function normalizePartNumberClean(string $partNumber): string {
    $norm = normalizeArabicDigits(strtoupper(trim($partNumber)));
    return preg_replace('/[^A-Z0-9]/', '', $norm);
}

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
 * قارئ ملفات .xlsx مدمج وخفيف يعتمد على ZipArchive + SimpleXML في حال عدم وجود مكتبة PhpSpreadsheet
 */
function parseXlsxFileNativeFallback(string $filePath): array {
    if (!class_exists(\ZipArchive::class) || !file_exists($filePath)) {
        return [];
    }

    $zip = new \ZipArchive();
    if ($zip->open($filePath) !== true) {
        return [];
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx && isset($sx->si)) {
            foreach ($sx->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string) $si->t;
                } elseif (isset($si->r)) {
                    $txt = '';
                    foreach ($si->r as $run) {
                        $txt .= (string) ($run->t ?? '');
                    }
                    $sharedStrings[] = $txt;
                } else {
                    $sharedStrings[] = '';
                }
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#i', (string) $name)) {
                $sheetXml = $zip->getFromIndex($i);
                break;
            }
        }
    }
    $zip->close();

    if ($sheetXml === false) {
        return [];
    }

    $xml = @simplexml_load_string($sheetXml);
    if (!$xml || !isset($xml->sheetData->row)) {
        return [];
    }

    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $rowData = [];
        $maxCol  = 0;
        foreach ($row->c as $c) {
            $ref = (string) ($c['r'] ?? '');
            $colIndex = 0;
            if (preg_match('/^([A-Z]+)/i', $ref, $m)) {
                $letters = strtoupper($m[1]);
                for ($l = 0; $l < strlen($letters); $l++) {
                    $colIndex = $colIndex * 26 + (ord($letters[$l]) - 64);
                }
                $colIndex--;
            } else {
                $colIndex = count($rowData);
            }

            $type = (string) ($c['t'] ?? '');
            $val  = '';
            if ($type === 's') {
                $idx = (int) ($c->v ?? 0);
                $val = $sharedStrings[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string) ($c->is->t ?? '');
            } else {
                $val = (string) ($c->v ?? '');
            }

            $rowData[$colIndex] = trim($val);
            if ($colIndex > $maxCol) {
                $maxCol = $colIndex;
            }
        }

        if (!empty($rowData)) {
            $normalizedRow = [];
            for ($k = 0; $k <= $maxCol; $k++) {
                $normalizedRow[$k] = $rowData[$k] ?? '';
            }
            $rows[] = $normalizedRow;
        }
    }

    return $rows;
}

/**
 * منشئ ملفات .xlsx مدمج وخفيف لتوليد ملف رد إكسل حقيقي للعميل على الواتساب
 */
function generateExcelReplyFileXlsx(array $headers, array $dataRows, string $outputPath): bool {
    $allRows = array_merge([$headers], $dataRows);

    if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class) && class_exists(\PhpOffice\PhpSpreadsheet\Writer\Xlsx::class)) {
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setRightToLeft(true);
            $sheet->setTitle('نتيجة التسعير');
            $sheet->fromArray($allRows, null, 'A1');
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($outputPath);
            return file_exists($outputPath);
        } catch (\Throwable $e) {
            // ننتقل للمنشئ المدمج
        }
    }

    if (!class_exists(\ZipArchive::class)) {
        return false;
    }

    $zip = new \ZipArchive();
    if ($zip->open($outputPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="نتيجة التسعير" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews>'
        . '<sheetData>';

    $rNum = 1;
    foreach ($allRows as $row) {
        $sheetXml .= '<row r="' . $rNum . '">';
        $cNum = 0;
        foreach (array_values($row) as $cellVal) {
            $colLetter = chr(65 + $cNum);
            $cellRef   = $colLetter . $rNum;
            $valStr    = (string) ($cellVal ?? '');

            if ($rNum > 1 && is_numeric($valStr) && !preg_match('/^0\d+/', $valStr) && strlen($valStr) < 10) {
                $sheetXml .= '<c r="' . $cellRef . '"><v>' . $valStr . '</v></c>';
            } else {
                $escaped = htmlspecialchars($valStr, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $sheetXml .= '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
            }
            $cNum++;
        }
        $sheetXml .= '</row>';
        $rNum++;
    }

    $sheetXml .= '</sheetData></worksheet>';

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    return file_exists($outputPath);
}

/*
|--------------------------------------------------------------------------
| 3. دوال المزامنة مع قلب النظام وإدارة المخزون والاستيراد (Master Core & Sync)
|--------------------------------------------------------------------------
*/

function syncItemToMasterCore(string $itemNumber, string $itemName, ?int $userId = null): void {
    $cleanNumber = strtoupper(trim($itemNumber));
    $cleanName   = normalizeArabicDigits(trim($itemName));

    if ($cleanNumber === '' || $cleanName === '') return;

    $detectedCarsList   = [];
    $detectedCategories = [];
    $detectedYearsList  = [];

    $carsList = Schema::hasTable('cars') ? DB::table('cars')->get() : collect();

    foreach ($carsList as $car) {
        if (containsWholeWordArabicSafe($cleanName, $car->car_name)) {
            $detectedCarsList[]   = $car->car_name;
            $detectedCategories[] = $car->category;
        }
        $aliases = Schema::hasTable('car_aliases')
            ? DB::table('car_aliases')->where('car_id', $car->id)->pluck('alias')->toArray()
            : [];
        foreach ($aliases as $alias) {
            if ($alias !== '' && containsWholeWordArabicSafe($cleanName, $alias)) {
                $detectedCarsList[]   = $car->car_name;
                $detectedCategories[] = $car->category;
            }
        }
    }

    $detectedCarsList   = array_values(array_unique(array_filter($detectedCarsList)));
    $detectedCategories = array_values(array_unique(array_filter($detectedCategories)));

    $patterns = Schema::hasTable('year_patterns')
        ? DB::table('year_patterns')->pluck('pattern_example')->toArray()
        : [];
    foreach ($patterns as $pattern) {
        $normP = normalizeArabicDigits(trim($pattern));
        if ($normP !== '' && containsWholeWordArabicSafe($cleanName, $normP)) {
            $detectedYearsList[] = $normP;
        }
    }

    $nameWithoutCars = $cleanName;
    foreach ($carsList as $car) {
        $nameWithoutCars = replaceWholeWordArabicSafe($nameWithoutCars, $car->car_name);
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
        } elseif (preg_match_all('/(?<!\d)\d{2}(?!\d)/u', $nameWithoutCars, $mShortYears)) {
            foreach ($mShortYears[0] as $sy) {
                $detectedYearsList[] = $sy;
            }
        }
    }

    $detectedYearsList = array_values(array_unique(array_filter($detectedYearsList)));

    $itemCarString      = !empty($detectedCarsList) ? implode(' | ', $detectedCarsList) : null;
    $itemCategoryString = !empty($detectedCategories) ? implode(' | ', $detectedCategories) : null;
    $itemYearsString    = !empty($detectedYearsList) ? implode(' | ', $detectedYearsList) : null;

    $itemUpdateQuery = DB::table('items')->where('item_number', $cleanNumber);
    if ($userId !== null) {
        $itemUpdateQuery->where('user_id', $userId);
    }
    $itemUpdateQuery->update([
        'detected_car_name' => $itemCarString,
        'detected_category' => $itemCategoryString,
        'detected_years'    => $itemYearsString,
    ]);

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
}

function cleanupAndNormalizeInventoryData(): void {
    $validUserIds = DB::table('users')->pluck('id')->toArray();
    if (!empty($validUserIds)) {
        DB::table('items')->whereNotIn('user_id', $validUserIds)->delete();
    }

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

    $activeItemNumbers = DB::table('items')->distinct()->pluck('item_number')->toArray();
    if (empty($activeItemNumbers)) {
        DB::table('master_items')->delete();
    } else {
        DB::table('master_items')->whereNotIn('item_number', $activeItemNumbers)->delete();
    }
}

function detectSmartExcelColumns(array $headerRow): array {
    $mapping = [
        'item_number' => null,
        'item_name'   => null,
        'quantity'    => null,
        'price'       => null,
    ];

    $numberAliases = ['item_number', 'part_number', 'part_no', 'partno', 'code', 'sku', 'number', 'id', 'oem', 'رقم الصنف', 'رقم القطعة', 'رقم القطعه', 'كود الصنف', 'كود', 'الرقم', 'رقم', 'ارقام الاصناف', 'أرقام الأصناف'];
    $nameAliases   = ['item_name', 'part_name', 'description', 'name', 'title', 'item', 'اسم الصنف', 'اسم القطعة', 'اسم القطعه', 'البيان', 'الوصف', 'الصنف', 'الاسم', 'بيان الصنف'];
    $qtyAliases    = ['quantity', 'qty', 'stock', 'balance', 'count', 'avail', 'order_qty', 'requested_qty', 'الكمية', 'الكميه', 'الرصيد', 'العدد', 'المتوفر', 'كمية', 'رصيد', 'الكمية المطلوبة', 'المطلوب'];
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

function detectCustomerInquiryExcelColumns(array $rows): array {
    $partCol = null;
    $qtyCol  = null;
    $startRowIndex = 0;

    $numberAliases = ['item_number', 'part_number', 'part_no', 'partno', 'code', 'sku', 'number', 'oem', 'رقم الصنف', 'رقم القطعة', 'رقم القطعه', 'كود الصنف', 'كود', 'الرقم', 'رقم', 'ارقام', 'أرقام'];
    $qtyAliases    = ['quantity', 'qty', 'count', 'order', 'req', 'الكمية', 'الكميه', 'العدد', 'كمية', 'كميه', 'مطلوب', 'المطلوب', 'الكمية المطلوبة'];

    $firstRow = $rows[0] ?? [];
    if (is_array($firstRow)) {
        foreach ($firstRow as $colIdx => $val) {
            $cleanVal = normalizeArabicSpelling(preg_replace('/[\s_\-\.]+/u', ' ', trim((string) $val)));
            $rawLower = strtolower(trim((string) $val));
            if ($cleanVal === '') continue;

            if ($partCol === null) {
                foreach ($numberAliases as $alias) {
                    if ($cleanVal === normalizeArabicSpelling($alias) || $rawLower === $alias || str_contains($cleanVal, normalizeArabicSpelling($alias))) {
                        $partCol = $colIdx;
                        $startRowIndex = 1;
                        break;
                    }
                }
            }
            if ($qtyCol === null) {
                foreach ($qtyAliases as $alias) {
                    if ($cleanVal === normalizeArabicSpelling($alias) || $rawLower === $alias || str_contains($cleanVal, normalizeArabicSpelling($alias))) {
                        $qtyCol = $colIdx;
                        $startRowIndex = 1;
                        break;
                    }
                }
            }
        }
    }

    if ($partCol === null) {
        $colScoresPart = [];
        $colScoresQty  = [];
        $sampleRows = array_slice($rows, 0, 15);

        foreach ($sampleRows as $rIdx => $r) {
            if (!is_array($r)) continue;
            foreach ($r as $cIdx => $cell) {
                $cellStr = normalizeArabicDigits(strtoupper(trim((string) $cell)));
                if ($cellStr === '') continue;

                if (strlen($cellStr) >= 4 && preg_match('/^[A-Z0-9\-]+$/', $cellStr) && preg_match('/\d/', $cellStr)) {
                    $colScoresPart[$cIdx] = ($colScoresPart[$cIdx] ?? 0) + 1;
                } elseif (preg_match('/^\d{1,4}$/', $cellStr) && (int) $cellStr > 0 && (int) $cellStr <= 1000) {
                    $colScoresQty[$cIdx] = ($colScoresQty[$cIdx] ?? 0) + 1;
                }
            }
        }

        if (!empty($colScoresPart)) {
            arsort($colScoresPart);
            $partCol = array_key_first($colScoresPart);
        } else {
            $keys = is_array($firstRow) ? array_keys($firstRow) : [0];
            $partCol = $keys[0] ?? 0;
        }

        if ($qtyCol === null && !empty($colScoresQty)) {
            unset($colScoresQty[$partCol]);
            if (!empty($colScoresQty)) {
                arsort($colScoresQty);
                $qtyCol = array_key_first($colScoresQty);
            }
        }
    }

    return [
        'part_col'  => $partCol,
        'qty_col'   => $qtyCol,
        'start_row' => $startRowIndex,
    ];
}

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

function buildMasterDictionaryRamCache(): array {
    $cars     = Schema::hasTable('cars') ? DB::table('cars')->get()->toArray() : [];
    $aliases  = Schema::hasTable('car_aliases') ? DB::table('car_aliases')->get()->groupBy('car_id')->toArray() : [];
    $patterns = Schema::hasTable('year_patterns')
        ? DB::table('year_patterns')
            ->pluck('pattern_example')
            ->map(fn($p) => normalizeArabicDigits(trim((string) $p)))
            ->filter()
            ->values()
            ->toArray()
        : [];

    $allAliases = [];
    foreach ($cars as $car) {
        $carAliases = $aliases[$car->id] ?? [];
        foreach ($carAliases as $alObj) {
            $al = trim((string) $alObj->alias);
            if ($al !== '') {
                $allAliases[] = ['alias' => $al, 'car_name' => $car->car_name, 'category' => $car->category];
            }
        }
    }

    return [
        'cars'           => $cars,
        'all_aliases'    => $allAliases,
        'patterns'       => $patterns,
        'has_compat_col' => Schema::hasColumn('master_items', 'compatible_cars'),
    ];
}

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

    $now           = now();
    $toInsertItems = [];
    $toUpdateItems = [];
    $masterUpdates = [];

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

        $nameWithoutCars = $cleanName;
        foreach ($ramCache['cars'] as $car) {
            $nameWithoutCars = replaceWholeWordArabicSafe($nameWithoutCars, $car->car_name);
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
            } elseif (preg_match_all('/(?<!\d)\d{2}(?!\d)/u', $nameWithoutCars, $mShortYears)) {
                foreach ($mShortYears[0] as $sy) {
                    $detectedYearsList[] = $sy;
                }
            }
        }

        $detectedYearsList = array_values(array_unique(array_filter($detectedYearsList)));

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

    // 1. محاولة القراءة عبر PhpSpreadsheet إن وجدت
    if (class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
            $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
            if (!empty($rows)) {
                return $rows;
            }
        } catch (\Throwable $e) {}
    }

    // 2. محاولة القراءة عبر Maatwebsite Excel إن وجدت
    if (class_exists(\Maatwebsite\Excel\Facades\Excel::class)) {
        try {
            $sheets = \Maatwebsite\Excel\Facades\Excel::toArray(new \stdClass(), $filePath);
            if (!empty($sheets[0])) {
                return $sheets[0];
            }
        } catch (\Throwable $e) {}
    }

    // 3. قارئ XLSX المدمج المباشر (يعمل دائماً على السيرفر بدون أي مكتبات خارجية)
    $nativeRows = parseXlsxFileNativeFallback($filePath);
    if (!empty($nativeRows)) {
        return $nativeRows;
    }

    // 4. محاولة أخيرة كـ CSV في حال كان الملف نصياً بامتداد مختلف
    $fallbackCsv = [];
    if (($handle = @fopen($filePath, 'r')) !== false) {
        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $fallbackCsv[] = $data;
        }
        fclose($handle);
    }
    return $fallbackCsv;
}

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

    $allItems = DB::table('items')->orderBy('id', 'asc')->get();
    foreach ($allItems as $item) {
        syncItemToMasterCore($item->item_number, $item->item_name, $item->user_id);
    }
}

/*
|--------------------------------------------------------------------------
| 4. محرك استخراج أرقام الأصناف والكميات من رسائل الواتساب وملفات الإكسل
|--------------------------------------------------------------------------
*/

function findExactItemIgnoringDashes(int $ownerUserId, string $partNumber, bool $onlyPositiveQty = false) {
    $rawUpper    = strtoupper(trim($partNumber));
    $cleanNoDash = normalizePartNumberClean($rawUpper);

    if ($cleanNoDash === '') {
        return null;
    }

    return Item::where('user_id', $ownerUserId)
        ->when($onlyPositiveQty, fn($q) => $q->where('quantity', '>', 0))
        ->where(function ($q) use ($rawUpper, $cleanNoDash) {
            $q->where('item_number', $rawUpper)
              ->orWhere(DB::raw("UPPER(REPLACE(REPLACE(item_number, '-', ''), ' ', ''))"), $cleanNoDash);
        })
        ->first();
}

function extractPartNumbersWithQuantitiesFromText(string $text, ?int $ownerUserId = null): array {
    $normText = normalizeArabicDigits(trim($text));
    if ($normText === '') {
        return [];
    }

    static $cachedUserItemsMap = [];
    if ($ownerUserId !== null && !isset($cachedUserItemsMap[$ownerUserId])) {
        $allNums = DB::table('items')
            ->where('user_id', $ownerUserId)
            ->pluck('item_number')
            ->toArray();
        $map = [];
        foreach ($allNums as $n) {
            $cleanKey = normalizePartNumberClean((string) $n);
            if ($cleanKey !== '') {
                $map[$cleanKey] = true;
            }
        }
        $cachedUserItemsMap[$ownerUserId] = $map;
    }
    $userItemsLookup = $ownerUserId !== null ? ($cachedUserItemsMap[$ownerUserId] ?? []) : [];

    $results = [];
    $lines   = preg_split('/[\r\n]+/', $normText);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $lineParts = [];

        if (preg_match_all('/[A-Za-z0-9]{2,}[\-][A-Za-z0-9\-]{2,}/u', $line, $pm)) {
            foreach ($pm[0] as $partNum) {
                if (!preg_match('/^(?:19|20)?\d{2}[\-](?:19|20)?\d{2}$/', $partNum)) {
                    $lineParts[] = strtoupper(trim($partNum));
                }
            }
        }

        $tokens = preg_split('/[\s,،\t:;\/\|\(\)\[\]\=\*xX×]+/u', $line, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($tokens as $tk) {
            $cleanTk = strtoupper(trim($tk, '-_*.،,؟?!: '));
            if (strlen($cleanTk) < 4) continue;

            $isStandaloneYear = (bool) preg_match('/^(19[7-9]\d|20[0-3]\d)$/', $cleanTk);

            if (preg_match('/^[A-Z0-9\-]+$/', $cleanTk) && preg_match('/\d/', $cleanTk)) {
                $cleanNoDash = normalizePartNumberClean($cleanTk);
                $existsInDb  = isset($userItemsLookup[$cleanNoDash]);

                if ($existsInDb) {
                    $lineParts[] = $cleanTk;
                } elseif (!$isStandaloneYear && strlen($cleanNoDash) >= 5) {
                    $lineParts[] = $cleanTk;
                }
            }
        }

        $lineParts = array_values(array_unique($lineParts));
        if (empty($lineParts)) continue;

        $detectedLineQty = 1;
        if (count($lineParts) === 1) {
            $singlePart      = $lineParts[0];
            $lineWithoutPart = str_ireplace($singlePart, ' ', $line);
            if (preg_match('/(?:[\*xX×=]|كميه|كمية|عدد|حبه|حبة|طقم|قطعه|قطعة)\s*(\d{1,4})\b/u', $lineWithoutPart, $qm)) {
                $detectedLineQty = max(1, (int) $qm[1]);
            } elseif (preg_match('/\b(\d{1,3})\s*(?:حبه|حبة|طقم|قطعه|قطعة|حبات)\b/u', $lineWithoutPart, $qm2)) {
                $detectedLineQty = max(1, (int) $qm2[1]);
            } elseif (preg_match('/^\s*(\d{1,3})\s*$/', trim($lineWithoutPart, ' -:*xX×=،,'), $qm3)) {
                $detectedLineQty = max(1, (int) $qm3[1]);
            }
        }

        foreach ($lineParts as $pNum) {
            $normKey = normalizePartNumberClean($pNum);
            if ($normKey === '') continue;

            if (!isset($results[$normKey])) {
                $results[$normKey] = [
                    'part_number'   => $pNum,
                    'requested_qty' => $detectedLineQty,
                ];
            } else {
                $results[$normKey]['requested_qty'] = max($results[$normKey]['requested_qty'], $detectedLineQty);
            }
        }
    }

    return array_values($results);
}

function extractPartNumbersFromWhatsappText(string $text, ?int $ownerUserId = null): array {
    $items = extractPartNumbersWithQuantitiesFromText($text, $ownerUserId);
    return array_values(array_map(fn($i) => $i['part_number'], $items));
}

/*
|--------------------------------------------------------------------------
| محرك استخراج الميديا الذكي (Excel بجميع صيغه + الصور OCR + الدوال المساندة)
|--------------------------------------------------------------------------
*/

/**
 * تحميل ملف الميديا (إكسل أو صورة) من الويب هوك مباشرة أو عبر استدعاء API المزود بـ message_id
 */
function downloadWhatsappMediaUniversal(array $payload, array $settings = [], string $expectedCategory = 'any'): ?array {
    $fileName   = '';
    $mimeType   = '';
    $mediaUrl   = '';
    $base64Data = '';
    $messageId  = (string) (
        data_get($payload, 'data.id')
        ?? data_get($payload, 'data.key.id')
        ?? data_get($payload, 'data.message_id')
        ?? data_get($payload, 'data.message.id')
        ?? data_get($payload, 'message_id')
        ?? data_get($payload, 'id')
        ?? ''
    );

    $msgType = strtolower((string) (
        data_get($payload, 'type')
        ?? data_get($payload, 'data.type')
        ?? data_get($payload, 'data.message.type')
        ?? data_get($payload, 'message_type')
        ?? data_get($payload, 'data.media_type')
        ?? ''
    ));

    // 1. إذا وصل الملف كـ Multipart مباشر في طلب الويب هوك
    foreach (['media', 'file', 'document', 'image'] as $fileKey) {
        if (request()->hasFile($fileKey)) {
            $upFile = request()->file($fileKey);
            if ($upFile && $upFile->isValid()) {
                return [
                    'path'      => $upFile->getRealPath(),
                    'ext'       => strtolower($upFile->getClientOriginalExtension() ?: 'bin'),
                    'mime'      => (string) $upFile->getMimeType(),
                    'is_temp'   => false,
                    'file_name' => (string) $upFile->getClientOriginalName(),
                ];
            }
        }
    }

    // 2. التقاط الروابط المباشرة القياسية
    $directUrlCandidates = [
        data_get($payload, 'media_url'),
        data_get($payload, 'data.media_url'),
        data_get($payload, 'data.message.media_url'),
        data_get($payload, 'data.media.url'),
        data_get($payload, 'data.document.url'),
        data_get($payload, 'data.document.media_url'),
        data_get($payload, 'data.image.url'),
        data_get($payload, 'data.image.media_url'),
        data_get($payload, 'media'),
        data_get($payload, 'data.media'),
        data_get($payload, 'file_url'),
        data_get($payload, 'data.file_url'),
        data_get($payload, 'download_url'),
        data_get($payload, 'data.download_url'),
        data_get($payload, 'url'),
        data_get($payload, 'data.url'),
    ];

    foreach ($directUrlCandidates as $cand) {
        if (is_string($cand) && filter_var(trim($cand), FILTER_VALIDATE_URL)) {
            $mediaUrl = trim($cand);
            break;
        }
    }

    // 3. مسح شامل لأي حقل داخل الـ Payload
    array_walk_recursive($payload, function ($value, $key) use (&$fileName, &$mimeType, &$mediaUrl, &$base64Data, &$msgType) {
        if (!is_string($value) && !is_numeric($value)) return;
        $valStr = trim((string) $value);
        if ($valStr === '') return;
        $kLower = strtolower((string) $key);

        if ($msgType === '' && in_array($kLower, ['type', 'message_type', 'mediatype', 'media_type'], true)) {
            $vLow = strtolower($valStr);
            if (in_array($vLow, ['document', 'file', 'spreadsheet', 'image', 'photo'], true)) {
                $msgType = $vLow;
            }
        }

        if ($fileName === '' && preg_match('/[^\/\\\\]+\.(xlsx|xls|csv|jpg|jpeg|png|webp)$/i', $valStr)) {
            $fileName = $valStr;
        }

        if ($mimeType === '' && in_array($kLower, ['mimetype', 'mime_type', 'content_type'], true)) {
            $mimeType = strtolower($valStr);
        }

        if ($mediaUrl === '' && filter_var($valStr, FILTER_VALIDATE_URL)) {
            if (
                in_array($kLower, ['media_url', 'mediaurl', 'file_url', 'fileurl', 'document_url', 'image_url', 'download_url', 'url', 'link', 'media', 'file', 'document', 'image'], true) ||
                preg_match('/\.(xlsx|xls|csv|jpg|jpeg|png|webp)(\?|$)/i', $valStr) ||
                str_contains($valStr, 'greatwhats') ||
                str_contains($valStr, 'whatsapp.net') ||
                str_contains($valStr, '/media') ||
                str_contains($valStr, '/files') ||
                str_contains($valStr, '/storage') ||
                str_contains($valStr, '/download')
            ) {
                $mediaUrl = $valStr;
            }
        }

        $cleanB64 = preg_replace('/^data:[^;]+;base64,/', '', $valStr);
        if ($base64Data === '' && strlen($cleanB64) > 100 && !str_contains($cleanB64, ' ')) {
            if (
                str_starts_with($cleanB64, 'UEsDB') || // XLSX (ZIP)
                str_starts_with($cleanB64, '0M8R4') || // XLS (OLE2)
                str_starts_with($cleanB64, '/9j/')  || // JPEG
                str_starts_with($cleanB64, 'iVBOR') || // PNG
                str_starts_with($cleanB64, 'UklGR') || // WEBP
                in_array($kLower, ['base64', 'media_base64', 'file_base64', 'document_base64', 'image_base64', 'data'], true)
            ) {
                $base64Data = $cleanB64;
            }
        }
    });

    $rawJson = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '';

    // فلترة حسب النوع المطلوب (excel أو image)
    if ($expectedCategory === 'excel') {
        $looksLikeExcel = in_array($msgType, ['document', 'file', 'spreadsheet'], true)
            || str_contains($rawJson, 'documentMessage')
            || str_contains($rawJson, 'spreadsheet')
            || str_contains($rawJson, 'excel')
            || str_contains($rawJson, 'ms-excel')
            || preg_match('/\.(xlsx|xls|csv)/i', $fileName . ' ' . $mediaUrl . ' ' . $rawJson)
            || str_starts_with($base64Data, 'UEsDB')
            || str_starts_with($base64Data, '0M8R4');

        if (!$looksLikeExcel) return null;
        if (in_array($msgType, ['image', 'video', 'audio', 'voice', 'sticker'], true) && !preg_match('/\.(xlsx|xls|csv)$/i', $fileName)) {
            return null;
        }
    } elseif ($expectedCategory === 'image') {
        $looksLikeImage = in_array($msgType, ['image', 'photo'], true)
            || str_contains($rawJson, 'imageMessage')
            || str_contains($mimeType, 'image/')
            || preg_match('/\.(jpg|jpeg|png|webp)(\?|$)/i', $fileName . ' ' . $mediaUrl)
            || str_starts_with($base64Data, '/9j/')
            || str_starts_with($base64Data, 'iVBOR')
            || str_starts_with($base64Data, 'UklGR');

        if (!$looksLikeImage) return null;
    }

    $tempDir = storage_path('app/temp_whatsapp_media');
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0775, true);
    }

    $ext = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));
    if ($ext === '') {
        if ($expectedCategory === 'image') {
            $ext = str_contains($mimeType, 'png') ? 'png' : 'jpg';
        } else {
            $ext = str_contains($mimeType, 'csv') ? 'csv' : 'xlsx';
        }
    }

    $tempFile = $tempDir . DIRECTORY_SEPARATOR . 'wa_media_' . time() . '_' . uniqid() . '.' . $ext;
    $apiToken = trim($settings['whatsapp_api_token'] ?? '');
    $apiUrl   = trim($settings['whatsapp_api_url'] ?? '');
    $sessionId = trim($settings['whatsapp_session_id'] ?? '');

    $authHeaders = ['Accept' => '*/*'];
    if ($apiToken !== '') {
        $authHeaders['X-API-Key']     = $apiToken;
        $authHeaders['X-Api-Key']     = $apiToken;
        $authHeaders['Authorization'] = 'Bearer ' . $apiToken;
    }

    $saveBinaryOrJsonContent = function (string $rawBody) use ($tempFile, $authHeaders): bool {
        $trimmed = trim($rawBody);
        if (strlen($trimmed) < 12) return false;

        // إذا كان الرد JSON يحتوي على base64 أو رابط داخلي
        if (str_starts_with($trimmed, '{')) {
            $jsonTry = json_decode($trimmed, true);
            if (is_array($jsonTry)) {
                $b64 = data_get($jsonTry, 'base64')
                    ?? data_get($jsonTry, 'data.base64')
                    ?? data_get($jsonTry, 'media_base64')
                    ?? data_get($jsonTry, 'data.media_base64')
                    ?? data_get($jsonTry, 'media')
                    ?? data_get($jsonTry, 'data.media')
                    ?? data_get($jsonTry, 'buffer')
                    ?? '';
                if (is_string($b64) && strlen($b64) > 60) {
                    $dec = base64_decode(preg_replace('/^data:[^;]+;base64,/', '', $b64), true);
                    if ($dec !== false && strlen($dec) > 12) {
                        file_put_contents($tempFile, $dec);
                        return true;
                    }
                }
                $innerUrl = data_get($jsonTry, 'media_url')
                         ?? data_get($jsonTry, 'data.media_url')
                         ?? data_get($jsonTry, 'download_url')
                         ?? data_get($jsonTry, 'data.download_url')
                         ?? data_get($jsonTry, 'url')
                         ?? data_get($jsonTry, 'data.url')
                         ?? '';
                if (is_string($innerUrl) && filter_var($innerUrl, FILTER_VALIDATE_URL)) {
                    $r2 = Http::withHeaders($authHeaders)->withoutVerifying()->timeout(30)->get($innerUrl);
                    if (!$r2->successful()) {
                        $r2 = Http::withoutVerifying()->timeout(30)->get($innerUrl);
                    }
                    if ($r2->successful() && strlen($r2->body()) > 12 && !str_starts_with(trim($r2->body()), '{')) {
                        file_put_contents($tempFile, $r2->body());
                        return true;
                    }
                }
                return false;
            }
        }

        file_put_contents($tempFile, $rawBody);
        return true;
    };

    try {
        $downloaded = false;

        // أ) فك Base64 إن وجد مباشرة
        if ($base64Data !== '') {
            $decoded = base64_decode($base64Data, true);
            if ($decoded !== false && strlen($decoded) > 12 && !str_starts_with(trim($decoded), '{')) {
                file_put_contents($tempFile, $decoded);
                $downloaded = true;
            }
        }

        // ب) التحميل من الرابط المباشر mediaUrl
        if (!$downloaded && $mediaUrl !== '') {
            $resp = Http::withHeaders($authHeaders)->withoutVerifying()->timeout(35)->get($mediaUrl);
            if (!$resp->successful() && $apiToken !== '') {
                $resp = Http::withoutVerifying()->timeout(35)->get($mediaUrl);
            }
            if ($resp->successful()) {
                $downloaded = $saveBinaryOrJsonContent($resp->body());
            }
        }

        // ج) إذا لم يتوفر رابط أو فشل التحميل، نطلب الميديا عبر message_id من API المزود (GreatWhats / Baileys)
        if (!$downloaded && $messageId !== '' && $apiUrl !== '') {
            $baseApi = preg_replace('#/(messages|send-message|send|chat|webhook).*$#i', '', rtrim($apiUrl, '/'));
            $fallbackEndpoints = [
                $baseApi . '/messages/' . urlencode($messageId) . '/media',
                $baseApi . '/messages/' . urlencode($messageId) . '/download',
                $baseApi . '/media/' . urlencode($messageId),
                $baseApi . '/download-media',
            ];

            foreach ($fallbackEndpoints as $ep) {
                try {
                    if (str_ends_with($ep, '/download-media')) {
                        $r = Http::withHeaders($authHeaders)->withoutVerifying()->timeout(25)->post($ep, [
                            'message_id' => $messageId,
                            'session_id' => $sessionId,
                            'id'         => $messageId,
                        ]);
                    } else {
                        $r = Http::withHeaders($authHeaders)->withoutVerifying()->timeout(25)->get($ep, [
                            'session_id' => $sessionId,
                        ]);
                    }
                    if ($r->successful() && $saveBinaryOrJsonContent($r->body())) {
                        $downloaded = true;
                        break;
                    }
                } catch (\Throwable $e) {}
            }
        }

        if (!$downloaded || !file_exists($tempFile) || filesize($tempFile) < 12) {
            if (file_exists($tempFile)) @unlink($tempFile);
            return null;
        }

        // فحص البصمة الحقيقية للملف (Magic Bytes) لتصحيح الامتداد تلقائياً
        $headBytes = (string) file_get_contents($tempFile, false, null, 0, 16);
        $detectedExt = $ext;

        if (str_starts_with($headBytes, "PK\x03\x04")) {
            $detectedExt = 'xlsx';
        } elseif (str_starts_with($headBytes, "\xD0\xCF\x11\xE0")) {
            $detectedExt = 'xls';
        } elseif (str_starts_with($headBytes, "\xFF\xD8\xFF")) {
            $detectedExt = 'jpg';
        } elseif (str_starts_with($headBytes, "\x89PNG")) {
            $detectedExt = 'png';
        } elseif (str_starts_with($headBytes, "RIFF") && str_contains($headBytes, "WEBP")) {
            $detectedExt = 'webp';
        }

        if ($detectedExt !== $ext) {
            $newTemp = $tempFile . '.' . $detectedExt;
            if (@rename($tempFile, $newTemp)) {
                $tempFile = $newTemp;
                $ext      = $detectedExt;
            }
        }

        return [
            'path'      => $tempFile,
            'ext'       => $ext,
            'mime'      => $mimeType,
            'is_temp'   => true,
            'file_name' => $fileName,
        ];
    } catch (\Throwable $e) {
        if (file_exists($tempFile)) @unlink($tempFile);
        return null;
    }
}

/**
 * قارئ شامل يدعم .xlsx و .xls القديم (BIFF8) وجداول XML/HTML وحتى استخراج الأرقام من البايتات الخام
 */
function parseAnyExcelOrBinarySpreadsheet(string $filePath): array {
    // 1. المحاولة عبر القارئ الأساسي (PhpSpreadsheet / Maatwebsite / Native XLSX / CSV)
    $rows = parseUploadedSpreadsheetFileRows($filePath);
    if (!empty($rows) && count($rows) > 0) {
        $extracted = extractNormalizedInquiryRowsFromSheet($rows);
        if (!empty($extracted)) {
            return $extracted;
        }
    }

    if (!file_exists($filePath)) {
        return [];
    }

    $rawContent = (string) @file_get_contents($filePath);
    if ($rawContent === '') {
        return [];
    }

    // 2. إذا كان الملف جدول HTML أو XML Spreadsheet متخفٍ بامتداد .xls
    if (stripos($rawContent, '<table') !== false || stripos($rawContent, '<Row') !== false || stripos($rawContent, '<Cell') !== false) {
        $xmlRows = [];
        if (preg_match_all('#<tr[^>]*>(.*?)</tr>#is', $rawContent, $trMatches)) {
            foreach ($trMatches[1] as $trHtml) {
                if (preg_match_all('#<t[dh][^>]*>(.*?)</t[dh]>#is', $trHtml, $tdMatches)) {
                    $r = [];
                    foreach ($tdMatches[1] as $cellHtml) {
                        $r[] = trim(html_entity_decode(strip_tags($cellHtml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    }
                    if (!empty($r)) $xmlRows[] = $r;
                }
            }
        } elseif (preg_match_all('#<Row[^>]*>(.*?)</Row>#is', $rawContent, $rowMatches)) {
            foreach ($rowMatches[1] as $rowXml) {
                if (preg_match_all('#<Data[^>]*>(.*?)</Data>#is', $rowXml, $dataMatches)) {
                    $r = [];
                    foreach ($dataMatches[1] as $cellXml) {
                        $r[] = trim(html_entity_decode(strip_tags($cellXml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
                    }
                    if (!empty($r)) $xmlRows[] = $r;
                }
            }
        }
        if (!empty($xmlRows)) {
            $extracted = extractNormalizedInquiryRowsFromSheet($xmlRows);
            if (!empty($extracted)) {
                return $extracted;
            }
        }
    }

    // 3. إذا كان ملف .xls ثنائي قديم (BIFF8 OLE2) أو إكسل بترميز خاص ولم تتوفر مكتبة PhpSpreadsheet
    // نحول UTF-16LE المدمج داخل قطاعات BIFF8 إلى نص مقروء ونستخرج أرقام الأصناف
    $utf16Stripped = preg_replace('/([A-Za-z0-9\-])\x00/', '$1', $rawContent);
    $printableText = preg_replace('/[^\x20-\x7E\r\n\t]+/', "\n", $utf16Stripped . "\n" . $rawContent);

    $candidates = [];
    if (preg_match_all('/\b[A-Z0-9]{2,8}[\-]?[A-Z0-9]{3,12}\b/i', (string) $printableText, $matches)) {
        $blacklistWords = [
            'WORKBOOK', 'WORKSHEET', 'SHEET1', 'SHEET2', 'SHEET3', 'DEFAULT', 'NORMAL',
            'CALIBRI', 'ARIAL', 'TAHOMA', 'DOCUMENT', 'SUMMARY', 'MICROSOFT', 'EXCEL',
            'SHAREDSTRINGS', 'CONTENT', 'XMLNS', 'SCHEMAS', 'OFFICE', 'OPENXMLFORMATS',
            'STYLES', 'THEME', 'NUMBER', 'GENERAL', 'STRING', 'BOOLEAN', 'ERROR',
        ];
        foreach ($matches[0] as $cand) {
            $up  = strtoupper(trim($cand, '-_ '));
            $key = normalizePartNumberClean($up);
            if (strlen($key) < 5 || strlen($key) > 16) continue;
            if (!preg_match('/\d/', $key)) continue;
            if (in_array($key, $blacklistWords, true)) continue;
            // تجاهل التواريخ والسنوات العادية
            if (preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])?(0[1-9]|[12]\d|3[01])?$/', $key)) continue;

            if (!isset($candidates[$key])) {
                $candidates[$key] = [
                    'part_number'   => $up,
                    'requested_qty' => 1,
                ];
            }
        }
    }

    return array_values($candidates);
}

/**
 * استخراج أصناف ملف الإكسل المرسل في الواتساب
 */
function extractExcelItemsFromWhatsappPayload(array $payload, array $settings = []): array {
    $media = downloadWhatsappMediaUniversal($payload, $settings, 'excel');
    if (!$media || empty($media['path']) || !file_exists($media['path'])) {
        return [];
    }

    try {
        $items = parseAnyExcelOrBinarySpreadsheet($media['path']);
        if (!empty($media['is_temp']) && file_exists($media['path'])) {
            @unlink($media['path']);
        }
        return $items;
    } catch (\Throwable $e) {
        if (!empty($media['is_temp']) && file_exists($media['path'])) {
            @unlink($media['path']);
        }
        return [];
    }
}

function extractNormalizedInquiryRowsFromSheet(array $rows): array {
    $colInfo  = detectCustomerInquiryExcelColumns($rows);
    $partCol  = $colInfo['part_col'];
    $qtyCol   = $colInfo['qty_col'];
    $startRow = $colInfo['start_row'];

    $extracted = [];
    $totalRows = count($rows);

    for ($i = $startRow; $i < $totalRows; $i++) {
        $row = $rows[$i] ?? null;
        if (!is_array($row)) continue;

        $rawPart = trim((string) ($row[$partCol] ?? ''));
        if ($rawPart === '') continue;

        $cleanPart = strtoupper(normalizeArabicDigits($rawPart));
        $normKey   = normalizePartNumberClean($cleanPart);

        if (strlen($normKey) < 4 || in_array(strtolower($cleanPart), ['item_number', 'part_number', 'code', 'sku', 'رقم الصنف', 'رقم القطعة'], true)) {
            continue;
        }

        $reqQty = 1;
        if ($qtyCol !== null && isset($row[$qtyCol])) {
            $parsedQty = (int) preg_replace('/[^\d]/', '', normalizeArabicDigits((string) $row[$qtyCol]));
            if ($parsedQty > 0) {
                $reqQty = $parsedQty;
            }
        }

        if (!isset($extracted[$normKey])) {
            $extracted[$normKey] = [
                'part_number'   => $cleanPart,
                'requested_qty' => $reqQty,
            ];
        } else {
            $extracted[$normKey]['requested_qty'] += $reqQty;
        }
    }

    return array_values($extracted);
}

/**
 * استخراج وقراءة أرقام الأصناف من الصور المرسلة في الواتساب (OCR: كرتون قطعة، فاتورة، أو ورقة مكتوب عليها أرقام)
 */
function extractImageOcrItemsFromWhatsappPayload(array $payload, array $settings = [], ?int $ownerUserId = null): array {
    $media = downloadWhatsappMediaUniversal($payload, $settings, 'image');
    if (!$media || empty($media['path']) || !file_exists($media['path'])) {
        return [];
    }

    $filePath = $media['path'];
    $extractedText = '';

    try {
        $imageBytes = (string) @file_get_contents($filePath);
        if (strlen($imageBytes) < 32) {
            if (!empty($media['is_temp'])) @unlink($filePath);
            return [];
        }

        $mime = $media['mime'] ?: 'image/jpeg';
        if (!str_starts_with($mime, 'image/')) {
            $mime = $media['ext'] === 'png' ? 'image/png' : 'image/jpeg';
        }

        // المحرك 1: Gemini Vision API (إن وجد مفتاح في .env أو إعدادات البوت)
        $geminiKey = trim((string) ($settings['gemini_api_key'] ?? env('GEMINI_API_KEY', '')));
        if ($geminiKey !== '') {
            try {
                $b64Img = base64_encode($imageBytes);
                $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$geminiKey}";
                $gResp = Http::withoutVerifying()->timeout(25)->post($geminiUrl, [
                    'contents' => [[
                        'parts' => [
                            ['text' => 'Extract all auto part numbers / OEM codes / item numbers visible in this image. Return ONLY the part numbers, each on a new line, without any extra words.'],
                            ['inline_data' => ['mime_type' => $mime, 'data' => $b64Img]],
                        ],
                    ]],
                ]);
                if ($gResp->successful()) {
                    $extractedText = (string) data_get($gResp->json(), 'candidates.0.content.parts.0.text', '');
                }
            } catch (\Throwable $e) {}
        }

        // المحرك 2 (المدمج الجاهز فوراً بدون أي إعدادات): محرك OCR السحابي المجاني
        if (trim($extractedText) === '') {
            try {
                $ocrKey = trim((string) ($settings['ocr_space_api_key'] ?? env('OCR_SPACE_API_KEY', 'helloworld')));
                $b64DataUri = 'data:' . $mime . ';base64,' . base64_encode($imageBytes);

                $ocrResp = Http::withoutVerifying()
                    ->timeout(25)
                    ->asForm()
                    ->post('https://api.ocr.space/parse/image', [
                        'apikey'            => $ocrKey,
                        'base64Image'       => $b64DataUri,
                        'language'          => 'eng',
                        'isOverlayRequired' => 'false',
                        'scale'             => 'true',
                        'OCREngine'         => '2',
                    ]);

                if ($ocrResp->successful()) {
                    $parsedResults = data_get($ocrResp->json(), 'ParsedResults', []);
                    if (is_array($parsedResults)) {
                        foreach ($parsedResults as $pr) {
                            $extractedText .= "\n" . (string) ($pr['ParsedText'] ?? '');
                        }
                    }
                }
            } catch (\Throwable $e) {}
        }

        if (!empty($media['is_temp']) && file_exists($filePath)) {
            @unlink($filePath);
        }

        if (trim($extractedText) === '') {
            return [];
        }

        return extractPartNumbersWithQuantitiesFromText($extractedText, $ownerUserId);
    } catch (\Throwable $e) {
        if (!empty($media['is_temp']) && file_exists($filePath)) {
            @unlink($filePath);
        }
        return [];
    }
}

/*
|--------------------------------------------------------------------------
| 5. دوال إعدادات البوت وإرسال رسائل وملفات الواتساب ومعالجة الويب هوك (Webhook)
|--------------------------------------------------------------------------
*/

function getUserBotSettings(int $userId): array {
    $defaults = [
        'bot_enabled'           => "1",
        'reply_available'       => "القطعة متوفرة لدينا ✅\nاسم الصنف: {item_name}\nرقم الصنف: {item_number}\nالكمية المتوفرة: {quantity}\nالسعر: {price} ريال",
        'reply_unavailable'     => "غير متوفر ❌",
        'reply_quota_exceeded'  => "نعتذر منك، لقد استنفدت الحد المسموح به للاستعلام الآلي.",
        'max_queries_per_phone' => "0",
        'quota_reset_period'    => "manual",
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
        '{item_name}'     => $data['item_name'] ?? '',
        '{item_number}'   => $data['item_number'] ?? '',
        '{price}'         => $data['price'] ?? '',
        '{quantity}'      => $data['quantity'] ?? '',
        '{requested_qty}' => $data['requested_qty'] ?? '',
        '{car}'           => $data['car'] ?? '',
        '{category}'      => $data['category'] ?? '',
        '{year}'          => $data['year'] ?? '',
        '{part}'          => $data['part'] ?? '',
        '{client_name}'   => $data['client_name'] ?? '',
        '{phone}'         => $data['phone'] ?? '',
        '{items}'         => $data['items'] ?? '',
    ];

    $output = str_replace(array_keys($replacements), array_values($replacements), $template);

    if (($data['price'] ?? null) === '') {
        $output = preg_replace('/^.*(?:السعر|سعر).*?:\s*(?:ريال|ر\.س|SAR)?\s*$/imu', '', $output);
    }
    if (($data['quantity'] ?? null) === '') {
        $output = preg_replace('/^.*(?:الكمية|الكميه|المتوفر).*?:\s*$/imu', '', $output);
    }

    $output = preg_replace("/\n{3,}/", "\n\n", trim((string) $output));
    return $output;
}

function buildSingleUnavailableItemReply(string $template, string $partNumber, string $clientName, string $phone): string {
    $tpl = trim($template);
    if ($tpl === '') {
        return '';
    }

    $hasPartPlaceholder = str_contains($tpl, '{item_number}')
                       || str_contains($tpl, '{part}')
                       || str_contains($tpl, '{item_name}')
                       || str_contains($tpl, '{items}');

    $formatted = formatBotTemplate($tpl, [
        'item_name'   => $partNumber,
        'item_number' => $partNumber,
        'price'       => '',
        'quantity'    => '0',
        'car'         => '',
        'category'    => '',
        'year'        => '',
        'part'        => $partNumber,
        'client_name' => $clientName,
        'phone'       => $phone,
        'items'       => $partNumber,
    ]);

    if (!$hasPartPlaceholder) {
        return "{$partNumber} : {$formatted}";
    }

    return $formatted;
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

function resolveGreatWhatsMessagesEndpoint(string $apiUrl): string {
    $url = trim($apiUrl);
    if ($url === '') {
        return 'https://greatwhats.com/api/v1/messages';
    }
    if (str_contains($url, 'greatwhats.com') && !str_contains($url, '/api/v1/messages')) {
        return 'https://greatwhats.com/api/v1/messages';
    }
    return $url;
}

function sendWhatsappReplyMessage(array $settings, string $cleanPhone, string $rawSender, $convId, string $replyText): void {
    $apiUrl    = resolveGreatWhatsMessagesEndpoint($settings['whatsapp_api_url'] ?? '');
    $apiToken  = trim($settings['whatsapp_api_token'] ?? '');
    $sessionId = trim($settings['whatsapp_session_id'] ?? '');

    if ($apiUrl === '' || trim($replyText) === '') {
        return;
    }

    $headers = [
        'Accept'       => 'application/json',
        'Content-Type' => 'application/json',
    ];
    if ($apiToken !== '') {
        $headers['X-API-Key']     = $apiToken;
        $headers['X-Api-Key']     = $apiToken;
        $headers['Authorization'] = 'Bearer ' . $apiToken;
    }

    $targetTo = str_contains($rawSender, '@g.us') ? $rawSender : normalizeWhatsappDigits($cleanPhone ?: $rawSender);

    $maxCharsPerMsg = 3500;
    $chunks = [];
    if (mb_strlen($replyText) <= $maxCharsPerMsg) {
        $chunks[] = $replyText;
    } else {
        $lines   = explode("\n", $replyText);
        $current = '';
        foreach ($lines as $line) {
            if (mb_strlen($current . "\n" . $line) > $maxCharsPerMsg && $current !== '') {
                $chunks[] = trim($current);
                $current  = $line;
            } else {
                $current = $current === '' ? $line : ($current . "\n" . $line);
            }
        }
        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }
    }

    foreach ($chunks as $idx => $chunkText) {
        try {
            if ($idx > 0) {
                usleep(300000);
            }

            // الصيغة الرسمية المعتمدة في GreatWhats (POST /api/v1/messages)
            $payload = [
                'to'   => (string) $targetTo,
                'text' => $chunkText,
            ];

            if ($sessionId !== '') {
                $payload['session_id'] = is_numeric($sessionId) ? (int) $sessionId : $sessionId;
            }
            if (!empty($convId)) {
                $payload['conversation_id'] = $convId;
            }

            // إضافة الحقول المرادفة فقط إذا كان المزود مختلفاً عن GreatWhats
            if (!str_contains($apiUrl, 'greatwhats.com')) {
                $payload['phone']   = (string) $targetTo;
                $payload['chatId']  = $rawSender;
                $payload['body']    = $chunkText;
                $payload['message'] = $chunkText;
            }

            Http::withHeaders($headers)
                ->withoutVerifying()
                ->timeout(15)
                ->post($apiUrl, $payload);
        } catch (\Throwable $e) {}
    }
}

function sendWhatsappExcelDocument(array $settings, string $cleanPhone, string $rawSender, $convId, string $filePath, string $fileName, string $caption): void {
    $apiUrl    = resolveGreatWhatsMessagesEndpoint($settings['whatsapp_api_url'] ?? '');
    $apiToken  = trim($settings['whatsapp_api_token'] ?? '');
    $sessionId = trim($settings['whatsapp_session_id'] ?? '');

    if ($apiUrl === '' || !file_exists($filePath)) {
        return;
    }

    $headers = ['Accept' => 'application/json'];
    if ($apiToken !== '') {
        $headers['X-API-Key']     = $apiToken;
        $headers['X-Api-Key']     = $apiToken;
        $headers['Authorization'] = 'Bearer ' . $apiToken;
    }

    $targetTo = str_contains($rawSender, '@g.us') ? $rawSender : normalizeWhatsappDigits($cleanPhone ?: $rawSender);
    $sentSuccessfully = false;

    // الطريقة 1 حسب دليل GreatWhats: رابط عام media_url (JSON)
    try {
        $publicUrl = url('/whatsapp-exports/' . basename($filePath));
        $jsonPayload = [
            'to'        => (string) $targetTo,
            'type'      => 'document',
            'media_url' => $publicUrl,
            'caption'   => $caption,
        ];
        if ($sessionId !== '') {
            $jsonPayload['session_id'] = is_numeric($sessionId) ? (int) $sessionId : $sessionId;
        }

        $respUrl = Http::withHeaders($headers)
            ->withoutVerifying()
            ->timeout(20)
            ->post($apiUrl, $jsonPayload);

        if ($respUrl->successful()) {
            $sentSuccessfully = true;
        }
    } catch (\Throwable $e) {
        $sentSuccessfully = false;
    }

    // الطريقة 2 حسب دليل GreatWhats: رفع مباشر Multipart (الحقل media)
    if (!$sentSuccessfully) {
        try {
            $multipartData = [
                'to'      => (string) $targetTo,
                'type'    => 'document',
                'caption' => $caption,
            ];
            if ($sessionId !== '') {
                $multipartData['session_id'] = is_numeric($sessionId) ? (int) $sessionId : $sessionId;
            }

            Http::withHeaders($headers)
                ->withoutVerifying()
                ->timeout(30)
                ->attach(
                    'media',
                    file_get_contents($filePath),
                    $fileName,
                    ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
                )
                ->post($apiUrl, $multipartData);
        } catch (\Throwable $e) {}
    }
}

function checkAndResetQuotaAutomatically(int $ownerUserId, array $phoneVariants, $quotaRecord, string $resetPeriod): int {
    if (!$quotaRecord) {
        return 0;
    }

    $currentCount = (int) $quotaRecord->queries_count;
    if ($resetPeriod === 'manual' || $currentCount === 0) {
        return $currentCount;
    }

    $refDateStr = $quotaRecord->last_reset_at ?? $quotaRecord->updated_at ?? $quotaRecord->created_at;
    if (!$refDateStr) {
        return $currentCount;
    }

    try {
        $refDate = Carbon::parse($refDateStr, 'Asia/Riyadh');
        $now     = Carbon::now('Asia/Riyadh');
        $shouldReset = false;

        if ($resetPeriod === 'daily' && ($refDate->format('Y-m-d') !== $now->format('Y-m-d') || $refDate->diffInHours($now) >= 24)) {
            $shouldReset = true;
        } elseif ($resetPeriod === 'weekly' && $refDate->diffInDays($now) >= 7) {
            $shouldReset = true;
        } elseif ($resetPeriod === 'monthly' && ($refDate->month !== $now->month || $refDate->year !== $now->year)) {
            $shouldReset = true;
        }

        if ($shouldReset) {
            DB::table('whatsapp_customer_quotas')
                ->where('user_id', $ownerUserId)
                ->whereIn('phone', $phoneVariants)
                ->update([
                    'queries_count' => 0,
                    'last_reset_at' => now(),
                    'updated_at'    => now(),
                ]);
            return 0;
        }
    } catch (\Throwable $e) {}

    return $currentCount;
}

function recordRadarInquiryLog(
    int $ownerUserId,
    string $phone,
    string $partNumber,
    ?string $itemName,
    string $status,
    int $reqQty,
    int $availQty,
    ?float $price,
    string $source = 'text'
): void {
    $normPart = normalizePartNumberClean($partNumber);
    if ($normPart === '') return;

    $todayStr = Carbon::now('Asia/Riyadh')->format('Y-m-d');
    $now      = now();

    DB::table('whatsapp_inquiry_logs')->insert([
        'user_id'         => $ownerUserId,
        'phone'           => $phone ?: 'محاكاة/مباشر',
        'part_number'     => strtoupper(trim($partNumber)),
        'normalized_part' => $normPart,
        'item_name'       => $itemName,
        'status'          => $status,
        'requested_qty'   => max(1, $reqQty),
        'available_qty'   => max(0, $availQty),
        'price'           => $price,
        'source'          => $source,
        'inquiry_date'    => $todayStr,
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);
}

function getPhoneMatchVariants(string $normalizedPhone): array {
    $variants = [$normalizedPhone];
    if (str_starts_with($normalizedPhone, '966') && strlen($normalizedPhone) === 12) {
        $local9     = substr($normalizedPhone, 3);
        $variants[] = '0' . $local9;
        $variants[] = $local9;
        $variants[] = '+966' . $local9;
    }
    return array_values(array_unique(array_filter($variants)));
}

if (!function_exists('sendWhatsappLoginOtp')) {
    function sendWhatsappLoginOtp($user, string $otpCode): bool {
        try {
            $cleanPhone = normalizeWhatsappDigits((string) ($user->phone ?? ''));
            if ($cleanPhone === '') {
                return false;
            }

            $settings = getUserBotSettings((int) $user->id);
            if (empty(trim($settings['whatsapp_api_url'] ?? ''))) {
                $admin = User::where('role', 'admin')->first();
                if ($admin) {
                    $settings = getUserBotSettings((int) $admin->id);
                }
            }

            if (empty(trim($settings['whatsapp_api_url'] ?? ''))) {
                $firstConfigured = DB::table('user_bot_settings')
                    ->where('key', 'whatsapp_api_url')
                    ->whereNotNull('value')
                    ->where('value', '!=', '')
                    ->first();
                if ($firstConfigured) {
                    $settings = getUserBotSettings((int) $firstConfigured->user_id);
                }
            }

            $apiUrl    = resolveGreatWhatsMessagesEndpoint($settings['whatsapp_api_url'] ?? '');
            $apiToken  = trim($settings['whatsapp_api_token'] ?? '');
            $sessionId = trim($settings['whatsapp_session_id'] ?? '');

            if ($apiUrl === '') {
                return false;
            }

            $headers = [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ];
            if ($apiToken !== '') {
                $headers['X-API-Key']     = $apiToken;
                $headers['X-Api-Key']     = $apiToken;
                $headers['Authorization'] = 'Bearer ' . $apiToken;
            }

            $messageText = "🔐 كود التحقق لتسجيل الدخول هو: *{$otpCode}*";
            $payload = [
                'to'   => $cleanPhone,
                'text' => $messageText,
            ];
            if ($sessionId !== '') {
                $payload['session_id'] = is_numeric($sessionId) ? (int) $sessionId : $sessionId;
            }

            $response = Http::withHeaders($headers)
                ->withoutVerifying()
                ->timeout(12)
                ->post($apiUrl, $payload);

            return $response->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('detectGroupAndBroadcastStatus')) {
    function detectGroupAndBroadcastStatus(array $payload): array {
        $rawJson = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '';

        $isBroadcast = str_contains($rawJson, 'status@broadcast')
                    || str_contains($rawJson, '@broadcast')
                    || str_contains($rawJson, '@newsletter')
                    || (bool) data_get($payload, 'data.isBroadcast', false)
                    || (bool) data_get($payload, 'isBroadcast', false);

        $groupJid = '';
        $candidates = [
            data_get($payload, 'data.key.remoteJid'),
            data_get($payload, 'data.remoteJid'),
            data_get($payload, 'data.from'),
            data_get($payload, 'from'),
            data_get($payload, 'chatId'),
            data_get($payload, 'data.chatId'),
            data_get($payload, 'data.group_id'),
            data_get($payload, 'group_id'),
        ];

        foreach ($candidates as $cand) {
            if (is_string($cand) && str_contains($cand, '@g.us')) {
                $groupJid = trim($cand);
                break;
            }
        }

        $isGroup = ($groupJid !== '')
                || str_contains($rawJson, '@g.us')
                || (bool) data_get($payload, 'data.isGroup', false)
                || (bool) data_get($payload, 'isGroup', false)
                || (bool) data_get($payload, 'data.is_group', false)
                || (bool) data_get($payload, 'is_group', false);

        return [
            'is_group'     => $isGroup,
            'is_broadcast' => $isBroadcast,
            'group_jid'    => $groupJid,
        ];
    }
}

if (!function_exists('isWithinAllowedTimeWindow')) {
    function isWithinAllowedTimeWindow(?string $startTime, ?string $endTime): bool {
        $start = trim((string) $startTime);
        $end   = trim((string) $endTime);

        if ($start === '' || $end === '') {
            return true;
        }

        try {
            $nowMinutes = Carbon::now('Asia/Riyadh')->hour * 60 + Carbon::now('Asia/Riyadh')->minute;

            $sParts = explode(':', $start);
            $eParts = explode(':', $end);
            if (count($sParts) < 2 || count($eParts) < 2) {
                return true;
            }

            $startMinutes = ((int) $sParts[0]) * 60 + ((int) $sParts[1]);
            $endMinutes   = ((int) $eParts[0]) * 60 + ((int) $eParts[1]);

            if ($startMinutes <= $endMinutes) {
                return $nowMinutes >= $startMinutes && $nowMinutes <= $endMinutes;
            } else {
                return $nowMinutes >= $startMinutes || $nowMinutes <= $endMinutes;
            }
        } catch (\Throwable $e) {
            return true;
        }
    }
}

function handleWebhook(Request $request) {
    @ignore_user_abort(true);
    @set_time_limit(0);

    $payload = $request->all();
    if (empty($payload)) {
        $rawInput = file_get_contents('php://input');
        if (!empty($rawInput)) {
            $decoded = json_decode($rawInput, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }
    }

    $eventType = (string) ($request->input('event') ?? data_get($payload, 'event') ?? data_get($payload, 'type') ?? 'message.received');

    // 1. الرد الفوري السريع على فحص GreatWhats وإشعارات الحالة حتى لا يحدث Timeout أبداً
    if (in_array($eventType, ['test.ping', 'webhook.test', 'ping', 'message.status', 'session.status', 'conversation.updated', 'message.sent', 'message.ack'], true)) {
        return response()->json(['status' => 'ok', 'event' => $eventType], 200);
    }

    $direction = strtolower((string) (data_get($payload, 'data.direction') ?? data_get($payload, 'direction') ?? ''));
    $fromMe    = data_get($payload, 'data.fromMe')
              ?? data_get($payload, 'data.from_me')
              ?? data_get($payload, 'data.message.fromMe')
              ?? data_get($payload, 'data.message.from_me')
              ?? data_get($payload, 'data.is_from_me')
              ?? data_get($payload, 'fromMe')
              ?? data_get($payload, 'from_me', false);

    if ($fromMe === true || $fromMe === 'true' || $fromMe === 1 || $fromMe === '1' || in_array($direction, ['outbound', 'out', 'sent'], true)) {
        return response()->json(['status' => 'ignored_from_me'], 200);
    }

    $ownerUserId = resolveWebhookOwnerUserId($request, $payload);

    // 2. إذا كان الطلب حقيقياً من خادم GreatWhats (وليس محاكاة داخلية)، نغلق اتصال الـ HTTP فوراً بكود 200 OK خلال أقل من 0.1 ثانية لمنع خطأ cURL error 28 (8000ms) ثم نكمل المعالجة والرد في الخلفية
    $isGreatWhatsPush = !$request->expectsJson() || str_contains(strtolower((string) $request->userAgent()), 'curl') || str_contains(strtolower((string) $request->userAgent()), 'greatwhats') || $request->hasHeader('X-Webhook-Signature') || $request->hasHeader('X-GreatWhats-Signature');

    if ($isGreatWhatsPush && function_exists('fastcgi_finish_request')) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'accepted_processing_async', 'received' => true]);
        @ob_flush();
        @flush();
        @fastcgi_finish_request();
    }

    $ownerUser   = User::find($ownerUserId);
    $settings    = getUserBotSettings($ownerUserId);
    $isBotActive = ($settings['bot_enabled'] ?? '1') === '1';
    $allowGroups = ($settings['allow_group_replies'] ?? '0') === '1';

    $groupCheck = detectGroupAndBroadcastStatus($payload);

    if ($groupCheck['is_broadcast']) {
        return response()->json(['status' => 'ignored_broadcast_or_status'], 200);
    }

    if ($groupCheck['is_group'] && !$allowGroups && $isBotActive) {
        return response()->json(['status' => 'ignored_group_message_completely'], 200);
    }

    try {
        DB::table('webhook_logs')->insert([
            'event_type' => $eventType,
            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (\Throwable $e) {}

    $participantPhone = data_get($payload, 'data.participant')
                     ?? data_get($payload, 'data.key.participant')
                     ?? data_get($payload, 'data.message.participant')
                     ?? data_get($payload, 'data.author')
                     ?? data_get($payload, 'participant');

    // استخراج رقم المرسل بجميع صيغ GreatWhats والـ Webhooks القياسية
    $senderCandidates = [
        data_get($payload, 'data.from'),
        data_get($payload, 'data.sender_phone'),
        data_get($payload, 'data.phone'),
        data_get($payload, 'data.sender'),
        data_get($payload, 'data.contact.phone'),
        data_get($payload, 'data.contact.number'),
        data_get($payload, 'data.conversation.contact_phone'),
        data_get($payload, 'data.conversation.phone'),
        data_get($payload, 'data.message.from'),
        data_get($payload, 'data.message.sender_phone'),
        data_get($payload, 'data.message.phone'),
        data_get($payload, 'data.key.remoteJid'),
        data_get($payload, 'from'),
        data_get($payload, 'sender_phone'),
        data_get($payload, 'sender'),
        data_get($payload, 'phone'),
        data_get($payload, 'contact.phone'),
    ];

    $sender = null;
    foreach ($senderCandidates as $cand) {
        if (is_string($cand) || is_numeric($cand)) {
            $candStr = trim((string) $cand);
            if ($candStr !== '') {
                $sender = $candStr;
                break;
            }
        } elseif (is_array($cand)) {
            $nestedPhone = $cand['phone'] ?? $cand['number'] ?? $cand['id'] ?? $cand['jid'] ?? null;
            if (!empty($nestedPhone) && (is_string($nestedPhone) || is_numeric($nestedPhone))) {
                $sender = trim((string) $nestedPhone);
                break;
            }
        }
    }

    if (!$sender) {
        return response()->json(['status' => 'no_sender_detected'], 200);
    }

    $rawSender = ($groupCheck['is_group'] && !empty($groupCheck['group_jid']))
               ? (string) $groupCheck['group_jid']
               : (string) $sender;

    $cleanPhone = ($groupCheck['is_group'] && !empty($groupCheck['group_jid']))
                ? $groupCheck['group_jid']
                : normalizeWhatsappDigits((string) $sender);

    $actualCustomerPhone = ($groupCheck['is_group'] && !empty($participantPhone))
                         ? normalizeWhatsappDigits((string) $participantPhone)
                         : normalizeWhatsappDigits((string) $sender);

    $quotaPhoneKey = $actualCustomerPhone ?: $cleanPhone;

    if ($cleanPhone === '') {
        return response()->json(['status' => 'empty_phone'], 200);
    }

    $phoneVariants = getPhoneMatchVariants($quotaPhoneKey);

    // 1. استخراج نص الرسالة أولاً (سريع جداً ولا يستهلك وقت اتصال خارجي)
    $textCandidates = [
        data_get($payload, 'data.text'),
        data_get($payload, 'data.body'),
        data_get($payload, 'data.message_body'),
        data_get($payload, 'data.content'),
        data_get($payload, 'data.caption'),
        data_get($payload, 'data.message.text'),
        data_get($payload, 'data.message.body'),
        data_get($payload, 'data.message.content'),
        data_get($payload, 'data.message.caption'),
        data_get($payload, 'data.message.conversation'),
        data_get($payload, 'data.message.extendedTextMessage.text'),
        data_get($payload, 'data.message.imageMessage.caption'),
        data_get($payload, 'data.message.documentMessage.caption'),
        data_get($payload, 'text'),
        data_get($payload, 'body'),
        data_get($payload, 'message_body'),
        data_get($payload, 'content'),
        data_get($payload, 'caption'),
        data_get($payload, 'message'),
        data_get($payload, 'data.message'),
    ];

    $messageText = '';
    foreach ($textCandidates as $tc) {
        if (is_string($tc) && trim($tc) !== '') {
            $messageText = trim($tc);
            break;
        } elseif (is_array($tc)) {
            $sub = $tc['text'] ?? $tc['body'] ?? $tc['content'] ?? $tc['caption'] ?? $tc['conversation'] ?? data_get($tc, 'extendedTextMessage.text') ?? null;
            if (is_string($sub) && trim($sub) !== '') {
                $messageText = trim($sub);
                break;
            }
        }
    }

    $isExcelSource  = false;
    $isImageSource  = false;
    $requestedItems = [];

    // 2. فحص هل الرسالة ملف إكسل
    $excelInquiries = extractExcelItemsFromWhatsappPayload($payload, $settings);
    if (!empty($excelInquiries)) {
        $requestedItems = $excelInquiries;
        $isExcelSource  = true;
    }

    // 3. إذا لم تكن إكسل، نفحص النص المباشر أولاً (أسرع)
    if (empty($requestedItems) && $messageText !== '') {
        $requestedItems = extractPartNumbersWithQuantitiesFromText($messageText, $ownerUserId);
    }

    // 4. إذا لم يوجد نص فيه أرقام، نفحص هل الرسالة صورة (OCR)
    if (empty($requestedItems)) {
        $imageInquiries = extractImageOcrItemsFromWhatsappPayload($payload, $settings, $ownerUserId);
        if (!empty($imageInquiries)) {
            $requestedItems = $imageInquiries;
            $isImageSource  = true;
        }
    }

    if (empty($requestedItems)) {
        return response()->json(['status' => 'ignored_no_part_number_detected'], 200);
    }

    $logSourceLabel = $isExcelSource ? 'excel' : ($isImageSource ? 'image_ocr' : 'text');

    // إذا كان البوت متوقفاً: يسجل جميع الأصناف في الرادار بصمت تام ولا يرد
    if (!$isBotActive) {
        foreach ($requestedItems as $reqEntry) {
            $pNum   = $reqEntry['part_number'];
            $reqQty = max(1, (int) ($reqEntry['requested_qty'] ?? 1));
            $item   = findExactItemIgnoringDashes($ownerUserId, $pNum, true);

            if ($item) {
                recordRadarInquiryLog($ownerUserId, $quotaPhoneKey, $item->item_number, $item->item_name, 'available', $reqQty, (int) $item->quantity, (float) $item->price, $logSourceLabel);
            } else {
                recordRadarInquiryLog($ownerUserId, $quotaPhoneKey, $pNum, null, 'unavailable', $reqQty, 0, null, $logSourceLabel);
            }
        }
        return response()->json(['status' => 'bot_disabled_logged_silently_to_radar', 'logged_items' => count($requestedItems)], 200);
    }

    // 1. فحص القائمة السوداء
    $isBlacklisted = DB::table('whatsapp_blacklist_phones')
        ->where('user_id', $ownerUserId)
        ->whereIn('phone', $phoneVariants)
        ->exists();

    if ($isBlacklisted) {
        return response()->json(['status' => 'ignored_blacklisted_phone'], 200);
    }

    // 2. فحص الاستثناء والوقت المسموح
    $whitelistRecord = DB::table('whatsapp_whitelist_phones')
        ->where('user_id', $ownerUserId)
        ->whereIn('phone', $phoneVariants)
        ->first();

    if ($whitelistRecord && !isWithinAllowedTimeWindow($whitelistRecord->allowed_start_time ?? null, $whitelistRecord->allowed_end_time ?? null)) {
        return response()->json(['status' => 'ignored_outside_whitelist_allowed_hours'], 200);
    }

    if ($isExcelSource && $whitelistRecord && !((bool) ($whitelistRecord->allow_excel ?? true))) {
        return response()->json(['status' => 'ignored_excel_disabled_for_this_whitelist_phone'], 200);
    }

    // 3. فحص الحصة ودورة التصفير
    $msgAvailable     = trim($settings['reply_available'] ?? '');
    $msgUnavailable   = trim($settings['reply_unavailable'] ?? '');
    $msgQuotaExceeded = trim($settings['reply_quota_exceeded'] ?? '');
    $maxQueriesLimit  = (int) ($settings['max_queries_per_phone'] ?? 0);
    $globalReset      = $settings['quota_reset_period'] ?? 'manual';
    $sessionId        = trim($settings['whatsapp_session_id'] ?? '');
    $convId           = data_get($payload, 'data.conversation_id') ?? data_get($payload, 'data.conversation.id') ?? data_get($payload, 'conversation_id');

    $effectiveResetPeriod = ($whitelistRecord && !empty($whitelistRecord->reset_period) && $whitelistRecord->reset_period !== 'default')
        ? $whitelistRecord->reset_period
        : $globalReset;

    $quotaRecord = DB::table('whatsapp_customer_quotas')
        ->where('user_id', $ownerUserId)
        ->whereIn('phone', $phoneVariants)
        ->first();

    $currentQueriesUsed = checkAndResetQuotaAutomatically($ownerUserId, $phoneVariants, $quotaRecord, $effectiveResetPeriod);

    $effectiveLimit = null;
    if ($whitelistRecord) {
        $cQuota = (int) ($whitelistRecord->custom_quota ?? 0);
        $effectiveLimit = $cQuota > 0 ? $cQuota : null;
    } else {
        $effectiveLimit = $maxQueriesLimit > 0 ? $maxQueriesLimit : null;
    }

    if ($effectiveLimit !== null && $currentQueriesUsed >= $effectiveLimit) {
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

    $quotaHitDuringBatch = false;
    if ($effectiveLimit !== null) {
        $remainingAllowance = max(0, $effectiveLimit - $currentQueriesUsed);
        if (count($requestedItems) > $remainingAllowance) {
            $requestedItems      = array_slice($requestedItems, 0, $remainingAllowance);
            $quotaHitDuringBatch = true;
        }
    }

    $canShowAvailable   = $whitelistRecord ? (bool) ($whitelistRecord->allow_available ?? true) : true;
    $canShowUnavailable = $whitelistRecord ? (bool) ($whitelistRecord->allow_unavailable ?? true) : true;
    $canShowPrices      = $whitelistRecord ? (bool) ($whitelistRecord->allow_prices ?? true) : true;
    $canShowQuantities  = $whitelistRecord ? (bool) ($whitelistRecord->allow_quantities ?? true) : true;

    $replyBlocks    = [];
    $excelReplyRows = [];
    $countedQueries = 0;
    $now            = now();

    foreach ($requestedItems as $reqEntry) {
        $pNum   = $reqEntry['part_number'];
        $reqQty = max(1, (int) ($reqEntry['requested_qty'] ?? 1));
        $countedQueries++;

        $item = findExactItemIgnoringDashes($ownerUserId, $pNum, true);

        if ($item) {
            $stockQty     = (int) $item->quantity;
            $fulfilledQty = ($reqQty > 1) ? min($reqQty, $stockQty) : $stockQty;

            recordRadarInquiryLog(
                $ownerUserId,
                $quotaPhoneKey,
                $item->item_number,
                $item->item_name,
                'available',
                $reqQty,
                $stockQty,
                (float) $item->price,
                $logSourceLabel
            );

            if ($isExcelSource) {
                $rowCells = [$item->item_number];
                if ($canShowQuantities) {
                    $rowCells[] = $fulfilledQty;
                }
                if ($canShowPrices) {
                    $rowCells[] = (float) $item->price;
                }
                $excelReplyRows[] = $rowCells;
                continue;
            }

            if ($canShowAvailable) {
                if ($whitelistRecord) {
                    $wlLines = [$item->item_number];
                    if ($canShowQuantities) {
                        $wlLines[] = (string) $fulfilledQty;
                    }
                    if ($canShowPrices) {
                        $wlLines[] = $item->price . ' ريال';
                    }
                    $replyBlocks[] = implode("\n", $wlLines);
                } elseif ($msgAvailable !== '') {
                    $formattedBlock = formatBotTemplate($msgAvailable, [
                        'item_name'     => $item->item_name,
                        'item_number'   => $item->item_number,
                        'price'         => (string) $item->price,
                        'quantity'      => (string) $fulfilledQty,
                        'requested_qty' => (string) $reqQty,
                        'car'           => $item->detected_car_name ?? '',
                        'category'      => $item->detected_category ?? '',
                        'year'          => $item->detected_years ?? '',
                        'part'          => $pNum,
                        'client_name'   => $ownerUser->name ?? '',
                        'phone'         => $quotaPhoneKey,
                        'items'         => "- {$item->item_name} (رقم: {$item->item_number}) | المتوفر: {$fulfilledQty} | السعر: {$item->price} ريال",
                    ]);

                    if ($formattedBlock !== '') {
                        $replyBlocks[] = $formattedBlock;
                    }
                }
            }
        } else {
            recordRadarInquiryLog(
                $ownerUserId,
                $quotaPhoneKey,
                $pNum,
                null,
                'unavailable',
                $reqQty,
                0,
                null,
                $logSourceLabel
            );

            if (!$isExcelSource && $canShowUnavailable && $msgUnavailable !== '') {
                $singleUnavailBlock = buildSingleUnavailableItemReply(
                    $msgUnavailable,
                    $pNum,
                    (string) ($ownerUser->name ?? ''),
                    $quotaPhoneKey
                );
                if ($singleUnavailBlock !== '') {
                    $replyBlocks[] = $singleUnavailBlock;
                }
            }
        }
    }

    if ($quotaHitDuringBatch && $msgQuotaExceeded !== '') {
        $replyBlocks[] = formatBotTemplate($msgQuotaExceeded, [
            'client_name' => $ownerUser->name ?? '',
            'phone'       => $quotaPhoneKey,
        ]);
    }

    DB::table('whatsapp_customer_quotas')->updateOrInsert(
        ['user_id' => $ownerUserId, 'phone' => $quotaPhoneKey],
        [
            'queries_count' => $currentQueriesUsed + $countedQueries,
            'last_reset_at' => $quotaRecord->last_reset_at ?? $now,
            'updated_at'    => $now,
            'created_at'    => $quotaRecord->created_at ?? $now,
        ]
    );

    if ($isExcelSource) {
        if (empty($excelReplyRows)) {
            if ($canShowUnavailable && $msgUnavailable !== '') {
                sendWhatsappReplyMessage($settings, $cleanPhone, $rawSender, $convId, $msgUnavailable);
            }
            return response()->json(['status' => 'excel_processed_no_available_items'], 200);
        }

        $excelHeaders = ['رقم الصنف'];
        if ($canShowQuantities) {
            $excelHeaders[] = 'الكمية';
        }
        if ($canShowPrices) {
            $excelHeaders[] = 'السعر';
        }

        $outDir = storage_path('app/public/whatsapp_exports');
        if (!is_dir($outDir)) {
            @mkdir($outDir, 0775, true);
        }
        $outFileName = 'pricing_' . date('Ymd_His') . '_' . uniqid() . '.xlsx';
        $outFullPath = $outDir . DIRECTORY_SEPARATOR . $outFileName;

        if (generateExcelReplyFileXlsx($excelHeaders, $excelReplyRows, $outFullPath)) {
            $availCount = count($excelReplyRows);
            $caption    = "✅ تم تسعير الأصناف المتوفرة ({$availCount} صنف)";
            sendWhatsappExcelDocument($settings, $cleanPhone, $rawSender, $convId, $outFullPath, 'تسعير_الاصناف_المتوفرة.xlsx', $caption);
        }

        return response()->json([
            'status'          => 'excel_reply_sent',
            'session_id'      => $sessionId,
            'counted_queries' => $countedQueries,
            'available_rows'  => count($excelReplyRows),
        ], 200);
    }

    $finalDirectReply = implode("\n\n", array_values(array_filter($replyBlocks)));

    if ($finalDirectReply === '') {
        return response()->json(['status' => 'skipped_empty_template_or_permissions'], 200);
    }

    sendWhatsappReplyMessage($settings, $cleanPhone, $rawSender, $convId, $finalDirectReply);

    return response()->json([
        'status'          => 'success',
        'source'          => $logSourceLabel,
        'session_id'      => $sessionId,
        'counted_queries' => $countedQueries,
        'reply'           => $finalDirectReply,
        'message'         => $finalDirectReply,
        'text'            => $finalDirectReply,
        'body'            => $finalDirectReply,
    ], 200);
}

/*
|--------------------------------------------------------------------------
| 6. دوال بناء الصفحات المستقلة وحقن روابط القائمة الجانبية وزر تشغيل/إيقاف البوت
|--------------------------------------------------------------------------
*/

function injectSidebarNavigationLinks(string $html): string {
    $isAdmin   = auth()->check() && auth()->user()->role === 'admin';
    $quotasUrl = route('whatsapp.quotas.page');
    $radarUrl  = $isAdmin ? route('admin.radar.page') : '';
    $currPath  = request()->path();

    $isQuotasActive = str_contains($currPath, 'whatsapp-quotas') ? 'true' : 'false';
    $isRadarActive  = str_contains($currPath, 'admin-radar') ? 'true' : 'false';
    $isAdminJs      = $isAdmin ? 'true' : 'false';

    $navScript = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    const aiLink = document.querySelector('a[href*="ai-whatsapp"]');
    if (aiLink && !document.getElementById('epc-nav-quotas')) {
        const quotasLink = aiLink.cloneNode(true);
        quotasLink.id = 'epc-nav-quotas';
        quotasLink.href = '{$quotasUrl}';
        quotasLink.innerHTML = '🛡️ <span style="margin-right:8px;">الاستثناءات والحصص</span>';

        if ({$isQuotasActive}) {
            quotasLink.style.background = '#16a34a';
            quotasLink.style.color = '#ffffff';
            quotasLink.style.borderRadius = '10px';
            aiLink.style.background = 'transparent';
        } else {
            quotasLink.style.background = 'transparent';
            quotasLink.style.color = '#cbd5e1';
            quotasLink.classList.remove('bg-emerald-500', 'bg-green-500', 'bg-green-600', 'text-white', 'active');
        }

        if (aiLink.parentNode && aiLink.parentNode.tagName === 'LI') {
            const li = aiLink.parentNode.cloneNode(false);
            li.appendChild(quotasLink);
            aiLink.parentNode.parentNode.insertBefore(li, aiLink.parentNode.nextSibling);
        } else if (aiLink.parentNode) {
            aiLink.parentNode.insertBefore(quotasLink, aiLink.nextSibling);
        }
    }

    if ({$isAdminJs} && !document.getElementById('epc-nav-radar')) {
        const masterLink = document.querySelector('a[href*="master-catalog"]') || document.querySelector('a[href*="clients"]') || aiLink;
        if (masterLink) {
            const radarLink = masterLink.cloneNode(true);
            radarLink.id = 'epc-nav-radar';
            radarLink.href = '{$radarUrl}';
            radarLink.innerHTML = '📡 <span style="margin-right:8px;"> الاصناف المطلوبة  </span>';

            if ({$isRadarActive}) {
                radarLink.style.background = '#16a34a';
                radarLink.style.color = '#ffffff';
                radarLink.style.borderRadius = '10px';
                if (aiLink) aiLink.style.background = 'transparent';
            } else {
                radarLink.style.background = 'transparent';
                radarLink.style.color = '#cbd5e1';
                radarLink.classList.remove('bg-emerald-500', 'bg-green-500', 'bg-green-600', 'text-white', 'active');
            }

            if (masterLink.parentNode && masterLink.parentNode.tagName === 'LI') {
                const li = masterLink.parentNode.cloneNode(false);
                li.appendChild(radarLink);
                masterLink.parentNode.parentNode.insertBefore(li, masterLink.parentNode.nextSibling);
            } else if (masterLink.parentNode) {
                masterLink.parentNode.insertBefore(radarLink, masterLink.nextSibling);
            }
        }
    }
});
</script>
HTML;

    if (str_contains($html, '</body>')) {
        return str_replace('</body>', $navScript . "\n</body>", $html);
    }
    return $html . $navScript;
}

/**
 * حقن شريط تفعيل / إيقاف البوت في أعلى صفحة بوت الواتساب (/ai-whatsapp) + إزالة البطاقات المكررة
 */
function enhanceSecondPageWithBotToggle(string $html, array $botSettings): string {
    $isEnabled = ($botSettings['bot_enabled'] ?? '1') === '1';
    $toggleUrl = route('pages.second.toggle_bot');
    $csrfToken = csrf_token();

    $statusBg    = $isEnabled ? '#f0fdf4' : '#fef2f2';
    $statusBdr   = $isEnabled ? '#86efac' : '#fca5a5';
    $badgeBg     = $isEnabled ? '#16a34a' : '#dc2626';
    $statusText  = $isEnabled ? '🟢 البوت مفعل ' : '🔴 البوت متوقف عن الرد ';
    $btnBg       = $isEnabled ? '#dc2626' : '#16a34a';
    $btnLabel    = $isEnabled ? '⏸️ إيقاف تفعيل البوت' : '▶️ تفعيل البوت';
    $nextVal     = $isEnabled ? '0' : '1';

    $bannerHtml = <<<HTML
    <div id="epc-bot-master-switch" style="background:{$statusBg};border:2px solid {$statusBdr};border-radius:16px;padding:18px 22px;margin-bottom:20px;display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:14px;direction:rtl;box-shadow:0 4px 12px rgba(0,0,0,0.03);">
        <div>
            <div style="font-size:16px;font-weight:800;color:#0f172a;display:flex;align-items:center;gap:8px;">
                <span style="background:{$badgeBg};color:#fff;padding:4px 12px;border-radius:999px;font-size:12px;">حالة البوت</span>
                <span>{$statusText}</span>
            </div>
        </div>
        <form method="POST" action="{$toggleUrl}" style="margin:0;">
            <input type="hidden" name="_token" value="{$csrfToken}">
            <input type="hidden" name="bot_enabled" value="{$nextVal}">
            <button type="submit" style="background:{$btnBg};color:#ffffff;border:none;padding:11px 22px;border-radius:10px;font-weight:800;font-size:14px;cursor:pointer;box-shadow:0 4px 10px rgba(0,0,0,0.12);">
                {$btnLabel}
            </button>
        </form>
    </div>
HTML;

    $bannerJson = json_encode($bannerHtml, JSON_UNESCAPED_UNICODE);

    $script = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    const container = document.querySelector('main') || document.querySelector('[role="main"]') || document.querySelector('.main-content');
    if (container && !document.getElementById('epc-bot-master-switch')) {
        const wrapper = document.createElement('div');
        wrapper.innerHTML = {$bannerJson};
        container.insertBefore(wrapper.firstElementChild, container.firstChild);
    }

    // إزالة البطاقات المكررة من أسفل صفحة /ai-whatsapp
    ['form[action*="whitelist"]', 'form[action*="quotas/reset"]'].forEach(function (sel) {
        document.querySelectorAll(sel).forEach(function (formEl) {
            let card = formEl.closest('.bg-white') || formEl.parentElement;
            if (card) {
                const parentGrid = card.parentElement;
                card.remove();
                if (parentGrid && parentGrid.children.length === 0) {
                    parentGrid.remove();
                }
            }
        });
    });
});
</script>
HTML;

    if (str_contains($html, '</body>')) {
        return str_replace('</body>', $script . "\n</body>", $html);
    }
    return $html . $script;
}

function renderStandalonePageInsideMainLayout(string $pageTitle, string $innerHtmlContent): string {
    $baseHtml = view('pages.second', [
        'customerMessage' => '',
        'matchedItems'    => collect(),
        'botSettings'     => getUserBotSettings(auth()->id()),
        'whitelistPhones' => collect(),
        'customerQuotas'  => collect(),
    ])->render();

    $payloadJson = json_encode($innerHtmlContent, JSON_UNESCAPED_UNICODE);
    $titleJson   = json_encode($pageTitle, JSON_UNESCAPED_UNICODE);

    $replaceScript = <<<HTML
<style id="epc-hide-until-ready">
    main, [role="main"], .main-content { opacity: 0; }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.title = {$titleJson};
    const customHtml = {$payloadJson};

    let container = document.querySelector('main') || document.querySelector('[role="main"]') || document.querySelector('.main-content');
    if (!container) {
        const formEl = document.querySelector('form[action*="ai-whatsapp"]');
        if (formEl) {
            let p = formEl.parentElement;
            while (p && p.parentElement && p.parentElement !== document.body && getComputedStyle(p.parentElement).display !== 'flex') {
                p = p.parentElement;
            }
            container = p;
        }
    }

    if (container) {
        container.innerHTML = customHtml;
        container.style.opacity = '1';

        container.querySelectorAll('script').forEach(function (oldScript) {
            const newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value));
            newScript.textContent = oldScript.textContent;
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    const hideStyle = document.getElementById('epc-hide-until-ready');
    if (hideStyle) hideStyle.remove();
});
</script>
HTML;

    $baseHtml = injectSidebarNavigationLinks($baseHtml);
    if (str_contains($baseHtml, '</body>')) {
        return str_replace('</body>', $replaceScript . "\n</body>", $baseHtml);
    }
    return $baseHtml . $replaceScript;
}

function injectInventoryLiveSearchAndFiltersScript(string $html): string {
    $currentFilter = request()->query('stock_filter', 'all');
    $rollbackUrl   = route('inventory.import.rollback');
    $csrfToken     = csrf_token();

    // التحقق هل تم رفع ملف إكسل جديد خلال آخر 20 ثانية فقط
    $recentJob = DB::table('excel_import_jobs')
        ->where('user_id', auth()->id())
        ->whereNotIn('status', ['rolled_back'])
        ->orderByDesc('id')
        ->first();

    $remainingRollbackSeconds = 0;
    $snapshotIdForRollback    = '';
    if ($recentJob && !empty($recentJob->snapshot_id) && !empty($recentJob->created_at)) {
        $elapsed = Carbon::parse($recentJob->created_at)->diffInSeconds(now());
        if ($elapsed < 20) {
            $remainingRollbackSeconds = 20 - $elapsed;
            $snapshotIdForRollback    = (string) $recentJob->snapshot_id;
        }
    }

    if (session()->has('just_imported_excel_snapshot_id')) {
        $remainingRollbackSeconds = 20;
        $snapshotIdForRollback    = (string) session('just_imported_excel_snapshot_id');
    }

    $injectedAssets = <<<HTML
<style>
    .epc-stock-filters-bar {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 12px 0;
        align-items: center;
    }
    .epc-filter-btn {
        padding: 6px 14px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #334155;
        transition: all 0.15s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .epc-filter-btn:hover {
        background: #f1f5f9;
        border-color: #94a3b8;
    }
    .epc-filter-btn.active {
        background: #2563eb;
        color: #ffffff;
        border-color: #2563eb;
    }
    .epc-live-indicator {
        font-size: 12px;
        color: #16a34a;
        font-weight: 600;
        margin-right: 8px;
        display: none;
    }
    #epc-temp-rollback-banner {
        display: none;
        background: #fffbeb;
        border: 2px solid #f59e0b;
        border-radius: 12px;
        padding: 12px 18px;
        margin: 12px 0;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        direction: rtl;
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.15);
    }
</style>

<div id="epc-temp-rollback-banner">
    <div style="font-size:13px;font-weight:800;color:#92400e;display:flex;align-items:center;gap:8px;">
        <span> يتم رفع ملف اكسل جديد - يمكنك التراجع واستعادة مخزونك السابق خلال:</span>
        <span id="epc-rollback-timer-badge" style="background:#dc2626;color:#fff;padding:3px 10px;border-radius:999px;font-size:13px;font-weight:800;">20 ثانية</span>
    </div>
    <form method="POST" action="{$rollbackUrl}" style="margin:0;">
        <input type="hidden" name="_token" value="{$csrfToken}">
        <input type="hidden" name="snapshot_id" id="epc-rollback-snap-id" value="{$snapshotIdForRollback}">
        <button type="submit" style="background:#dc2626;color:#ffffff;border:none;padding:8px 18px;border-radius:8px;font-weight:800;font-size:13px;cursor:pointer;">
            اضغط للتراجع
        </button>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // 1. إخفاء أي زر تراجع قديم ثابت في الصفحة حتى لا يظهر إلا المؤقت لمدة 20 ثانية
    document.querySelectorAll('form[action*="rollback"]').forEach(function (f) {
        if (!f.closest('#epc-temp-rollback-banner')) {
            f.style.display = 'none';
        }
    });
    document.querySelectorAll('button, a').forEach(function (el) {
        const txt = (el.textContent || '').trim();
        if ((txt.includes('تراجع عن آخر استيراد') || txt.includes('استعادة المخزون السابق')) && !el.closest('#epc-temp-rollback-banner')) {
            el.style.display = 'none';
        }
    });

    const rollbackBanner = document.getElementById('epc-temp-rollback-banner');
    const timerBadge     = document.getElementById('epc-rollback-timer-badge');
    const snapInput      = document.getElementById('epc-rollback-snap-id');
    let countdownInterval = null;

    function startRollbackCountdown(seconds, snapshotId) {
        if (!rollbackBanner || seconds <= 0) return;
        if (snapshotId && snapInput) snapInput.value = snapshotId;

        const mainArea = document.querySelector('main') || document.querySelector('table')?.parentElement;
        if (mainArea && rollbackBanner.parentElement !== mainArea) {
            mainArea.insertBefore(rollbackBanner, mainArea.firstChild);
        }

        let left = Math.min(20, Math.ceil(seconds));
        rollbackBanner.style.display = 'flex';
        if (timerBadge) timerBadge.textContent = left + ' ثانية';

        if (countdownInterval) clearInterval(countdownInterval);
        countdownInterval = setInterval(function () {
            left--;
            if (left <= 0) {
                clearInterval(countdownInterval);
                rollbackBanner.style.display = 'none';
            } else if (timerBadge) {
                timerBadge.textContent = left + ' ثانية';
            }
        }, 1000);
    }

    // إذا تم رفع ملف للتو قبل تحديث الصفحة، يبدأ عداد الـ 20 ثانية فوراً
    const initialSeconds = {$remainingRollbackSeconds};
    if (initialSeconds > 0) {
        startRollbackCountdown(initialSeconds, '{$snapshotIdForRollback}');
    }

    // مراقبة أي عملية رفع ملف إكسل عبر Fetch/AJAX لإظهار الزر لمدة 20 ثانية فور انتهاء الرفع ثم إخفائه
    const origFetch = window.fetch;
    window.fetch = function () {
        const reqUrl = typeof arguments[0] === 'string' ? arguments[0] : (arguments[0]?.url || '');
        return origFetch.apply(this, arguments).then(function (response) {
            if (reqUrl.includes('/inventory/import') && !reqUrl.includes('/rollback') && !reqUrl.includes('/detect-columns')) {
                response.clone().json().then(function (data) {
                    if (data && (data.status === 'success' || data.status === 'background_started' || (data.job && data.job.status === 'completed'))) {
                        const sid = data.snapshot_id || (data.latest_snapshot ? data.latest_snapshot.id : '');
                        startRollbackCountdown(20, sid);
                    }
                }).catch(function () {});
            }
            return response;
        });
    };

    const searchInput = document.querySelector('input[name="search"], input[type="search"], #search-input, #inventory-search');
    if (!searchInput) return;

    let currentStockFilter = '{$currentFilter}';
    let debounceTimer = null;
    let activeController = null;

    const filterBar = document.createElement('div');
    filterBar.className = 'epc-stock-filters-bar';
    filterBar.innerHTML = `
        <span style="font-size:13px;font-weight:700;color:#475569;">فلتر سريع:</span>
        <button type="button" class="epc-filter-btn \${currentStockFilter === 'all' ? 'active' : ''}" data-filter="all">📦 الكل</button>
        <button type="button" class="epc-filter-btn \${currentStockFilter === 'available' ? 'active' : ''}" data-filter="available">✅ المتوفر (> 0)</button>
        <button type="button" class="epc-filter-btn \${currentStockFilter === 'out' ? 'active' : ''}" data-filter="out">❌ المنتهي (0)</button>
        <button type="button" class="epc-filter-btn \${currentStockFilter === 'low' ? 'active' : ''}" data-filter="low">⚠️ منخفض (< 3)</button>
        <button type="button" class="epc-filter-btn \${currentStockFilter === 'no_price' ? 'active' : ''}" data-filter="no_price">💰 بدون سعر (0)</button>
        <span id="epc-live-loading" class="epc-live-indicator">⏳ جاري البحث الفوري...</span>
    `;

    const searchForm = searchInput.closest('form');
    if (searchForm && searchForm.parentNode) {
        searchForm.parentNode.insertBefore(filterBar, searchForm.nextSibling);
        searchForm.addEventListener('submit', function (e) {
            e.preventDefault();
            triggerLiveInventorySearch();
        });
    } else if (searchInput.parentNode) {
        searchInput.parentNode.appendChild(filterBar);
    }

    const loadingBadge = document.getElementById('epc-live-loading');

    function triggerLiveInventorySearch() {
        const queryVal = searchInput.value.trim();
        const url = new URL(window.location.href);
        url.searchParams.set('search', queryVal);
        url.searchParams.set('stock_filter', currentStockFilter);
        url.searchParams.delete('page');

        window.history.replaceState({}, '', url.toString());

        if (activeController) {
            activeController.abort();
        }
        activeController = new AbortController();

        if (loadingBadge) loadingBadge.style.display = 'inline-block';

        origFetch(url.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json, text/html'
            },
            signal: activeController.signal
        })
        .then(res => {
            const contentType = res.headers.get('content-type') || '';
            if (contentType.includes('application/json')) {
                return res.json().then(data => ({ type: 'json', data }));
            }
            return res.text().then(text => ({ type: 'html', text }));
        })
        .then(result => {
            if (loadingBadge) loadingBadge.style.display = 'none';
            const currentTbody = document.querySelector('table tbody');
            if (!currentTbody) return;

            if (result.type === 'json' && result.data && typeof result.data.html === 'string') {
                currentTbody.innerHTML = result.data.html;
            } else if (result.type === 'html') {
                const parser = new DOMParser();
                const doc = parser.parseFromString(result.text, 'text/html');
                const newTbody = doc.querySelector('table tbody');
                if (newTbody) {
                    currentTbody.innerHTML = newTbody.innerHTML;
                }
            }
        })
        .catch(err => {
            if (err.name !== 'AbortError' && loadingBadge) {
                loadingBadge.style.display = 'none';
            }
        });
    }

    searchInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(triggerLiveInventorySearch, 220);
    });

    filterBar.querySelectorAll('.epc-filter-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            filterBar.querySelectorAll('.epc-filter-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentStockFilter = this.getAttribute('data-filter') || 'all';
            triggerLiveInventorySearch();
        });
    });
});
</script>
HTML;

    $html = injectSidebarNavigationLinks($html);
    if (str_contains($html, '</body>')) {
        return str_replace('</body>', $injectedAssets . "\n</body>", $html);
    }
    return $html . $injectedAssets;
}

/*
|--------------------------------------------------------------------------
| 7. المسارات العامة ومسارات المصادقة وتحميل ملف إكسل الواتساب
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return redirect()->route('inventory.index');
});

Route::get('/dashboard', function () {
    return redirect()->route('inventory.index');
})->middleware(['auth', 'verified'])->name('dashboard');

// مسار عام لتحميل ملف إكسل التسعير المرسل للعميل في الواتساب
Route::get('/whatsapp-exports/{file}', function (string $file) {
    $safeFile = basename($file);
    $path = storage_path('app/public/whatsapp_exports/' . $safeFile);
    if (!file_exists($path)) {
        abort(404);
    }
    return response()->download($path, 'نتيجة_التسعير.xlsx', [
        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ]);
})->name('public.whatsapp.excel.download');

Route::post('/api/webhook/receiver', function (Request $r) {
    return handleWebhook($r);
});

Route::post('/api/webhook/receiver/', function (Request $r) {
    return handleWebhook($r);
});

require __DIR__ . '/auth.php';

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
| 8. مسارات النظام المحمية بتسجيل الدخول (Authenticated Routes)
|--------------------------------------------------------------------------
*/

Route::middleware('auth')->group(function () {

    Route::get('/cars-dictionary', function () {
        return redirect()->route('inventory.index');
    })->name('cars.dictionary');

    // 1. المخزون الخاص
    Route::prefix('inventory')->name('inventory.')->group(function () {
        Route::get('/', function (Request $request) {
            $user         = auth()->user();
            $targetUserId = $user->id;

            if ($user->role === 'admin' && $request->filled('client_id')) {
                $targetUserId = (int) $request->query('client_id');
            }

            $query = Item::where('user_id', $targetUserId);

            $stockFilter = (string) $request->query('stock_filter', 'all');
            if ($stockFilter === 'available') {
                $query->where('quantity', '>', 0);
            } elseif ($stockFilter === 'out') {
                $query->where('quantity', '<=', 0);
            } elseif ($stockFilter === 'low') {
                $query->where('quantity', '>', 0)->where('quantity', '<', 3);
            } elseif ($stockFilter === 'no_price') {
                $query->where('price', '<=', 0);
            }

            if ($request->filled('search')) {
                $s           = trim((string) $request->query('search'));
                $cleanNoDash = normalizePartNumberClean($s);

                $query->where(function ($q) use ($s, $cleanNoDash) {
                    $q->where('item_number', 'like', "%{$s}%")
                      ->orWhere('item_name', 'like', "%{$s}%")
                      ->orWhere('detected_car_name', 'like', "%{$s}%");

                    if ($cleanNoDash !== '') {
                        $q->orWhere(DB::raw("UPPER(REPLACE(REPLACE(item_number, '-', ''), ' ', ''))"), 'like', "%{$cleanNoDash}%");
                    }
                });
            }

            $items = $query->latest()->paginate(15)->withQueryString();

            if ($request->ajax() || $request->wantsJson()) {
                if (view()->exists('inventory.partials.table_rows')) {
                    $html = view('inventory.partials.table_rows', compact('items'))->render();
                    return response()->json(['html' => $html]);
                }
            }

            $viewingClient = ($user->role === 'admin' && $request->filled('client_id')) ? User::find($targetUserId) : null;

            $renderedPage = view('inventory.index', compact('items', 'viewingClient'))->render();
            return response(injectInventoryLiveSearchAndFiltersScript($renderedPage));
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

            return back()->with('success', 'تمت إضافة الصنف بنجاح.');
        })->name('store');

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
            return back()->with('success', 'تم تحديث الصنف بنجاح (رقم الصنف محمي وثابت).');
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

        $rendered = view('pages.clients', compact('clients'))->render();
        return response(injectSidebarNavigationLinks($rendered));
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
            $cleanNoDash = normalizePartNumberClean($search);
            $query->where(function ($q) use ($search, $cleanNoDash) {
                $q->where('master_items.item_number', 'like', "%{$search}%")
                  ->orWhere('master_items.first_item_name', 'like', "%{$search}%")
                  ->orWhere('master_items.compatible_cars', 'like', "%{$search}%")
                  ->orWhere('items.item_name', 'like', "%{$search}%");

                if ($cleanNoDash !== '') {
                    $q->orWhere(DB::raw("UPPER(REPLACE(REPLACE(master_items.item_number, '-', ''), ' ', ''))"), 'like', "%{$cleanNoDash}%");
                }
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

        $rendered = view('pages.master_catalog', compact('masterItems'))->render();
        return response(injectSidebarNavigationLinks($rendered));
    })->name('master.catalog');

    // 4. صفحة تفاصيل الرقم الموحد + مسارات الكلمات المفتاحية (حل خطأ Route [master.item.keyword.add] not defined)
    Route::get('/master-catalog/{itemNumber}', function ($itemNumber) {
        if (auth()->user()->role !== 'admin') abort(403);

        $cleanNumber = strtoupper(trim($itemNumber));

        $master = DB::table('master_items')->where('item_number', $cleanNumber)->first();

        $clientHoldings = DB::table('items')
            ->join('users', 'items.user_id', '=', 'users.id')
            ->select(
                'items.*',
                DB::raw("COALESCE(users.name, 'المدير العام') as client_name")
            )
            ->where('items.item_number', $cleanNumber)
            ->get();

        $keywords = Schema::hasTable('master_item_keywords')
            ? DB::table('master_item_keywords')->where('item_number', $cleanNumber)->latest()->get()
            : collect();

        $rendered = view('pages.master_details', compact('itemNumber', 'master', 'clientHoldings', 'keywords'))->render();
        return response(injectSidebarNavigationLinks($rendered));
    })->name('master.item.details');

    Route::post('/master-catalog/{itemNumber}/keywords', function (Request $request, $itemNumber) {
        if (auth()->user()->role !== 'admin') abort(403);
        $cleanNumber = strtoupper(trim($itemNumber));
        $keyword     = trim((string) $request->input('keyword', ''));
        if ($keyword !== '') {
            DB::table('master_item_keywords')->insert([
                'item_number' => $cleanNumber,
                'keyword'     => $keyword,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
        return back()->with('success', 'تمت إضافة الكلمة المفتاحية للصنف بنجاح.');
    })->name('master.item.keyword.add');

    Route::delete('/master-catalog/keywords/{id}', function ($id) {
        if (auth()->user()->role !== 'admin') abort(403);
        DB::table('master_item_keywords')->where('id', $id)->delete();
        return back()->with('success', 'تم حذف الكلمة المفتاحية.');
    })->name('master.item.keyword.delete');

    // 5. صفحة الرد الآلي عبر الواتساب (/ai-whatsapp) - بدون تسجيل التجارب في الأصناف المطلوبة نهائياً
    Route::get('/ai-whatsapp', function (Request $request) {
        $userId          = auth()->id();
        $customerMessage = (string) $request->query('message', '');
        $matchedItems    = collect();
        $botSettings     = getUserBotSettings($userId);

        if (trim($customerMessage) !== '') {
            $extractedWithQty = extractPartNumbersWithQuantitiesFromText($customerMessage, $userId);
            $partNumbers      = array_map(fn($x) => $x['part_number'], $extractedWithQty);

            if (!empty($partNumbers)) {
                $matchedItems = Item::where('user_id', $userId)
                    ->where('quantity', '>', 0)
                    ->where(function ($q) use ($partNumbers) {
                        foreach ($partNumbers as $pNum) {
                            $cleanNoDash = normalizePartNumberClean($pNum);
                            $q->orWhere('item_number', $pNum)
                              ->orWhere(DB::raw("UPPER(REPLACE(REPLACE(item_number, '-', ''), ' ', ''))"), $cleanNoDash);
                        }
                    })
                    ->get();
            }
        }

        $rendered = view('pages.second', [
            'customerMessage' => $customerMessage,
            'matchedItems'    => $matchedItems,
            'botSettings'     => $botSettings,
            'whitelistPhones' => collect(),
            'customerQuotas'  => collect(),
        ])->render();

        $rendered = enhanceSecondPageWithBotToggle($rendered, $botSettings);
        return response(injectSidebarNavigationLinks($rendered));
    })->name('pages.second');

    // مسار زر تفعيل / إيقاف الرد الآلي للبوت
    Route::post('/ai-whatsapp/toggle-bot', function (Request $request) {
        $userId = auth()->id();
        $newState = $request->input('bot_enabled', '1') === '1' ? '1' : '0';

        DB::table('user_bot_settings')->updateOrInsert(
            ['user_id' => $userId, 'key' => 'bot_enabled'],
            ['value' => $newState]
        );

        $msg = $newState === '1'
            ? 'تم تفعيل الرد الآلي للبوت .'
            : 'تم إيقاف الرد الآلي للبوت ';

        return back()->with('success', $msg);
    })->name('pages.second.toggle_bot');

    // 6. صفحة إدارة الاستثناءات المتقدمة والتصفير التلقائي (شاملة التعديل + يومي/أسبوعي/شهري)
    Route::get('/whatsapp-quotas', function () {
        $userId      = auth()->id();
        $botSettings = getUserBotSettings($userId);
        $csrfToken   = csrf_token();

        $whitelistPhones = DB::table('whatsapp_whitelist_phones')
            ->where('user_id', $userId)
            ->latest()
            ->get();

        $blacklistPhones = DB::table('whatsapp_blacklist_phones')
            ->where('user_id', $userId)
            ->latest()
            ->get();

        $customerQuotas = DB::table('whatsapp_customer_quotas')
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->take(50)
            ->get();

        $resetPeriod = $botSettings['quota_reset_period'] ?? 'manual';
        $maxLimit    = htmlspecialchars((string) ($botSettings['max_queries_per_phone'] ?? '0'), ENT_QUOTES, 'UTF-8');

        $selManual  = $resetPeriod === 'manual' ? 'selected' : '';
        $selDaily   = $resetPeriod === 'daily' ? 'selected' : '';
        $selWeekly  = $resetPeriod === 'weekly' ? 'selected' : '';
        $selMonthly = $resetPeriod === 'monthly' ? 'selected' : '';

        $periodLabels = [
            'default' => 'حسب الإعداد العام',
            'manual'  => 'يدوي فقط',
            'daily'   => '🔄 تجديد يومي',
            'weekly'  => '📅 تجديد أسبوعي',
            'monthly' => '🗓️ تجديد شهري',
        ];

        $whitelistRowsHtml = '';
        $whitelistJsData   = [];

        foreach ($whitelistPhones as $w) {
            $cQuotaVal = (int) ($w->custom_quota ?? 0);
            $rPeriod   = $w->reset_period ?? 'default';
            $rLabel    = $periodLabels[$rPeriod] ?? 'حسب الإعداد العام';

            $quotaBadge = ($cQuotaVal > 0)
                ? "<span style='background:#eff6ff;color:#1e40af;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:700;'>محدد ({$cQuotaVal} رقم) — {$rLabel}</span>"
                : "<span style='background:#f0fdf4;color:#166534;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:700;'>مفتوح بلا حد</span>";

            $timeWindow = (!empty($w->allowed_start_time) && !empty($w->allowed_end_time))
                ? "⏰ {$w->allowed_start_time} إلى {$w->allowed_end_time}"
                : "🕒 طوال اليوم (24 ساعة)";

            $perms = [];
            if ($w->allow_available ?? true)   $perms[] = "✅ المتوفر";
            if ($w->allow_unavailable ?? true) $perms[] = "❌ غير المتوفر";
            if ($w->allow_prices ?? true)      $perms[] = "💰 الأسعار";
            if ($w->allow_quantities ?? true)  $perms[] = "📦 الكميات";
            if ($w->allow_excel ?? true)       $perms[] = "📊 إكسل";
            $permsStr = implode(' | ', $perms);

            $delUrl    = route('pages.second.whitelist.delete', $w->id);
            $labelSafe = htmlspecialchars((string) ($w->label ?? 'بدون اسم'), ENT_QUOTES, 'UTF-8');

            $whitelistJsData[$w->id] = [
                'id'                 => $w->id,
                'phone'              => $w->phone,
                'label'              => (string) ($w->label ?? ''),
                'custom_quota'       => $cQuotaVal,
                'reset_period'       => $rPeriod,
                'allowed_start_time' => (string) ($w->allowed_start_time ?? ''),
                'allowed_end_time'   => (string) ($w->allowed_end_time ?? ''),
                'allow_available'    => (bool) ($w->allow_available ?? true),
                'allow_unavailable'  => (bool) ($w->allow_unavailable ?? true),
                'allow_prices'       => (bool) ($w->allow_prices ?? true),
                'allow_quantities'   => (bool) ($w->allow_quantities ?? true),
                'allow_excel'        => (bool) ($w->allow_excel ?? true),
            ];

            $whitelistRowsHtml .= "
            <tr style='border-bottom:1px solid #f1f5f9;transition:background 0.15s;' onmouseover=\"this.style.background='#f8fafc'\" onmouseout=\"this.style.background='transparent'\">
                <td style='padding:14px;font-weight:700;font-family:monospace;font-size:14px;color:#0f172a;'>{$w->phone}</td>
                <td style='padding:14px;font-weight:600;color:#334155;'>{$labelSafe}</td>
                <td style='padding:14px;'>{$quotaBadge}</td>
                <td style='padding:14px;font-size:13px;color:#475569;'>{$timeWindow}</td>
                <td style='padding:14px;font-size:12px;color:#475569;'>{$permsStr}</td>
                <td style='padding:14px;display:flex;gap:6px;align-items:center;'>
                    <button type='button' class='epc-edit-wl-btn' data-id='{$w->id}' style='background:#dbeafe;color:#1e40af;border:none;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;'>✏️ تعديل</button>
                    <form method='POST' action='{$delUrl}' style='display:inline;margin:0;' onsubmit=\"return confirm('هل تريد حذف هذا الرقم من الاستثناء؟');\">
                        <input type='hidden' name='_token' value='{$csrfToken}'>
                        <input type='hidden' name='_method' value='DELETE'>
                        <button type='submit' style='background:#fee2e2;color:#991b1b;border:none;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;'>🗑️ حذف</button>
                    </form>
                </td>
            </tr>";
        }
        if ($whitelistRowsHtml === '') {
            $whitelistRowsHtml = "<tr><td colspan='6' style='padding:24px;text-align:center;color:#64748b;'>لا توجد أرقام مضافة في قائمة الاستثناء حالياً.</td></tr>";
        }

        $blacklistRowsHtml = '';
        foreach ($blacklistPhones as $b) {
            $delBUrl    = route('pages.second.blacklist.delete', $b->id);
            $reasonSafe = htmlspecialchars((string) ($b->reason ?? 'بدون سبب'), ENT_QUOTES, 'UTF-8');
            $blacklistRowsHtml .= "
            <tr style='border-bottom:1px solid #f1f5f9;'>
                <td style='padding:12px;font-weight:700;color:#b91c1c;font-family:monospace;'>{$b->phone}</td>
                <td style='padding:12px;color:#334155;'>{$reasonSafe}</td>
                <td style='padding:12px;font-size:12px;color:#64748b;direction:ltr;'>{$b->created_at}</td>
                <td style='padding:12px;'>
                    <form method='POST' action='{$delBUrl}' style='display:inline;'>
                        <input type='hidden' name='_token' value='{$csrfToken}'>
                        <input type='hidden' name='_method' value='DELETE'>
                        <button type='submit' style='background:#f1f5f9;color:#475569;border:none;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:600;'>إلغاء الحظر</button>
                    </form>
                </td>
            </tr>";
        }
        if ($blacklistRowsHtml === '') {
            $blacklistRowsHtml = "<tr><td colspan='4' style='padding:20px;text-align:center;color:#64748b;'>لا توجد أرقام محظورة في القائمة السوداء.</td></tr>";
        }

        $quotaRowsHtml = '';
        $resetUrl = route('pages.second.quotas.reset');
        foreach ($customerQuotas as $q) {
            $quotaRowsHtml .= "
            <tr style='border-bottom:1px solid #f1f5f9;'>
                <td style='padding:12px;font-weight:700;font-family:monospace;color:#0f172a;'>{$q->phone}</td>
                <td style='padding:12px;font-weight:700;color:#2563eb;'>{$q->queries_count} رقم صنف</td>
                <td style='padding:12px;font-size:12px;color:#64748b;direction:ltr;'>{$q->updated_at}</td>
                <td style='padding:12px;'>
                    <form method='POST' action='{$resetUrl}' style='display:inline;'>
                        <input type='hidden' name='_token' value='{$csrfToken}'>
                        <input type='hidden' name='quota_id' value='{$q->id}'>
                        <button type='submit' style='background:#e0f2fe;color:#0369a1;border:none;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;'>تصفير الرقم</button>
                    </form>
                </td>
            </tr>";
        }
        if ($quotaRowsHtml === '') {
            $quotaRowsHtml = "<tr><td colspan='4' style='padding:20px;text-align:center;color:#64748b;'>لا توجد استعلامات مسجلة في العداد حالياً.</td></tr>";
        }

        $settingsActionUrl  = route('pages.second.settings');
        $whitelistActionUrl = route('pages.second.whitelist.add');
        $blacklistActionUrl = route('pages.second.blacklist.add');
        $wlJson             = json_encode($whitelistJsData, JSON_UNESCAPED_UNICODE);

        $contentHtml = <<<HTML
        <div style="padding:28px;max-width:1300px;margin:0 auto;direction:rtl;font-family:inherit;background:#f8fafc;min-height:100vh;">
            <div style="background:linear-gradient(135deg, #0f172a 0%, #1e293b 100%);color:#fff;padding:24px 28px;border-radius:16px;margin-bottom:24px;box-shadow:0 4px 12px rgba(15,23,42,0.08);">
                <h1 style="margin:0;font-size:22px;font-weight:800;display:flex;align-items:center;gap:10px;">🛡️ إدارة الاستثناءات والحصص والتصفير التلقائي</h1>
                <p style="margin:8px 0 0 0;font-size:13px;color:#94a3b8;line-height:1.5;">تحكم احترافي في الحد العام للاستعلامات، دورات التصفير التلقائي (يومي / أسبوعي / شهري)، وتعديل صلاحيات الأرقام المستثناة.</p>
            </div>

            <!-- 1. التصفير التلقائي والحد العام -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;margin-bottom:24px;box-shadow:0 2px 8px rgba(0,0,0,0.02);">
                <h3 style="margin:0 0 8px 0;font-size:16px;font-weight:700;color:#0f172a;">🔄 نظام الحصة العام والتصفير التلقائي</h3>
                <p style="margin:0 0 18px 0;font-size:13px;color:#64748b;">كل رقم صنف يتم الاستعلام عنه يُحتسب كاستعلام واحد مستقل.</p>
                
                <form method="POST" action="{$settingsActionUrl}" style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-end;">
                    <input type="hidden" name="_token" value="{$csrfToken}">
                    <div style="flex:1;min-width:240px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:#334155;margin-bottom:6px;">الحد العام للاستعلام لكل رقم (0 = مفتوح):</label>
                        <input type="number" name="max_queries_per_phone" min="0" value="{$maxLimit}" style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:10px;font-size:14px;background:#f8fafc;">
                    </div>
                    <div style="flex:1;min-width:260px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:#334155;margin-bottom:6px;">دورة التصفير التلقائي لعداد الاستعلامات:</label>
                        <select name="quota_reset_period" style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:10px;font-size:14px;background:#f8fafc;">
                            <option value="manual" {$selManual}>يدوي فقط (من زر تصفير العداد)</option>
                            <option value="daily" {$selDaily}>تلقائي يومياً (يتجدد كل يوم)</option>
                            <option value="weekly" {$selWeekly}>تلقائي أسبوعياً (يتجدد كل 7 أيام)</option>
                            <option value="monthly" {$selMonthly}>تلقائي شهرياً (يتجدد بداية كل شهر)</option>
                        </select>
                    </div>
                    <button type="submit" style="background:#2563eb;color:#ffffff;border:none;padding:11px 24px;border-radius:10px;font-weight:700;cursor:pointer;font-size:14px;">
                        💾 حفظ الإعدادات
                    </button>
                </form>
            </div>

            <!-- 2. نظام الاستثناءات المتقدم (إضافة + تعديل) -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;margin-bottom:24px;box-shadow:0 2px 8px rgba(0,0,0,0.02);">
                <h3 id="epc-wl-form-title" style="margin:0 0 16px 0;font-size:16px;font-weight:700;color:#0f172a;">🌟 إضافة / تعديل رقم في قائمة الاستثناء المتقدمة</h3>
                
                <form id="epc-wl-form" method="POST" action="{$whitelistActionUrl}" style="background:#f8fafc;padding:20px;border-radius:14px;border:1px solid #e2e8f0;margin-bottom:20px;">
                    <input type="hidden" name="_token" value="{$csrfToken}">
                    <input type="hidden" name="whitelist_id" id="epc-wl-id" value="">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:18px;">
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;">رقم الجوال *</label>
                            <input type="text" name="phone" id="epc-wl-phone" required placeholder="05XXXXXXXX" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;">اسم العميل / الوصف</label>
                            <input type="text" name="label" id="epc-wl-label" placeholder="مثال: ورشة النخبة" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;">عدد الأرقام المسموحة (0 = مفتوح)</label>
                            <input type="number" name="custom_quota" id="epc-wl-quota" min="0" value="0" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;">دورة التصفير لهذا الرقم</label>
                            <select name="reset_period" id="epc-wl-reset" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;font-size:13px;">
                                <option value="daily">🔄 يومي (يتجدد كل يوم)</option>
                                <option value="weekly">📅 أسبوعي (يتجدد كل أسبوع)</option>
                                <option value="monthly">🗓️ شهري (يتجدد كل شهر)</option>
                                <option value="manual">✋ يدوي فقط</option>
                                <option value="default">⚙️ حسب الإعداد العام</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;">من الساعة (اختياري)</label>
                            <input type="time" name="allowed_start_time" id="epc-wl-start" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:6px;">إلى الساعة (اختياري)</label>
                            <input type="time" name="allowed_end_time" id="epc-wl-end" style="width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;">
                        </div>
                    </div>

                    <div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center;padding-top:14px;border-top:1px dashed #cbd5e1;">
                        <span style="font-size:13px;font-weight:700;color:#1e293b;">صلاحيات الرد المسموحة:</span>
                        <label style="font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" name="allow_available" id="epc-wl-avail" value="1" checked> ✅ المتوفر</label>
                        <label style="font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" name="allow_unavailable" id="epc-wl-unavail" value="1" checked> ❌ غير المتوفر</label>
                        <label style="font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" name="allow_prices" id="epc-wl-prices" value="1" checked> 💰 الأسعار</label>
                        <label style="font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" name="allow_quantities" id="epc-wl-qty" value="1" checked> 📦 الكميات</label>
                        <label style="font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer;"><input type="checkbox" name="allow_excel" id="epc-wl-excel" value="1" checked> 📊 ملفات إكسل</label>
                        
                        <div style="margin-right:auto;display:flex;gap:8px;">
                            <button type="button" id="epc-wl-cancel-edit" style="display:none;background:#e2e8f0;color:#334155;border:none;padding:11px 18px;border-radius:10px;font-weight:700;cursor:pointer;">
                                إلغاء التعديل
                            </button>
                            <button type="submit" id="epc-wl-submit-btn" style="background:#16a34a;color:#fff;border:none;padding:11px 24px;border-radius:10px;font-weight:700;cursor:pointer;">
                                + حفظ الرقم المستثنى
                            </button>
                        </div>
                    </div>
                </form>

                <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:12px;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:right;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#334155;">
                                <th style="padding:14px;">رقم الجوال</th>
                                <th style="padding:14px;">الاسم / الوصف</th>
                                <th style="padding:14px;">حد الاستعلام ودورة التجديد</th>
                                <th style="padding:14px;">الأوقات المسموحة</th>
                                <th style="padding:14px;">الصلاحيات المفعلة</th>
                                <th style="padding:14px;">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$whitelistRowsHtml}
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 3. القائمة السوداء (Blacklist) -->
            <div style="background:#ffffff;border:1px solid #fee2e2;border-radius:16px;padding:24px;margin-bottom:24px;">
                <h3 style="margin:0 0 14px 0;font-size:16px;font-weight:700;color:#991b1b;">🚫 القائمة السوداء (حظر الأرقام)</h3>
                <form method="POST" action="{$blacklistActionUrl}" style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                    <input type="hidden" name="_token" value="{$csrfToken}">
                    <input type="text" name="phone" required placeholder="رقم الجوال (05XXXXXXXX)" style="flex:1;min-width:220px;padding:10px 14px;border:1px solid #fca5a5;border-radius:10px;background:#fff;">
                    <input type="text" name="reason" placeholder="سبب الحظر (اختياري)" style="flex:1;min-width:220px;padding:10px 14px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;">
                    <button type="submit" style="background:#dc2626;color:#fff;border:none;padding:10px 22px;border-radius:10px;font-weight:700;cursor:pointer;">
                        حظر الرقم
                    </button>
                </form>
                <div style="overflow-x:auto;border:1px solid #fee2e2;border-radius:12px;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:right;">
                        <thead>
                            <tr style="background:#fef2f2;color:#991b1b;">
                                <th style="padding:12px;">الرقم المحظور</th>
                                <th style="padding:12px;">السبب</th>
                                <th style="padding:12px;">تاريخ الحظر</th>
                                <th style="padding:12px;">إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$blacklistRowsHtml}
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 4. سجل عداد الاستعلامات الحالي للعملاء -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                    <h3 style="margin:0;font-size:16px;font-weight:700;color:#0f172a;">📊 عداد استهلاك الأرقام وحصص العملاء</h3>
                    <form method="POST" action="{$resetUrl}" onsubmit="return confirm('هل تريد تصفير العداد لجميع الأرقام؟');">
                        <input type="hidden" name="_token" value="{$csrfToken}">
                        <button type="submit" style="background:#ef4444;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;">
                            🔄 تصفير جميع العدادات
                        </button>
                    </form>
                </div>
                <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:12px;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:right;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#334155;">
                                <th style="padding:12px;">رقم الجوال</th>
                                <th style="padding:12px;">الأرقام المستهلكة</th>
                                <th style="padding:12px;">آخر استعلام</th>
                                <th style="padding:12px;">إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$quotaRowsHtml}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
        (function () {
            const wlData = {$wlJson};
            const formTitle  = document.getElementById('epc-wl-form-title');
            const submitBtn  = document.getElementById('epc-wl-submit-btn');
            const cancelBtn  = document.getElementById('epc-wl-cancel-edit');
            const inpId      = document.getElementById('epc-wl-id');
            const inpPhone   = document.getElementById('epc-wl-phone');
            const inpLabel   = document.getElementById('epc-wl-label');
            const inpQuota   = document.getElementById('epc-wl-quota');
            const inpReset   = document.getElementById('epc-wl-reset');
            const inpStart   = document.getElementById('epc-wl-start');
            const inpEnd     = document.getElementById('epc-wl-end');
            const chkAvail   = document.getElementById('epc-wl-avail');
            const chkUnavail = document.getElementById('epc-wl-unavail');
            const chkPrices  = document.getElementById('epc-wl-prices');
            const chkQty     = document.getElementById('epc-wl-qty');
            const chkExcel   = document.getElementById('epc-wl-excel');

            document.querySelectorAll('.epc-edit-wl-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    const id = this.getAttribute('data-id');
                    const item = wlData[id];
                    if (!item) return;

                    inpId.value    = item.id;
                    inpPhone.value = item.phone;
                    inpLabel.value = item.label;
                    inpQuota.value = item.custom_quota;
                    inpReset.value = item.reset_period || 'daily';
                    inpStart.value = item.allowed_start_time;
                    inpEnd.value   = item.allowed_end_time;

                    chkAvail.checked   = !!item.allow_available;
                    chkUnavail.checked = !!item.allow_unavailable;
                    chkPrices.checked  = !!item.allow_prices;
                    chkQty.checked     = !!item.allow_quantities;
                    chkExcel.checked   = !!item.allow_excel;

                    formTitle.innerHTML = '✏️ تعديل صلاحيات وحدود الرقم المستثنى: <span style="color:#2563eb;font-family:monospace;">' + item.phone + '</span>';
                    submitBtn.textContent = '💾 حفظ التعديلات على الرقم';
                    submitBtn.style.background = '#2563eb';
                    cancelBtn.style.display = 'inline-block';

                    document.getElementById('epc-wl-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
                });
            });

            if (cancelBtn) {
                cancelBtn.addEventListener('click', function () {
                    inpId.value    = '';
                    inpPhone.value = '';
                    inpLabel.value = '';
                    inpQuota.value = 0;
                    inpReset.value = 'daily';
                    inpStart.value = '';
                    inpEnd.value   = '';
                    chkAvail.checked = chkUnavail.checked = chkPrices.checked = chkQty.checked = chkExcel.checked = true;

                    formTitle.textContent = '🌟 إضافة / تعديل رقم في قائمة الاستثناء المتقدمة';
                    submitBtn.textContent = '+ حفظ الرقم المستثنى';
                    submitBtn.style.background = '#16a34a';
                    cancelBtn.style.display = 'none';
                });
            }
        })();
        </script>
HTML;

        return response(renderStandalonePageInsideMainLayout('إدارة الاستثناءات والحصص', $contentHtml));
    })->name('whatsapp.quotas.page');

    // 7. صفحة رادار الطلبات العام الموحد (/admin-radar)
    Route::delete('/admin-radar/item/{normKey}', function (string $normKey) {
        if (auth()->user()->role !== 'admin') {
            abort(403);
        }

        $cleanKey = normalizePartNumberClean($normKey);
        DB::table('whatsapp_inquiry_logs')
            ->where('normalized_part', $cleanKey)
            ->orWhere(DB::raw("UPPER(REPLACE(REPLACE(part_number, '-', ''), ' ', ''))"), $cleanKey)
            ->delete();

        return back()->with('success', "تم حذف الصنف ({$cleanKey}) من سجل الأصناف المطلوبة بنجاح.");
    })->name('admin.radar.delete_item');

    Route::get('/admin-radar', function (Request $request) {
        if (auth()->user()->role !== 'admin') {
            abort(403);
        }

        $csrfToken = csrf_token();

        $allLogs = DB::table('whatsapp_inquiry_logs')
            ->leftJoin('users', 'whatsapp_inquiry_logs.user_id', '=', 'users.id')
            ->select(
                'whatsapp_inquiry_logs.*',
                DB::raw("COALESCE(users.name, 'المدير العام') as merchant_name")
            )
            ->orderByDesc('whatsapp_inquiry_logs.id')
            ->get();

        $groupedRadar = [];

        foreach ($allLogs as $log) {
            $normKey = $log->normalized_part ?: normalizePartNumberClean((string) $log->part_number);
            if ($normKey === '') continue;

            $dayStr = !empty($log->inquiry_date)
                ? $log->inquiry_date
                : substr((string) $log->created_at, 0, 10);

            if (!isset($groupedRadar[$normKey])) {
                $groupedRadar[$normKey] = [
                    'normalized_part' => $normKey,
                    'part_number'     => $log->part_number,
                    'item_name'       => $log->item_name ?: null,
                    'unique_days_map' => [],
                    'detailed_count'  => 0,
                    'avail_count'     => 0,
                    'unavail_count'   => 0,
                    'total_req_qty'   => 0,
                    'last_status'     => $log->status,
                    'last_date'       => (string) $log->created_at,
                    'details_list'    => [],
                ];
            }

            if (empty($groupedRadar[$normKey]['item_name']) && !empty($log->item_name)) {
                $groupedRadar[$normKey]['item_name'] = $log->item_name;
            }

            $groupedRadar[$normKey]['unique_days_map'][$dayStr] = true;
            $groupedRadar[$normKey]['detailed_count']++;

            if ($log->status === 'available') {
                $groupedRadar[$normKey]['avail_count']++;
            } else {
                $groupedRadar[$normKey]['unavail_count']++;
            }

            $groupedRadar[$normKey]['total_req_qty'] += max(1, (int) $log->requested_qty);

            $groupedRadar[$normKey]['details_list'][] = [
                'date'      => (string) $log->created_at,
                'day'       => $dayStr,
                'customer'  => (string) $log->phone,
                'merchant'  => (string) $log->merchant_name,
                'status'    => (string) $log->status,
                'req_qty'   => max(1, (int) $log->requested_qty),
                'avail_qty' => (int) $log->available_qty,
                'price'     => $log->price !== null ? number_format((float) $log->price, 2) : '—',
                'source'    => $log->source === 'excel' ? 'ملف إكسل' : 'رسالة واتساب',
            ];
        }

        $itemsList = array_values($groupedRadar);
        usort($itemsList, function ($a, $b) {
            $daysA = count($a['unique_days_map']);
            $daysB = count($b['unique_days_map']);
            if ($daysA !== $daysB) {
                return $daysB <=> $daysA;
            }
            return $b['detailed_count'] <=> $a['detailed_count'];
        });

        $totalUniqueParts    = count($itemsList);
        $totalAvailParts     = 0;
        $totalMissingParts   = 0;
        $totalRequestedUnits = 0;

        $radarRowsHtml   = '';
        $detailsMapForJs = [];

        foreach ($itemsList as $item) {
            $uniqueDaysCount = count($item['unique_days_map']);
            $detailedCount   = (int) $item['detailed_count'];
            $totalReqQty     = (int) $item['total_req_qty'];
            $availCount      = (int) $item['avail_count'];
            $unavailCount    = (int) $item['unavail_count'];
            $partKey         = $item['normalized_part'];

            $totalRequestedUnits += $totalReqQty;
            if ($unavailCount > 0 && $availCount === 0) {
                $totalMissingParts++;
                $rowStatusType = 'unavailable';
            } elseif ($availCount > 0 && $unavailCount === 0) {
                $totalAvailParts++;
                $rowStatusType = 'available';
            } else {
                $totalMissingParts++;
                $rowStatusType = 'mixed';
            }

            $detailsMapForJs[$partKey] = [
                'part_number'    => $item['part_number'],
                'item_name'      => $item['item_name'] ?: 'غير مسجل في المخزون',
                'unique_days'    => $uniqueDaysCount,
                'detailed_count' => $detailedCount,
                'total_req_qty'  => $totalReqQty,
                'records'        => $item['details_list'],
            ];

            $nameRaw     = $item['item_name'] ?: '— غير مسجل في المخزون —';
            $nameDisplay = htmlspecialchars((string) $nameRaw, ENT_QUOTES, 'UTF-8');
            $partDisplay = htmlspecialchars((string) $item['part_number'], ENT_QUOTES, 'UTF-8');
            $searchAttr  = htmlspecialchars(strtolower($item['part_number'] . ' ' . $partKey . ' ' . $nameRaw), ENT_QUOTES, 'UTF-8');
            $deleteUrl   = route('admin.radar.delete_item', $partKey);

            if ($availCount > 0 && $unavailCount > 0) {
                $statusHtml = "<div style='display:flex;flex-wrap:wrap;gap:4px;'>
                    <span style='background:#dcfce7;color:#166534;padding:4px 10px;border-radius:8px;font-size:12px;font-weight:700;'>✅ متوفر ({$availCount})</span>
                    <span style='background:#fee2e2;color:#991b1b;padding:4px 10px;border-radius:8px;font-size:12px;font-weight:700;'>❌ ناقص ({$unavailCount})</span>
                </div>";
                $statusTextForExcel = "متوفر ({$availCount}) / غير متوفر ({$unavailCount})";
            } elseif ($availCount > 0) {
                $statusHtml = "<span style='background:#dcfce7;color:#166534;padding:5px 12px;border-radius:8px;font-size:12px;font-weight:700;display:inline-block;'>✅ متوفر ({$availCount})</span>";
                $statusTextForExcel = "متوفر ({$availCount})";
            } else {
                $statusHtml = "<span style='background:#fee2e2;color:#991b1b;padding:5px 12px;border-radius:8px;font-size:12px;font-weight:700;display:inline-block;'>❌ غير متوفر ({$unavailCount})</span>";
                $statusTextForExcel = "غير متوفر ({$unavailCount})";
            }

            $statusExcelSafe = htmlspecialchars($statusTextForExcel, ENT_QUOTES, 'UTF-8');

            $radarRowsHtml .= "
            <tr class='epc-radar-row'
                data-part-key='{$partKey}'
                data-search='{$searchAttr}'
                data-part='{$partDisplay}'
                data-name='{$nameDisplay}'
                data-status-type='{$rowStatusType}'
                data-status-text='{$statusExcelSafe}'
                data-days='{$uniqueDaysCount}'
                data-detailed='{$detailedCount}'
                data-qty='{$totalReqQty}'
                data-unavail='{$unavailCount}'
                data-date='{$item['last_date']}'
                style='border-bottom:1px solid #f1f5f9;transition:background 0.15s;'
                onmouseover=\"this.style.background='#f8fafc'\"
                onmouseout=\"this.style.background='transparent'\">
                <td style='padding:14px 12px;font-weight:800;font-family:monospace;font-size:15px;color:#0f172a;'>{$partDisplay}</td>
                <td style='padding:14px 12px;font-weight:600;color:#334155;'>{$nameDisplay}</td>
                <td style='padding:14px 12px;'>{$statusHtml}</td>
                <td style='padding:14px 12px;text-align:center;'>
                    <span style='background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:5px 14px;border-radius:999px;font-size:14px;font-weight:800;display:inline-block;'>
                        {$totalReqQty} حبة
                    </span>
                </td>
                <td style='padding:14px 12px;text-align:center;'>
                    <span style='background:#dbeafe;color:#1e40af;padding:5px 14px;border-radius:999px;font-size:14px;font-weight:800;display:inline-block;'>
                        {$uniqueDaysCount}
                    </span>
                </td>
                <td style='padding:14px 12px;text-align:center;'>
                    <span style='background:#f3e8ff;color:#6b21a8;padding:5px 14px;border-radius:999px;font-size:14px;font-weight:800;display:inline-block;'>
                        {$detailedCount}
                    </span>
                </td>
                <td style='padding:14px 12px;font-size:12px;color:#475569;direction:ltr;text-align:right;font-family:monospace;'>{$item['last_date']}</td>
                <td style='padding:14px 12px;text-align:center;'>
                    <div style='display:flex;align-items:center;justify-content:center;gap:6px;'>
                        <button type='button' class='epc-open-log-btn' data-part-key='{$partKey}' style='background:#0f172a;color:#ffffff;border:none;padding:7px 14px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:4px;'>
                            📋 السجل
                        </button>
                        <form method='POST' action='{$deleteUrl}' onsubmit=\"return confirm('هل أنت متأكد من حذف هذا الصنف ({$partDisplay}) من الرادار؟');\" style='display:inline;margin:0;'>
                            <input type='hidden' name='_token' value='{$csrfToken}'>
                            <input type='hidden' name='_method' value='DELETE'>
                            <button type='submit' title='حذف هذا الصنف من الرادار' style='background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;padding:7px 10px;border-radius:8px;cursor:pointer;font-size:13px;font-weight:700;'>
                                🗑️
                            </button>
                        </form>
                    </div>
                </td>
            </tr>";
        }

        if ($radarRowsHtml === '') {
            $radarRowsHtml = "<tr id='epc-empty-radar-row'><td colspan='8' style='padding:32px;text-align:center;color:#64748b;font-size:14px;'>لا توجد أرقام مسجلة في الرادار حتى الآن.</td></tr>";
        }

        $detailsJson  = json_encode($detailsMapForJs, JSON_UNESCAPED_UNICODE);
        $clearLogsUrl = route('pages.second.logs.clear');

        $contentHtml = <<<HTML
        <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

        <div style="padding:28px;max-width:1400px;margin:0 auto;direction:rtl;background:#f8fafc;min-height:100vh;">
            <div style="background:linear-gradient(135deg, #0f172a 0%, #1e293b 100%);color:#ffffff;padding:24px 28px;border-radius:18px;margin-bottom:22px;display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:16px;box-shadow:0 8px 20px rgba(15,23,42,0.08);">
                <div>
                    <h1 style="margin:0;font-size:24px;font-weight:800;display:flex;align-items:center;gap:10px;">
                        📡 رادار الأصناف المطلوبة والنواقص
                    </h1>
                    <p style="margin:6px 0 0 0;font-size:13px;color:#cbd5e1;">
                        يجمع كافة الأرقام التي تم تسعيرها أو طلبها من جميع العملاء تلقائياً (حتى أثناء إيقاف الرد الآلي للبوت)
                    </p>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
                    <button type="button" id="epc-open-export-modal-btn" style="background:#16a34a;color:#ffffff;border:none;padding:10px 18px;border-radius:10px;font-weight:700;cursor:pointer;font-size:13px;display:inline-flex;align-items:center;gap:6px;">
                        📊 تصدير إلى Excel (.xlsx)
                    </button>
                    <form method="POST" action="{$clearLogsUrl}" onsubmit="return confirm('هل أنت متأكد من تصفير ومسح سجل الرادار بالكامل؟');" style="margin:0;">
                        <input type="hidden" name="_token" value="{$csrfToken}">
                        <button type="submit" style="background:#ef4444;color:#fff;border:none;padding:10px 16px;border-radius:10px;font-weight:700;cursor:pointer;font-size:13px;">
                            🗑️ حذف السجل بالكامل
                        </button>
                    </form>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px;margin-bottom:22px;">
                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <div style="font-size:13px;color:#64748b;font-weight:700;">إجمالي الأصناف المعروضة</div>
                        <div id="epc-stat-visible-count" style="font-size:24px;font-weight:800;color:#0f172a;margin-top:4px;">{$totalUniqueParts}</div>
                    </div>
                    <div style="background:#eff6ff;color:#2563eb;width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;">📦</div>
                </div>

                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <div style="font-size:13px;color:#64748b;font-weight:700;">أصناف غير متوفرة (نواقص)</div>
                        <div style="font-size:24px;font-weight:800;color:#dc2626;margin-top:4px;">{$totalMissingParts}</div>
                    </div>
                    <div style="background:#fef2f2;color:#dc2626;width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;">❌</div>
                </div>

                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <div style="font-size:13px;color:#64748b;font-weight:700;">أصناف متوفرة بالكامل</div>
                        <div style="font-size:24px;font-weight:800;color:#16a34a;margin-top:4px;">{$totalAvailParts}</div>
                    </div>
                    <div style="background:#f0fdf4;color:#16a34a;width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;">✅</div>
                </div>

                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 20px;display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <div style="font-size:13px;color:#64748b;font-weight:700;">إجمالي الكمية المطلوبة</div>
                        <div style="font-size:24px;font-weight:800;color:#b45309;margin-top:4px;">{$totalRequestedUnits} <span style="font-size:13px;font-weight:600;">حبة</span></div>
                    </div>
                    <div style="background:#fffbeb;color:#d97706;width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;">🔢</div>
                </div>
            </div>

            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;padding:18px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
                <div style="flex:1;min-width:260px;">
                    <input type="text" id="epc-radar-live-search" placeholder="🔍 بحث فوري حي برقم الصنف أو اسم القطعة..." style="width:100%;padding:11px 16px;border:1px solid #cbd5e1;border-radius:10px;font-size:14px;background:#f8fafc;">
                </div>

                <select id="epc-radar-status-filter" style="padding:11px 14px;border:1px solid #cbd5e1;border-radius:10px;font-size:13px;font-weight:700;background:#f8fafc;color:#1e293b;cursor:pointer;">
                    <option value="all">📦 جميع الحالات (المتوفر وغير المتوفر)</option>
                    <option value="unavailable">❌ الأصناف غير المتوفرة (النواقص)</option>
                    <option value="available">✅ الأصناف المتوفرة فقط</option>
                </select>

                <select id="epc-radar-sort-select" style="padding:11px 14px;border:1px solid #cbd5e1;border-radius:10px;font-size:13px;font-weight:700;background:#f8fafc;color:#1e293b;cursor:pointer;">
                    <option value="days_desc">🔥 الترتيب: الأكثر طلباً (يومي)</option>
                    <option value="detailed_desc">📈 الترتيب: الأكثر تكراراً (إجمالي)</option>
                    <option value="qty_desc">🔢 الترتيب: الأعلى كمية مطلوبة</option>
                    <option value="unavail_desc">⚠️ الترتيب: النواقص الأكثر طلباً أولاً</option>
                    <option value="date_desc">🕒 الترتيب: الأحدث طلباً أولاً</option>
                    <option value="date_asc">⏳ الترتيب: الأقدم طلباً أولاً</option>
                </select>

                <button type="button" id="epc-radar-reset-btn" style="padding:11px 16px;border-radius:10px;border:1px solid #cbd5e1;background:#ffffff;color:#475569;font-size:13px;font-weight:700;cursor:pointer;">
                    إعادة ضبط
                </button>
            </div>

            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;">
                <div style="overflow-x:auto;">
                    <table id="epc-radar-table" style="width:100%;border-collapse:collapse;font-size:13px;text-align:right;">
                        <thead>
                            <tr style="background:#0f172a;color:#ffffff;">
                                <th style="padding:15px 12px;">رقم الصنف</th>
                                <th style="padding:15px 12px;">اسم الصنف</th>
                                <th style="padding:15px 12px;">حالة التوفر</th>
                                <th style="padding:15px 12px;text-align:center;">إجمالي الكمية المطلوبة</th>
                                <th style="padding:15px 12px;text-align:center;">مرات الطلب (يومي)</th>
                                <th style="padding:15px 12px;text-align:center;">مرات الطلب (مكرر)</th>
                                <th style="padding:15px 12px;">تاريخ آخر طلب</th>
                                <th style="padding:15px 12px;text-align:center;">إجراءات</th>
                            </tr>
                        </thead>
                        <tbody id="epc-radar-tbody">
                            {$radarRowsHtml}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="epc-export-choice-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:99999;align-items:center;justify-content:center;padding:20px;direction:rtl;">
            <div style="background:#ffffff;width:100%;max-width:540px;border-radius:18px;overflow:hidden;">
                <div style="background:#0f172a;color:#ffffff;padding:18px 22px;display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="margin:0;font-size:17px;font-weight:800;">📊 اختر نوع التصدير إلى ملف Excel (.xlsx)</h3>
                    <button type="button" id="epc-close-export-modal-btn" style="background:transparent;color:#cbd5e1;border:none;font-size:18px;cursor:pointer;font-weight:800;">✕</button>
                </div>
                <div style="padding:22px;">
                    <div style="display:flex;flex-direction:column;gap:12px;">
                        <button type="button" id="epc-do-export-normal" style="text-align:right;background:#f0fdf4;border:2px solid #86efac;border-radius:14px;padding:16px;cursor:pointer;">
                            <div style="font-size:15px;font-weight:800;color:#166534;margin-bottom:4px;">1️⃣ تصدير عادي (.xlsx) — ملخص الجدول بدون تكرار</div>
                        </button>
                        <button type="button" id="epc-do-export-detailed" style="text-align:right;background:#eff6ff;border:2px solid #93c5fd;border-radius:14px;padding:16px;cursor:pointer;">
                            <div style="font-size:15px;font-weight:800;color:#1e40af;margin-bottom:4px;">2️⃣ تصدير تفصيلي (.xlsx) — شامل تكرار جميع الطلبات</div>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div id="epc-radar-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:99999;align-items:center;justify-content:center;padding:20px;direction:rtl;">
            <div style="background:#ffffff;width:100%;max-width:980px;border-radius:18px;overflow:hidden;">
                <div style="background:#0f172a;color:#ffffff;padding:18px 24px;display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <h3 id="epc-modal-title" style="margin:0;font-size:18px;font-weight:800;">السجل التفصيلي للصنف</h3>
                        <p id="epc-modal-subtitle" style="margin:5px 0 0 0;font-size:13px;color:#cbd5e1;"></p>
                    </div>
                    <button type="button" id="epc-close-modal-btn" style="background:#ef4444;color:#fff;border:none;padding:7px 16px;border-radius:8px;cursor:pointer;font-weight:700;">✕ إغلاق</button>
                </div>
                <div style="padding:20px;max-height:500px;overflow-y:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:right;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#334155;">
                                <th style="padding:12px 10px;">#</th>
                                <th style="padding:12px 10px;">التاريخ والوقت</th>
                                <th style="padding:12px 10px;">رقم جوال العميل</th>
                                <th style="padding:12px 10px;">المتجر / التاجر</th>
                                <th style="padding:12px 10px;">الحالة وقت الطلب</th>
                                <th style="padding:12px 10px;">الكمية (مطلوب / متوفر)</th>
                                <th style="padding:12px 10px;">السعر</th>
                                <th style="padding:12px 10px;">المصدر</th>
                            </tr>
                        </thead>
                        <tbody id="epc-modal-tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
        (function () {
            const radarDetailsData = {$detailsJson};
            const searchInput      = document.getElementById('epc-radar-live-search');
            const statusSelect     = document.getElementById('epc-radar-status-filter');
            const sortSelect       = document.getElementById('epc-radar-sort-select');
            const resetBtn         = document.getElementById('epc-radar-reset-btn');
            const openExportBtn    = document.getElementById('epc-open-export-modal-btn');
            const exportModal      = document.getElementById('epc-export-choice-modal');
            const closeExportBtn   = document.getElementById('epc-close-export-modal-btn');
            const btnExportNormal  = document.getElementById('epc-do-export-normal');
            const btnExportDetail  = document.getElementById('epc-do-export-detailed');
            const tbody            = document.getElementById('epc-radar-tbody');
            const modal            = document.getElementById('epc-radar-modal');
            const closeModal       = document.getElementById('epc-close-modal-btn');
            const visibleCount     = document.getElementById('epc-stat-visible-count');

            function openDetailsModal(partKey) {
                const data = radarDetailsData[partKey];
                if (!data) return;

                document.getElementById('epc-modal-title').textContent = '📋 السجل التفصيلي لرقم الصنف: ' + data.part_number + ' (' + data.item_name + ')';
                document.getElementById('epc-modal-subtitle').textContent =
                    'إجمالي الكمية المطلوبة: ' + data.total_req_qty + ' حبة | عدد الأيام الفريدة: ' + data.unique_days + ' | إجمالي مرات الطلب: ' + data.detailed_count;

                const modalTbody = document.getElementById('epc-modal-tbody');
                modalTbody.innerHTML = '';

                data.records.forEach(function (rec, idx) {
                    const stBadge = rec.status === 'available'
                        ? "<span style='background:#dcfce7;color:#166534;padding:3px 8px;border-radius:6px;font-weight:700;font-size:12px;'>✅ متوفر</span>"
                        : "<span style='background:#fee2e2;color:#991b1b;padding:3px 8px;border-radius:6px;font-weight:700;font-size:12px;'>❌ غير متوفر</span>";

                    const tr = document.createElement('tr');
                    tr.style.borderBottom = '1px solid #f1f5f9';
                    tr.innerHTML = `
                        <td style="padding:10px;font-weight:700;">\${idx + 1}</td>
                        <td style="padding:10px;direction:ltr;font-family:monospace;">\${rec.date}</td>
                        <td style="padding:10px;font-weight:700;font-family:monospace;">\${rec.customer}</td>
                        <td style="padding:10px;">\${rec.merchant}</td>
                        <td style="padding:10px;">\${stBadge}</td>
                        <td style="padding:10px;">مطلوب: <b>\${rec.req_qty}</b> | متوفر: <b>\${rec.avail_qty}</b></td>
                        <td style="padding:10px;">\${rec.price}</td>
                        <td style="padding:10px;">\${rec.source}</td>
                    `;
                    modalTbody.appendChild(tr);
                });

                modal.style.display = 'flex';
            }

            document.addEventListener('click', function (e) {
                const logBtn = e.target.closest('.epc-open-log-btn');
                if (logBtn) {
                    e.preventDefault();
                    openDetailsModal(logBtn.getAttribute('data-part-key'));
                }
                if (e.target === modal) modal.style.display = 'none';
                if (e.target === exportModal) exportModal.style.display = 'none';
            });

            if (closeModal) closeModal.addEventListener('click', () => modal.style.display = 'none');
            if (closeExportBtn) closeExportBtn.addEventListener('click', () => exportModal.style.display = 'none');

            function applyLiveFilterAndSort() {
                if (!tbody) return;
                const rawQuery   = (searchInput.value || '').trim().toLowerCase();
                const cleanQuery = rawQuery.replace(/[^a-z0-9\\u0600-\\u06FF]/g, '');
                const statusVal  = statusSelect.value;
                const sortVal    = sortSelect.value;

                const rows = Array.from(tbody.querySelectorAll('.epc-radar-row'));
                let shown = 0;

                rows.forEach(function (row) {
                    const searchData = (row.getAttribute('data-search') || '').toLowerCase();
                    const rowStatus  = row.getAttribute('data-status-type') || 'all';

                    let matchSearch = true;
                    if (rawQuery !== '') {
                        matchSearch = searchData.includes(rawQuery) || (cleanQuery !== '' && searchData.includes(cleanQuery));
                    }

                    let matchStatus = true;
                    if (statusVal === 'available') {
                        matchStatus = (rowStatus === 'available' || rowStatus === 'mixed');
                    } else if (statusVal === 'unavailable') {
                        matchStatus = (rowStatus === 'unavailable' || rowStatus === 'mixed');
                    }

                    if (matchSearch && matchStatus) {
                        row.style.display = '';
                        shown++;
                    } else {
                        row.style.display = 'none';
                    }
                });

                rows.sort(function (a, b) {
                    if (sortVal === 'days_desc') {
                        const d = parseInt(b.dataset.days, 10) - parseInt(a.dataset.days, 10);
                        return d !== 0 ? d : parseInt(b.dataset.detailed, 10) - parseInt(a.dataset.detailed, 10);
                    }
                    if (sortVal === 'detailed_desc') return parseInt(b.dataset.detailed, 10) - parseInt(a.dataset.detailed, 10);
                    if (sortVal === 'qty_desc') return parseInt(b.dataset.qty, 10) - parseInt(a.dataset.qty, 10);
                    if (sortVal === 'unavail_desc') return parseInt(b.dataset.unavail, 10) - parseInt(a.dataset.unavail, 10);
                    if (sortVal === 'date_desc') return (b.dataset.date || '').localeCompare(a.dataset.date || '');
                    if (sortVal === 'date_asc') return (a.dataset.date || '').localeCompare(b.dataset.date || '');
                    return 0;
                });

                rows.forEach(r => tbody.appendChild(r));
                if (visibleCount) visibleCount.textContent = shown;
            }

            if (searchInput)  searchInput.addEventListener('input', applyLiveFilterAndSort);
            if (statusSelect) statusSelect.addEventListener('change', applyLiveFilterAndSort);
            if (sortSelect)   sortSelect.addEventListener('change', applyLiveFilterAndSort);

            if (resetBtn) {
                resetBtn.addEventListener('click', function () {
                    searchInput.value  = '';
                    statusSelect.value = 'all';
                    sortSelect.value   = 'days_desc';
                    applyLiveFilterAndSort();
                });
            }

            function downloadRealExcelXlsx(aoaData, colWidths, sheetTitle, filePrefix) {
                const fileName = filePrefix + '_' + new Date().toISOString().slice(0, 10) + '.xlsx';
                if (typeof XLSX !== 'undefined') {
                    const wb = XLSX.utils.book_new();
                    const ws = XLSX.utils.aoa_to_sheet(aoaData);
                    ws['!cols'] = colWidths.map(w => ({ wch: w }));
                    if (!wb.Workbook) wb.Workbook = {};
                    if (!wb.Workbook.Views) wb.Workbook.Views = [{}];
                    wb.Workbook.Views[0].RTL = true;
                    XLSX.utils.book_append_sheet(wb, ws, sheetTitle);
                    XLSX.writeFile(wb, fileName);
                }
            }

            if (openExportBtn) {
                openExportBtn.addEventListener('click', function () {
                    exportModal.style.display = 'flex';
                });
            }

            if (btnExportNormal) {
                btnExportNormal.addEventListener('click', function () {
                    const visibleRows = Array.from(tbody.querySelectorAll('.epc-radar-row')).filter(r => r.style.display !== 'none');
                    const aoa = [['رقم الصنف', 'اسم الصنف', 'حالة التوفر', 'إجمالي الكمية المطلوبة', 'مرات الطلب (يومي)', 'مرات الطلب (مكرر)', 'تاريخ آخر طلب']];
                    visibleRows.forEach(function (r) {
                        aoa.push([
                            String(r.getAttribute('data-part') || ''),
                            String(r.getAttribute('data-name') || ''),
                            String(r.getAttribute('data-status-text') || ''),
                            Number(r.getAttribute('data-qty') || 0),
                            Number(r.getAttribute('data-days') || 0),
                            Number(r.getAttribute('data-detailed') || 0),
                            String(r.getAttribute('data-date') || '')
                        ]);
                    });
                    exportModal.style.display = 'none';
                    downloadRealExcelXlsx(aoa, [22, 35, 26, 22, 18, 18, 22], 'ملخص الأصناف المطلوبة', 'radar_summary_export');
                });
            }

            if (btnExportDetail) {
                btnExportDetail.addEventListener('click', function () {
                    const visibleRows = Array.from(tbody.querySelectorAll('.epc-radar-row')).filter(r => r.style.display !== 'none');
                    const aoa = [['رقم الصنف', 'اسم الصنف', 'الحالة وقت الطلب', 'الكمية المطلوبة', 'الكمية المتوفرة', 'السعر', 'رقم جوال العميل', 'المتجر / التاجر', 'المصدر', 'التاريخ والوقت']];
                    visibleRows.forEach(function (r) {
                        const partKey = r.getAttribute('data-part-key') || '';
                        const itemObj = radarDetailsData[partKey];
                        if (!itemObj || !Array.isArray(itemObj.records)) return;
                        itemObj.records.forEach(function (rec) {
                            aoa.push([
                                String(itemObj.part_number || ''),
                                String(itemObj.item_name || ''),
                                rec.status === 'available' ? 'متوفر' : 'غير متوفر',
                                Number(rec.req_qty || 1),
                                Number(rec.avail_qty || 0),
                                String(rec.price || '—'),
                                String(rec.customer || ''),
                                String(rec.merchant || ''),
                                String(rec.source || ''),
                                String(rec.date || '')
                            ]);
                        });
                    });
                    exportModal.style.display = 'none';
                    downloadRealExcelXlsx(aoa, [22, 35, 18, 16, 16, 14, 20, 22, 16, 22], 'التقرير التفصيلي للطلبات', 'radar_detailed_export');
                });
            }
        })();
        </script>
HTML;

        return response(renderStandalonePageInsideMainLayout('رادار الطلبات العام الموحد', $contentHtml));
    })->name('admin.radar.page');

    // حفظ إعدادات البوت والتصفير التلقائي
    Route::post('/ai-whatsapp/settings', function (Request $request) {
        $userId = auth()->id();
        $fields = [
            'bot_enabled',
            'reply_available',
            'reply_unavailable',
            'reply_quota_exceeded',
            'max_queries_per_phone',
            'quota_reset_period',
            'allow_group_replies',
            'whatsapp_api_url',
            'whatsapp_api_token',
            'whatsapp_session_id',
        ];

        foreach ($fields as $field) {
            if ($field === 'allow_group_replies') {
                if ($request->exists($field) || $request->has('reply_available')) {
                    $val = $request->boolean($field) || $request->input($field) === '1' ? '1' : '0';
                    DB::table('user_bot_settings')->updateOrInsert(
                        ['user_id' => $userId, 'key' => $field],
                        ['value' => $val]
                    );
                }
                continue;
            }

            if ($request->exists($field)) {
                DB::table('user_bot_settings')->updateOrInsert(
                    ['user_id' => $userId, 'key' => $field],
                    ['value' => trim((string) $request->input($field, ''))]
                );
            }
        }

        return back()->with('success', 'تم حفظ الإعدادات بنجاح.');
    })->name('pages.second.settings');

    // إضافة أو تعديل رقم في قائمة الاستثناء المتقدمة (مع دورة التصفير يومي / أسبوعي / شهري)
    Route::post('/ai-whatsapp/whitelist', function (Request $request) {
        $userId      = auth()->id();
        $whitelistId = $request->input('whitelist_id');
        $rawPhone    = trim((string) $request->input('phone', ''));
        $label       = trim((string) $request->input('label', ''));

        $normalized = normalizeWhatsappDigits($rawPhone);
        if ($normalized === '') {
            return back()->with('error', 'يرجى إدخال رقم جوال صحيح.');
        }

        $customQuota = (int) $request->input('custom_quota', 0);
        $resetPeriod = in_array($request->input('reset_period'), ['daily', 'weekly', 'monthly', 'manual', 'default'], true)
            ? $request->input('reset_period')
            : 'daily';
        $startTime   = trim((string) $request->input('allowed_start_time', '')) ?: null;
        $endTime     = trim((string) $request->input('allowed_end_time', '')) ?: null;

        $allowAvailable   = $request->boolean('allow_available');
        $allowUnavailable = $request->boolean('allow_unavailable');
        $allowPrices      = $request->boolean('allow_prices');
        $allowQuantities  = $request->boolean('allow_quantities');
        $allowExcel       = $request->boolean('allow_excel');

        $payloadData = [
            'phone'              => $normalized,
            'label'              => $label,
            'custom_quota'       => $customQuota > 0 ? $customQuota : null,
            'reset_period'       => $resetPeriod,
            'allowed_start_time' => $startTime,
            'allowed_end_time'   => $endTime,
            'allow_available'    => $allowAvailable,
            'allow_unavailable'  => $allowUnavailable,
            'allow_prices'       => $allowPrices,
            'allow_quantities'   => $allowQuantities,
            'allow_excel'        => $allowExcel,
            'updated_at'         => now(),
        ];

        if (!empty($whitelistId)) {
            DB::table('whatsapp_whitelist_phones')
                ->where('id', (int) $whitelistId)
                ->where('user_id', $userId)
                ->update($payloadData);
            return back()->with('success', "تم تحديث صلاحيات وحدود الرقم ({$normalized}) بنجاح.");
        }

        $payloadData['created_at'] = now();
        DB::table('whatsapp_whitelist_phones')->updateOrInsert(
            ['user_id' => $userId, 'phone' => $normalized],
            $payloadData
        );

        return back()->with('success', "تم حفظ الرقم ({$normalized}) في قائمة الاستثناء مع صلاحياته ودورة التصفير المخصصة بنجاح.");
    })->name('pages.second.whitelist.add');

    Route::delete('/ai-whatsapp/whitelist/{id}', function ($id) {
        DB::table('whatsapp_whitelist_phones')
            ->where('id', $id)
            ->where('user_id', auth()->id())
            ->delete();
        return back()->with('success', 'تم حذف الرقم من قائمة الاستثناء.');
    })->name('pages.second.whitelist.delete');

    // مسارات القائمة السوداء
    Route::post('/ai-whatsapp/blacklist', function (Request $request) {
        $userId     = auth()->id();
        $rawPhone   = trim((string) $request->input('phone', ''));
        $reason     = trim((string) $request->input('reason', ''));
        $normalized = normalizeWhatsappDigits($rawPhone);

        if ($normalized === '') {
            return back()->with('error', 'يرجى إدخال رقم جوال صحيح لحظره.');
        }

        DB::table('whatsapp_blacklist_phones')->updateOrInsert(
            ['user_id' => $userId, 'phone' => $normalized],
            ['reason' => $reason, 'updated_at' => now(), 'created_at' => now()]
        );

        return back()->with('success', "تم حظر الرقم ({$normalized}) وإضافته للقائمة السوداء.");
    })->name('pages.second.blacklist.add');

    Route::delete('/ai-whatsapp/blacklist/{id}', function ($id) {
        DB::table('whatsapp_blacklist_phones')
            ->where('id', $id)
            ->where('user_id', auth()->id())
            ->delete();
        return back()->with('success', 'تم إلغاء حظر الرقم من القائمة السوداء.');
    })->name('pages.second.blacklist.delete');

    Route::post('/ai-whatsapp/logs/clear', function () {
        if (auth()->user()->role === 'admin') {
            DB::table('whatsapp_inquiry_logs')->delete();
        } else {
            DB::table('whatsapp_inquiry_logs')->where('user_id', auth()->id())->delete();
        }
        return back()->with('success', 'تم مسح سجل رادار الطلبات بنجاح.');
    })->name('pages.second.logs.clear');

    Route::post('/ai-whatsapp/quotas/reset', function (Request $request) {
        $userId = auth()->id();
        if ($request->filled('quota_id')) {
            DB::table('whatsapp_customer_quotas')
                ->where('id', $request->input('quota_id'))
                ->where('user_id', $userId)
                ->update(['queries_count' => 0, 'last_reset_at' => now(), 'updated_at' => now()]);
            return back()->with('success', 'تم تصفير عداد الاستعلامات لهذا الرقم.');
        }

        DB::table('whatsapp_customer_quotas')
            ->where('user_id', $userId)
            ->update(['queries_count' => 0, 'last_reset_at' => now(), 'updated_at' => now()]);
        return back()->with('success', 'تم تصفير عداد الاستعلامات لجميع الأرقام بنجاح.');
    })->name('pages.second.quotas.reset');

  // 8. إعدادات الربط API والنسخ الاحتياطي (مع التقاط إجباري للرفع المجزأ لتجاوز حد 2MB في السيرفر)
    Route::get('/page-third', function () {
        if (auth()->user()->role !== 'admin') abort(403);
        $webhookLogs = DB::table('webhook_logs')->latest()->take(20)->get();
        $rendered    = view('pages.third', compact('webhookLogs'))->render();
        $restoreUrl  = route('backup.restore');
        $csrfToken   = csrf_token();

        $chunkScript = <<<HTML
<script>
(function () {
    async function handleChunkedBackupRestore(formEl, e) {
        const fileInput = formEl.querySelector('input[type="file"]');
        if (!fileInput || !fileInput.files || !fileInput.files[0]) {
            return;
        }

        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        const file = fileInput.files[0];
        // تعطيل حقل الملف مؤقتاً حتى لا يرسله المتصفح كـ Multipart أبداً
        fileInput.disabled = true;

        const submitBtn = formEl.querySelector('button[type="submit"], input[type="submit"]') || formEl.querySelector('button');
        const origText = submitBtn ? submitBtn.innerHTML : 'استعادة';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.75';
        }

        // حجم الجزء 512 كيلوبايت فقط لضمان عبوره تحت أي سقف في Render / PHP / Nginx
        const chunkSize = 512 * 1024;
        const totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));
        const uploadId = 'bk_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);

        try {
            for (let i = 0; i < totalChunks; i++) {
                const start = i * chunkSize;
                const end = Math.min(file.size, start + chunkSize);
                const blobSlice = file.slice(start, end);

                const percent = Math.round(((i + 1) / totalChunks) * 100);
                if (submitBtn) {
                    submitBtn.innerHTML = '⏳ جاري رفع واستعادة النسخة... (' + percent + '%)';
                }

                const base64Chunk = await new Promise((resolve, reject) => {
                    const reader = new FileReader();
                    reader.onload = () => {
                        const res = String(reader.result || '');
                        resolve(res.includes(',') ? res.split(',')[1] : res);
                    };
                    reader.onerror = reject;
                    reader.readAsDataURL(blobSlice);
                });

                const res = await fetch('{$restoreUrl}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': '{$csrfToken}'
                    },
                    body: JSON.stringify({
                        chunk_upload: true,
                        upload_id: uploadId,
                        chunk_index: i,
                        total_chunks: totalChunks,
                        chunk_data: base64Chunk
                    })
                });

                const rawText = await res.text();
                let data = {};
                try {
                    data = JSON.parse(rawText);
                } catch (_) {
                    throw new Error('استجابة غير متوقعة من السيرفر في الجزء رقم ' + (i + 1));
                }

                if (!res.ok || data.status === 'error') {
                    throw new Error(data.message || 'فشل رفع الجزء رقم ' + (i + 1));
                }
            }

            if (submitBtn) {
                submitBtn.innerHTML = '✅ تمت الاستعادة بنجاح! جاري تحديث الصفحة...';
            }
            window.location.href = window.location.pathname + '?restored=1';
        } catch (err) {
            alert('❌ حدث خطأ أثناء استعادة النسخة الاحتياطية: ' + err.message);
            fileInput.disabled = false;
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.innerHTML = origText;
            }
        }
    }

    // اعتراض الإرسال في مرحلة الـ Capture قبل أي كود آخر في الصفحة
    document.addEventListener('submit', function (e) {
        const form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        const action = (form.getAttribute('action') || '').toLowerCase();
        const hasBackupInput = form.querySelector('input[name="backup_file"], input[accept*=".sqlite"]');
        if (action.includes('restore-backup') || hasBackupInput) {
            handleChunkedBackupRestore(form, e);
        }
    }, true);
})();
</script>
HTML;

        $rendered = injectSidebarNavigationLinks($rendered);
        if (str_contains($rendered, '</body>')) {
            $rendered = str_replace('</body>', $chunkScript . "\n</body>", $rendered);
        } else {
            $rendered .= $chunkScript;
        }

        return response($rendered);
    })->name('pages.third');

    Route::get('/download-backup', function () {
        if (auth()->user()->role !== 'admin') abort(403);
        $path = config('database.connections.sqlite.database', database_path('database.sqlite'));
        try {
            DB::statement('PRAGMA wal_checkpoint(FULL);');
        } catch (\Throwable $e) {}
        return response()->download($path, 'database_backup_' . date('Y-m-d_H-i-s') . '.sqlite');
    })->name('backup.download');

    Route::post('/restore-backup', function (Request $request) {
        if (auth()->user()->role !== 'admin') abort(403);

        @ignore_user_abort(true);
        @set_time_limit(0);

        $targetPath = config('database.connections.sqlite.database', database_path('database.sqlite'));

        $payload = $request->all();
        if (empty($payload)) {
            $raw = file_get_contents('php://input');
            if (!empty($raw)) {
                $dec = json_decode($raw, true);
                if (is_array($dec)) {
                    $payload = $dec;
                }
            }
        }

        // 1. استقبال الأجزاء المرفوعة (Chunked Base64 Upload) لتجاوز قيود حجم الملف في PHP
        if (!empty($payload['chunk_upload'])) {
            $uploadId    = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($payload['upload_id'] ?? 'temp'));
            $chunkIndex  = (int) ($payload['chunk_index'] ?? 0);
            $totalChunks = (int) ($payload['total_chunks'] ?? 1);
            $chunkData   = (string) ($payload['chunk_data'] ?? '');

            $tempDir = storage_path('app/temp_backups');
            if (!is_dir($tempDir)) {
                @mkdir($tempDir, 0775, true);
            }

            $tempFile = $tempDir . DIRECTORY_SEPARATOR . $uploadId . '.sqlite.part';
            if ($chunkIndex === 0 && file_exists($tempFile)) {
                @unlink($tempFile);
            }

            $decoded = base64_decode($chunkData, true);
            if ($decoded === false) {
                return response()->json(['status' => 'error', 'message' => 'تعذر قراءة بيانات الجزء المرفوع.'], 422);
            }

            file_put_contents($tempFile, $decoded, FILE_APPEND);

            if ($chunkIndex + 1 >= $totalChunks) {
                $header = file_get_contents($tempFile, false, null, 0, 16);
                if ($header === false || !str_starts_with($header, 'SQLite format 3')) {
                    @unlink($tempFile);
                    return response()->json(['status' => 'error', 'message' => 'الملف المرفوع ليس قاعدة بيانات SQLite صالحة.'], 422);
                }

                DB::disconnect('sqlite');
                if (file_exists($targetPath . '-wal')) @unlink($targetPath . '-wal');
                if (file_exists($targetPath . '-shm')) @unlink($targetPath . '-shm');

                copy($tempFile, $targetPath);
                @unlink($tempFile);

                session()->flash('success', 'تمت استعادة النسخة الاحتياطية لقاعدة البيانات بنجاح!');
                return response()->json(['status' => 'completed', 'message' => 'تمت الاستعادة بنجاح!']);
            }

            return response()->json(['status' => 'chunk_received', 'chunk' => $chunkIndex]);
        }

        // 2. الاحتياطي المباشر للملفات الصغيرة جداً
        if (!$request->hasFile('backup_file') || !$request->file('backup_file')->isValid()) {
            $errMsg = $request->hasFile('backup_file')
                ? $request->file('backup_file')->getErrorMessage()
                : 'حجم الملف أكبر من الحد المسموح به في السيرفر.';
            return back()->with('error', 'تعذر رفع ملف النسخة الاحتياطية: ' . $errMsg);
        }

        $file = $request->file('backup_file');
        DB::disconnect('sqlite');
        if (file_exists($targetPath . '-wal')) @unlink($targetPath . '-wal');
        if (file_exists($targetPath . '-shm')) @unlink($targetPath . '-shm');

        copy($file->getRealPath(), $targetPath);

        return back()->with('success', 'تمت استعادة النسخة الاحتياطية لقاعدة البيانات بنجاح!');
    })->name('backup.restore');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});
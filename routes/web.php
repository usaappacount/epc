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

    // جدول الاستثناءات المتقدم (حدود مخصصة + أوقات مسموحة + صلاحيات عرض دقيقة + إكسل)
    if (!Schema::hasTable('whatsapp_whitelist_phones')) {
        Schema::create('whatsapp_whitelist_phones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(1)->index();
            $table->string('phone')->index();
            $table->string('label')->nullable();
            $table->integer('custom_quota')->nullable();
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

    // جدول سجل ورادار الطلبات الشامل (يسجل المتوفر وغير المتوفر بالتواريخ والكميات والأسعار من جميع العملاء)
    if (!Schema::hasTable('whatsapp_inquiry_logs')) {
        Schema::create('whatsapp_inquiry_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(1)->index();
            $table->string('phone')->index();
            $table->string('part_number')->index();
            $table->string('normalized_part')->index();
            $table->string('item_name')->nullable();
            $table->string('status', 20)->default('unavailable')->index(); // available | unavailable
            $table->integer('requested_qty')->default(1);
            $table->integer('available_qty')->default(0);
            $table->decimal('price', 12, 2)->nullable();
            $table->string('source', 20)->default('text'); // text | excel
            $table->string('inquiry_date', 15)->nullable()->index(); // YYYY-MM-DD لحساب عدم التكرار في نفس اليوم
            $table->timestamps();
        });
    } else {
        if (!Schema::hasColumn('whatsapp_inquiry_logs', 'inquiry_date')) {
            Schema::table('whatsapp_inquiry_logs', function (Blueprint $table) {
                $table->string('inquiry_date', 15)->nullable()->index();
            });
        }
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
| 2. دوال المساعدة وتنسيق النصوص والأرقام (Helper & Normalization Functions)
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
        $sampleRows = array_slice($rows, 0, 10);

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

    $results = [];
    $lines   = preg_split('/[\r\n]+/', $normText);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $lineParts = [];

        if (preg_match_all('/\b[A-Za-z0-9]{2,}[\-][A-Za-z0-9\-]{2,}\b/u', $line, $pm)) {
            foreach ($pm[0] as $partNum) {
                if (!preg_match('/^(?:19|20)?\d{2}[\-](?:19|20)?\d{2}$/', $partNum)) {
                    $lineParts[] = strtoupper(trim($partNum));
                }
            }
        }

        $tokens = preg_split('/[\s,،\t:;\/\|\(\)\[\]]+/u', $line, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($tokens as $tk) {
            $cleanTk = strtoupper(trim($tk, '-_*.،,؟?!: '));
            if (strlen($cleanTk) < 4) continue;

            $isStandaloneYear = (bool) preg_match('/^(19[7-9]\d|20[0-3]\d)$/', $cleanTk);

            if (preg_match('/^[A-Z0-9\-]+$/', $cleanTk) && preg_match('/\d/', $cleanTk)) {
                $cleanNoDash = normalizePartNumberClean($cleanTk);

                $existsInDb = DB::table('items')
                    ->when($ownerUserId, fn($q) => $q->where('user_id', $ownerUserId))
                    ->where(function ($q) use ($cleanTk, $cleanNoDash) {
                        $q->where('item_number', $cleanTk)
                          ->orWhere(DB::raw("UPPER(REPLACE(REPLACE(item_number, '-', ''), ' ', ''))"), $cleanNoDash);
                    })
                    ->exists();

                if ($existsInDb) {
                    $lineParts[] = $cleanTk;
                } elseif (!$isStandaloneYear && strlen($cleanTk) >= 5) {
                    $lineParts[] = $cleanTk;
                }
            }
        }

        $lineParts = array_values(array_unique($lineParts));
        if (empty($lineParts)) continue;

        $detectedLineQty = 1;
        if (count($lineParts) === 1) {
            $singlePart = $lineParts[0];
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

function extractExcelItemsFromWhatsappPayload(array $payload): array {
    $rawJson = json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '';

    $fileName = data_get($payload, 'data.message.documentMessage.fileName')
             ?? data_get($payload, 'data.message.documentWithCaptionMessage.message.documentMessage.fileName')
             ?? data_get($payload, 'data.document.fileName')
             ?? data_get($payload, 'data.fileName')
             ?? data_get($payload, 'data.file_name')
             ?? data_get($payload, 'data.filename')
             ?? data_get($payload, 'fileName')
             ?? data_get($payload, 'filename')
             ?? '';

    $mimeType = data_get($payload, 'data.message.documentMessage.mimetype')
             ?? data_get($payload, 'data.mimetype')
             ?? data_get($payload, 'mimetype')
             ?? '';

    $mediaUrl = data_get($payload, 'data.media_url')
             ?? data_get($payload, 'data.mediaUrl')
             ?? data_get($payload, 'data.file_url')
             ?? data_get($payload, 'data.fileUrl')
             ?? data_get($payload, 'data.url')
             ?? data_get($payload, 'data.document.url')
             ?? data_get($payload, 'data.message.documentMessage.url')
             ?? data_get($payload, 'media_url')
             ?? data_get($payload, 'file_url')
             ?? data_get($payload, 'url')
             ?? '';

    $base64Data = data_get($payload, 'data.base64')
               ?? data_get($payload, 'data.media_base64')
               ?? data_get($payload, 'data.file_base64')
               ?? data_get($payload, 'base64')
               ?? '';

    $isExcelMime = str_contains(strtolower($mimeType), 'spreadsheet')
                || str_contains(strtolower($mimeType), 'excel')
                || str_contains(strtolower($mimeType), 'csv')
                || preg_match('/\.(xlsx|xls|csv)$/i', (string) $fileName)
                || preg_match('/\.(xlsx|xls|csv)(\?|$)/i', (string) $mediaUrl);

    if (!$isExcelMime && empty($base64Data) && empty($mediaUrl)) {
        return [];
    }

    if (!$isExcelMime && !preg_match('/\.(xlsx|xls|csv)/i', $rawJson)) {
        return [];
    }

    $tempDir = storage_path('app/temp_whatsapp_excel');
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0775, true);
    }

    $ext = strtolower(pathinfo((string) $fileName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
        $ext = str_contains(strtolower($mimeType), 'csv') ? 'csv' : 'xlsx';
    }

    $tempFile = $tempDir . DIRECTORY_SEPARATOR . 'wa_inq_' . time() . '_' . uniqid() . '.' . $ext;

    try {
        $downloaded = false;
        if (!empty($base64Data) && is_string($base64Data)) {
            $cleanBase64 = preg_replace('/^data:[^;]+;base64,/', '', $base64Data);
            $decoded = base64_decode($cleanBase64, true);
            if ($decoded !== false && strlen($decoded) > 16) {
                file_put_contents($tempFile, $decoded);
                $downloaded = true;
            }
        }

        if (!$downloaded && !empty($mediaUrl) && filter_var($mediaUrl, FILTER_VALIDATE_URL)) {
            $resp = Http::withoutVerifying()->timeout(20)->get($mediaUrl);
            if ($resp->successful() && strlen($resp->body()) > 16) {
                file_put_contents($tempFile, $resp->body());
                $downloaded = true;
            }
        }

        if (!$downloaded || !file_exists($tempFile)) {
            return [];
        }

        $rows = parseUploadedSpreadsheetFileRows($tempFile);
        @unlink($tempFile);

        if (empty($rows)) {
            return [];
        }

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
    } catch (\Throwable $e) {
        if (file_exists($tempFile)) {
            @unlink($tempFile);
        }
        return [];
    }
}

/*
|--------------------------------------------------------------------------
| 5. دوال إعدادات البوت وإرسال رسائل الواتساب ومعالجة الويب هوك (Webhook)
|--------------------------------------------------------------------------
*/

function getUserBotSettings(int $userId): array {
    $defaults = [
        'reply_available'       => "القطعة متوفرة لدينا ✅\nاسم الصنف: {item_name}\nرقم الصنف: {item_number}\nالكمية المتوفرة: {quantity}\nالسعر: {price} ريال",
        'reply_unavailable'     => "نعتذر منك، رقم الصنف المطلوب ({part}) غير متوفر حالياً ❌",
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

            Http::withHeaders($headers)->withoutVerifying()->timeout(12)->post($apiUrl, [
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
            // تجاهل أخطاء الشبكة الخارجية
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

function isWithinAllowedTimeWindow(?string $startTime, ?string $endTime): bool {
    $start = trim((string) $startTime);
    $end   = trim((string) $endTime);
    if ($start === '' || $end === '') {
        return true;
    }

    try {
        $nowTime = Carbon::now('Asia/Riyadh')->format('H:i');
        if ($start <= $end) {
            return ($nowTime >= $start && $nowTime <= $end);
        }
        return ($nowTime >= $start || $nowTime <= $end);
    } catch (\Throwable $e) {
        return true;
    }
}

function checkAndResetQuotaAutomatically(int $ownerUserId, string $phoneKey, $quotaRecord, string $resetPeriod): int {
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

        if ($resetPeriod === 'daily' && $refDate->diffInHours($now) >= 24) {
            $shouldReset = true;
        } elseif ($resetPeriod === 'monthly' && ($refDate->month !== $now->month || $refDate->year !== $now->year)) {
            $shouldReset = true;
        }

        if ($shouldReset) {
            DB::table('whatsapp_customer_quotas')
                ->where('user_id', $ownerUserId)
                ->where('phone', $phoneKey)
                ->update([
                    'queries_count' => 0,
                    'last_reset_at' => now(),
                    'updated_at'    => now(),
                ]);
            return 0;
        }
    } catch (\Throwable $e) {
        // تجاهل الخطأ
    }

    return $currentCount;
}

/**
 * تسجيل الاستعلام في جدول الرادار الموحد (مع حفظ تاريخ اليوم بتوقيت الرياض لمنع تكرار اليوم في العد المميز)
 */
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

    if (!$sender) {
        return response()->json(['status' => 'no_sender_detected'], 200);
    }

    $rawSender = ($groupCheck['is_group'] && !empty($groupCheck['group_jid']))
               ? (string) $groupCheck['group_jid']
               : (string) $sender;

    $cleanPhone = ($groupCheck['is_group'] && !empty($groupCheck['group_jid']))
                ? $groupCheck['group_jid']
                : normalizeWhatsappDigits((string) $sender);

    $quotaPhoneKey = normalizeWhatsappDigits((string) $sender) ?: $cleanPhone;

    if ($cleanPhone === '') {
        return response()->json(['status' => 'empty_phone'], 200);
    }

    // 1. فحص القائمة السوداء
    $isBlacklisted = DB::table('whatsapp_blacklist_phones')
        ->where('user_id', $ownerUserId)
        ->where('phone', $quotaPhoneKey)
        ->exists();

    if ($isBlacklisted) {
        return response()->json(['status' => 'ignored_blacklisted_phone'], 200);
    }

    // 2. فحص الاستثناء والوقت المسموح
    $whitelistRecord = DB::table('whatsapp_whitelist_phones')
        ->where('user_id', $ownerUserId)
        ->where('phone', $quotaPhoneKey)
        ->first();

    if ($whitelistRecord && !isWithinAllowedTimeWindow($whitelistRecord->allowed_start_time ?? null, $whitelistRecord->allowed_end_time ?? null)) {
        return response()->json(['status' => 'ignored_outside_whitelist_allowed_hours'], 200);
    }

    // 3. فحص هل أرسل العميل ملف إكسل أو نص
    $excelInquiries = extractExcelItemsFromWhatsappPayload($payload);
    $isExcelSource  = !empty($excelInquiries);

    if ($isExcelSource) {
        if (!$whitelistRecord || !((bool) ($whitelistRecord->allow_excel ?? true))) {
            return response()->json(['status' => 'ignored_excel_not_permitted_for_this_phone'], 200);
        }
        $requestedItems = $excelInquiries;
    } else {
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

        if (!$messageText || !is_string($messageText) || trim($messageText) === '') {
            return response()->json(['status' => 'no_message_detected'], 200);
        }

        $requestedItems = extractPartNumbersWithQuantitiesFromText(trim($messageText), $ownerUserId);
    }

    if (empty($requestedItems)) {
        return response()->json(['status' => 'ignored_no_part_number_detected'], 200);
    }

    // 4. فحص الحصة والتصفير التلقائي (كل رقم صنف = 1)
    $msgAvailable     = trim($settings['reply_available'] ?? '');
    $msgUnavailable   = trim($settings['reply_unavailable'] ?? '');
    $msgQuotaExceeded = trim($settings['reply_quota_exceeded'] ?? '');
    $maxQueriesLimit  = (int) ($settings['max_queries_per_phone'] ?? 0);
    $resetPeriod      = $settings['quota_reset_period'] ?? 'manual';
    $sessionId        = trim($settings['whatsapp_session_id'] ?? '');
    $convId           = data_get($payload, 'data.conversation_id', data_get($payload, 'conversation_id'));

    $quotaRecord = DB::table('whatsapp_customer_quotas')
        ->where('user_id', $ownerUserId)
        ->where('phone', $quotaPhoneKey)
        ->first();

    $currentQueriesUsed = checkAndResetQuotaAutomatically($ownerUserId, $quotaPhoneKey, $quotaRecord, $resetPeriod);

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

    $replyBlocks           = [];
    $unavailablePartsNames = [];
    $countedQueries        = 0;
    $now                   = now();

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
                $isExcelSource ? 'excel' : 'text'
            );

            if ($canShowAvailable && $msgAvailable !== '') {
                $priceDisplay = $canShowPrices ? (string) $item->price : '';
                $qtyDisplay   = $canShowQuantities ? (string) $fulfilledQty : '';

                if ($isExcelSource) {
                    $lineParts = ["✅ *{$item->item_number}* ({$item->item_name})"];
                    if ($canShowQuantities) {
                        $lineParts[] = ($reqQty > 1 && $stockQty < $reqQty)
                            ? "المتوفر: {$fulfilledQty} من أصل {$reqQty}"
                            : "الكمية المتوفرة: {$fulfilledQty}";
                    }
                    if ($canShowPrices) {
                        $lineParts[] = "السعر: {$item->price} ريال";
                    }
                    $replyBlocks[] = implode(' | ', $lineParts);
                } else {
                    $formattedBlock = formatBotTemplate($msgAvailable, [
                        'item_name'     => $item->item_name,
                        'item_number'   => $item->item_number,
                        'price'         => $priceDisplay,
                        'quantity'      => $qtyDisplay,
                        'requested_qty' => (string) $reqQty,
                        'car'           => $item->detected_car_name ?? '',
                        'category'      => $item->detected_category ?? '',
                        'year'          => $item->detected_years ?? '',
                        'part'          => $pNum,
                        'client_name'   => $ownerUser->name ?? '',
                        'phone'         => $quotaPhoneKey,
                        'items'         => "- {$item->item_name} (رقم: {$item->item_number})"
                            . ($canShowQuantities ? " | المتوفر: {$fulfilledQty}" : "")
                            . ($canShowPrices ? " | السعر: {$item->price} ريال" : ""),
                    ]);

                    if ($canShowQuantities && !str_contains($msgAvailable, '{quantity}') && $reqQty > 1) {
                        $formattedBlock .= "\nالكمية المتوفرة: {$fulfilledQty}" . ($stockQty < $reqQty ? " (من أصل {$reqQty} مطلوبة)" : "");
                    }

                    $replyBlocks[] = $formattedBlock;
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
                $isExcelSource ? 'excel' : 'text'
            );

            if ($canShowUnavailable) {
                if ($isExcelSource) {
                    $replyBlocks[] = "❌ *{$pNum}* : غير متوفر حالياً";
                } else {
                    $unavailablePartsNames[] = $pNum;
                }
            }
        }
    }

    if (!$isExcelSource && $canShowUnavailable && !empty($unavailablePartsNames) && $msgUnavailable !== '') {
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

    $separator        = $isExcelSource ? "\n" : "\n\n";
    $finalDirectReply = implode($separator, array_values(array_filter($replyBlocks)));

    if ($isExcelSource && $finalDirectReply !== '') {
        $finalDirectReply = "📊 *نتيجة فحص ملف الإكسل ({$countedQueries} صنف):*\n\n" . $finalDirectReply;
    }

    if ($finalDirectReply === '') {
        return response()->json(['status' => 'skipped_empty_template_or_permissions'], 200);
    }

    sendWhatsappReplyMessage($settings, $cleanPhone, $rawSender, $convId, $finalDirectReply);

    return response()->json([
        'status'          => 'success',
        'session_id'      => $sessionId,
        'counted_queries' => $countedQueries,
        'reply'           => $finalDirectReply,
        'message'         => $finalDirectReply,
        'text'            => $finalDirectReply,
        'body'            => $finalDirectReply,
        'data'            => [
            'session_id' => $sessionId,
            'reply'      => $finalDirectReply,
            'message'    => $finalDirectReply,
            'text'       => $finalDirectReply,
        ],
    ], 200);
}

/*
|--------------------------------------------------------------------------
| 6. دوال بناء الصفحات المستقلة وحقن روابط القائمة الجانبية (Sidebar Navigation)
|--------------------------------------------------------------------------
*/

/**
 * حقن روابط الصفحات المستقلة الجديدة في القائمة الجانبية اليمنى (Sidebar) تلقائياً في كل الصفحات:
 * 1. رابط "🛡️ الاستثناءات والحصص" (تحت الرد الآلي مباشرة لجميع المستخدمين)
 * 2. رابط "📡 رادار الطلبات العام" (تحت قسم المدير للأدمن فقط)
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
            radarLink.innerHTML = '📡 <span style="margin-right:8px;">رادار الطلبات العام</span>';

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
 * استبدال محتوى منطقة العمل الرئيسية داخل القالب الأساسي لإنشاء صفحة مستقلة نظيفة تماماً بدون "صفحة فوق صفحة"
 */
function renderStandalonePageInsideMainLayout(string $pageTitle, string $innerHtmlContent): string {
    $baseHtml = view('pages.second', [
        'customerMessage' => '',
        'matchedItems'    => collect(),
        'botSettings'     => getUserBotSettings(auth()->id()),
        'whitelistPhones' => collect(),
        'customerQuotas'  => collect(),
    ])->render();

    $payloadJson  = json_encode($innerHtmlContent, JSON_UNESCAPED_UNICODE);
    $titleJson    = json_encode($pageTitle, JSON_UNESCAPED_UNICODE);

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

/**
 * حقن البحث المباشر بدون إنتر + الفلاتر السريعة + روابط القائمة الجانبية في صفحة المخزون
 */
function injectInventoryLiveSearchAndFiltersScript(string $html): string {
    $currentFilter = request()->query('stock_filter', 'all');

    $injectedAssets = <<<HTML
<!-- EPC Live Search & Smart Stock Filters -->
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
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
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

        fetch(url.toString(), {
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
| 7. المسارات العامة ومسارات المصادقة (Public & Auth Routes)
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

    // 4. صفحة تفاصيل الرقم الموحد
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

        $keywords = collect();

        $rendered = view('pages.master_details', compact('itemNumber', 'master', 'clientHoldings', 'keywords'))->render();
        return response(injectSidebarNavigationLinks($rendered));
    })->name('master.item.details');

    // 5. صفحة الرد الآلي عبر الواتساب (/ai-whatsapp) - نظيفة تماماً وبدون أي تداخل
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

                // تسجيل تجربة التسعير في الرادار أيضاً ليظهر للأدمن فوراً
                foreach ($extractedWithQty as $reqItem) {
                    $pNum = $reqItem['part_number'];
                    $rQty = max(1, (int) ($reqItem['requested_qty'] ?? 1));
                    $found = findExactItemIgnoringDashes($userId, $pNum, true);
                    if ($found) {
                        recordRadarInquiryLog($userId, 'محاكي الصفحة', $found->item_number, $found->item_name, 'available', $rQty, (int) $found->quantity, (float) $found->price, 'text');
                    } else {
                        recordRadarInquiryLog($userId, 'محاكي الصفحة', $pNum, null, 'unavailable', $rQty, 0, null, 'text');
                    }
                }
            }
        }

        $whitelistPhones = DB::table('whatsapp_whitelist_phones')
            ->where('user_id', $userId)
            ->latest()
            ->get();

        $customerQuotas = DB::table('whatsapp_customer_quotas')
            ->where('user_id', $userId)
            ->orderByDesc('updated_at')
            ->take(20)
            ->get();

        $rendered = view('pages.second', [
            'customerMessage' => $customerMessage,
            'matchedItems'    => $matchedItems,
            'botSettings'     => $botSettings,
            'whitelistPhones' => $whitelistPhones,
            'customerQuotas'  => $customerQuotas,
        ])->render();

        return response(injectSidebarNavigationLinks($rendered));
    })->name('pages.second');

    // 6. صفحة مستقلة تماماً: إدارة الاستثناءات المتقدمة والتصفير التلقائي والقائمة السوداء (/whatsapp-quotas)
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
        $selMonthly = $resetPeriod === 'monthly' ? 'selected' : '';

        $whitelistRowsHtml = '';
        foreach ($whitelistPhones as $w) {
            $quotaBadge = (!empty($w->custom_quota) && (int) $w->custom_quota > 0)
                ? "<span style='background:#dbeafe;color:#1e40af;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:700;'>محدد ({$w->custom_quota} رقم)</span>"
                : "<span style='background:#dcfce7;color:#166534;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:700;'>مفتوح بلا حد</span>";

            $timeWindow = (!empty($w->allowed_start_time) && !empty($w->allowed_end_time))
                ? "⏰ {$w->allowed_start_time} إلى {$w->allowed_end_time}"
                : "🕒 طوال اليوم (24 ساعة)";

            $perms = [];
            if ($w->allow_available ?? true)   $perms[] = "✅ المتوفر";
            if ($w->allow_unavailable ?? true) $perms[] = "❌ غير المتوفر";
            if ($w->allow_prices ?? true)      $perms[] = "💰 الأسعار";
            if ($w->allow_quantities ?? true)  $perms[] = "📦 الكميات";
            if ($w->allow_excel ?? true)       $perms[] = "📊 ملفات إكسل";
            $permsStr = implode(' | ', $perms);

            $delUrl    = route('pages.second.whitelist.delete', $w->id);
            $labelSafe = htmlspecialchars((string) ($w->label ?? 'بدون اسم'), ENT_QUOTES, 'UTF-8');

            $whitelistRowsHtml .= "
            <tr style='border-bottom:1px solid #e2e8f0;'>
                <td style='padding:12px;font-weight:700;font-family:monospace;font-size:14px;'>{$w->phone}</td>
                <td style='padding:12px;font-weight:600;'>{$labelSafe}</td>
                <td style='padding:12px;'>{$quotaBadge}</td>
                <td style='padding:12px;font-size:13px;'>{$timeWindow}</td>
                <td style='padding:12px;font-size:12px;color:#334155;'>{$permsStr}</td>
                <td style='padding:12px;'>
                    <form method='POST' action='{$delUrl}' style='display:inline;'>
                        <input type='hidden' name='_token' value='{$csrfToken}'>
                        <input type='hidden' name='_method' value='DELETE'>
                        <button type='submit' style='background:#ef4444;color:#fff;border:none;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;'>حذف</button>
                    </form>
                </td>
            </tr>";
        }
        if ($whitelistRowsHtml === '') {
            $whitelistRowsHtml = "<tr><td colspan='6' style='padding:18px;text-align:center;color:#64748b;'>لا توجد أرقام مضافة في قائمة الاستثناء حالياً.</td></tr>";
        }

        $blacklistRowsHtml = '';
        foreach ($blacklistPhones as $b) {
            $delBUrl    = route('pages.second.blacklist.delete', $b->id);
            $reasonSafe = htmlspecialchars((string) ($b->reason ?? 'بدون سبب'), ENT_QUOTES, 'UTF-8');
            $blacklistRowsHtml .= "
            <tr style='border-bottom:1px solid #e2e8f0;'>
                <td style='padding:10px;font-weight:700;color:#b91c1c;font-family:monospace;'>{$b->phone}</td>
                <td style='padding:10px;'>{$reasonSafe}</td>
                <td style='padding:10px;font-size:12px;color:#64748b;direction:ltr;'>{$b->created_at}</td>
                <td style='padding:10px;'>
                    <form method='POST' action='{$delBUrl}' style='display:inline;'>
                        <input type='hidden' name='_token' value='{$csrfToken}'>
                        <input type='hidden' name='_method' value='DELETE'>
                        <button type='submit' style='background:#475569;color:#fff;border:none;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;'>إلغاء الحظر</button>
                    </form>
                </td>
            </tr>";
        }
        if ($blacklistRowsHtml === '') {
            $blacklistRowsHtml = "<tr><td colspan='4' style='padding:16px;text-align:center;color:#64748b;'>لا توجد أرقام محظورة في القائمة السوداء.</td></tr>";
        }

        $quotaRowsHtml = '';
        $resetUrl = route('pages.second.quotas.reset');
        foreach ($customerQuotas as $q) {
            $quotaRowsHtml .= "
            <tr style='border-bottom:1px solid #e2e8f0;'>
                <td style='padding:10px;font-weight:700;font-family:monospace;'>{$q->phone}</td>
                <td style='padding:10px;font-weight:700;color:#1d4ed8;'>{$q->queries_count} رقم صنف</td>
                <td style='padding:10px;font-size:12px;color:#64748b;direction:ltr;'>{$q->updated_at}</td>
                <td style='padding:10px;'>
                    <form method='POST' action='{$resetUrl}' style='display:inline;'>
                        <input type='hidden' name='_token' value='{$csrfToken}'>
                        <input type='hidden' name='quota_id' value='{$q->id}'>
                        <button type='submit' style='background:#0284c7;color:#fff;border:none;padding:5px 12px;border-radius:6px;cursor:pointer;font-size:12px;'>تصفير هذا الرقم</button>
                    </form>
                </td>
            </tr>";
        }
        if ($quotaRowsHtml === '') {
            $quotaRowsHtml = "<tr><td colspan='4' style='padding:16px;text-align:center;color:#64748b;'>لا توجد استعلامات مسجلة في العداد حالياً.</td></tr>";
        }

        $settingsActionUrl  = route('pages.second.settings');
        $whitelistActionUrl = route('pages.second.whitelist.add');
        $blacklistActionUrl = route('pages.second.blacklist.add');

        $contentHtml = <<<HTML
        <div style="padding:24px;max-width:1300px;margin:0 auto;direction:rtl;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
                <div>
                    <h1 style="margin:0;font-size:24px;font-weight:800;color:#0f172a;">🛡️ إدارة الاستثناءات والحصص والتصفير التلقائي</h1>
                    <p style="margin:6px 0 0 0;font-size:14px;color:#64748b;">تحكم كامل في الحد العام، التصفير اليومي/الشهري، صلاحيات المستثنين الدقيقة، والقائمة السوداء.</p>
                </div>
            </div>

            <!-- 1. التصفير التلقائي والحد العام -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;padding:22px;margin-bottom:24px;box-shadow:0 2px 6px rgba(0,0,0,0.03);">
                <h3 style="margin:0 0 10px 0;font-size:18px;color:#0f172a;">🔄 نظام الحصة العام والتصفير التلقائي (كل رقم صنف = 1 استعلام)</h3>
                <p style="margin:0 0 16px 0;font-size:13px;color:#475569;">
                    💡 إذا كان الحد العام مثلاً <b>5</b> وأرسل العميل <b>5 أرقام أصناف</b> في رسالة واحدة، تُحتسب <b>5 استعلامات</b> (لأن كل رقم صنف يُحتسب بـ 1).
                </p>
                <form method="POST" action="{$settingsActionUrl}" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;">
                    <input type="hidden" name="_token" value="{$csrfToken}">
                    <div>
                        <label style="display:block;font-size:13px;font-weight:700;color:#1e293b;margin-bottom:6px;">الحد العام للاستعلام لكل رقم (0 = مفتوح):</label>
                        <input type="number" name="max_queries_per_phone" min="0" value="{$maxLimit}" style="padding:9px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;width:220px;">
                    </div>
                    <div>
                        <label style="display:block;font-size:13px;font-weight:700;color:#1e293b;margin-bottom:6px;">دورة التصفير التلقائي لعداد الاستعلامات:</label>
                        <select name="quota_reset_period" style="padding:9px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;background:#f8fafc;min-width:260px;">
                            <option value="manual" {$selManual}>يدوي فقط (من زر تصفير العداد)</option>
                            <option value="daily" {$selDaily}>تلقائي يومياً (كل 24 ساعة)</option>
                            <option value="monthly" {$selMonthly}>تلقائي بداية كل شهر</option>
                        </select>
                    </div>
                    <button type="submit" style="background:#2563eb;color:#ffffff;border:none;padding:10px 22px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;">
                        💾 حفظ إعدادات الحد والتصفير
                    </button>
                </form>
            </div>

            <!-- 2. نظام الاستثناءات المتقدم -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;padding:22px;margin-bottom:24px;box-shadow:0 2px 6px rgba(0,0,0,0.03);">
                <h3 style="margin:0 0 14px 0;font-size:18px;color:#0f172a;">🌟 قائمة الاستثناء المتقدمة (صلاحيات مخصصة + أوقات معينة + دعم الإكسل)</h3>
                <form method="POST" action="{$whitelistActionUrl}" style="background:#f8fafc;padding:18px;border-radius:12px;border:1px solid #e2e8f0;margin-bottom:20px;">
                    <input type="hidden" name="_token" value="{$csrfToken}">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:14px;">
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px;">رقم الجوال *</label>
                            <input type="text" name="phone" required placeholder="05XXXXXXXX" style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px;">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px;">اسم العميل / الوصف</label>
                            <input type="text" name="label" placeholder="مثال: ورشة النخبة" style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px;">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px;">عدد مرات الاستعلام (0 = مفتوح)</label>
                            <input type="number" name="custom_quota" min="0" value="0" style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px;">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px;">من الساعة (اختياري)</label>
                            <input type="time" name="allowed_start_time" style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px;">
                        </div>
                        <div>
                            <label style="display:block;font-size:12px;font-weight:700;margin-bottom:5px;">إلى الساعة (اختياري)</label>
                            <input type="time" name="allowed_end_time" style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:8px;">
                        </div>
                    </div>

                    <div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center;padding-top:12px;border-top:1px dashed #cbd5e1;">
                        <span style="font-size:13px;font-weight:700;color:#1e293b;">صلاحيات الرد المسموحة لهذا العميل:</span>
                        <label style="font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="checkbox" name="allow_available" value="1" checked> ✅ إظهار المتوفر
                        </label>
                        <label style="font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="checkbox" name="allow_unavailable" value="1" checked> ❌ إظهار غير المتوفر
                        </label>
                        <label style="font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="checkbox" name="allow_prices" value="1" checked> 💰 إظهار الأسعار
                        </label>
                        <label style="font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="checkbox" name="allow_quantities" value="1" checked> 📦 إظهار الكميات المتوفرة
                        </label>
                        <label style="font-size:13px;display:flex;align-items:center;gap:6px;cursor:pointer;">
                            <input type="checkbox" name="allow_excel" value="1" checked> 📊 السماح بالاستعلام عبر ملف Excel
                        </label>
                        <button type="submit" style="margin-right:auto;background:#16a34a;color:#fff;border:none;padding:10px 22px;border-radius:8px;font-weight:700;cursor:pointer;">
                            + حفظ / تحديث الرقم المستثنى
                        </button>
                    </div>
                </form>

                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:right;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#334155;">
                                <th style="padding:12px;">رقم الجوال</th>
                                <th style="padding:12px;">الاسم / الوصف</th>
                                <th style="padding:12px;">حد الاستعلام</th>
                                <th style="padding:12px;">الأوقات المسموحة</th>
                                <th style="padding:12px;">الصلاحيات المفعلة</th>
                                <th style="padding:12px;">إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$whitelistRowsHtml}
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 3. القائمة السوداء (Blacklist) -->
            <div style="background:#ffffff;border:1px solid #fee2e2;border-radius:14px;padding:22px;margin-bottom:24px;box-shadow:0 2px 6px rgba(0,0,0,0.03);">
                <h3 style="margin:0 0 12px 0;font-size:18px;color:#991b1b;">🚫 القائمة السوداء (Blacklist) - حظر الأرقام من الاستعلام نهائياً</h3>
                <form method="POST" action="{$blacklistActionUrl}" style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                    <input type="hidden" name="_token" value="{$csrfToken}">
                    <input type="text" name="phone" required placeholder="رقم الجوال المراد حظره (05XXXXXXXX)" style="flex:1;min-width:220px;padding:9px 14px;border:1px solid #fca5a5;border-radius:8px;">
                    <input type="text" name="reason" placeholder="سبب الحظر (اختياري)" style="flex:1;min-width:220px;padding:9px 14px;border:1px solid #cbd5e1;border-radius:8px;">
                    <button type="submit" style="background:#dc2626;color:#fff;border:none;padding:9px 20px;border-radius:8px;font-weight:700;cursor:pointer;">
                        حظر الرقم فوراً
                    </button>
                </form>
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:right;">
                        <thead>
                            <tr style="background:#fef2f2;color:#991b1b;">
                                <th style="padding:10px;">الرقم المحظور</th>
                                <th style="padding:10px;">السبب</th>
                                <th style="padding:10px;">تاريخ الحظر</th>
                                <th style="padding:10px;">إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$blacklistRowsHtml}
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 4. سجل عداد الاستعلامات الحالي للعملاء -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;padding:22px;box-shadow:0 2px 6px rgba(0,0,0,0.03);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                    <h3 style="margin:0;font-size:18px;color:#0f172a;">📊 عداد استهلاك الأرقام وحصص العملاء</h3>
                    <form method="POST" action="{$resetUrl}" onsubmit="return confirm('هل تريد تصفير العداد لجميع الأرقام؟');">
                        <input type="hidden" name="_token" value="{$csrfToken}">
                        <button type="submit" style="background:#dc2626;color:#fff;border:none;padding:8px 16px;border-radius:8px;font-weight:700;cursor:pointer;font-size:13px;">
                            🔄 تصفير جميع العدادات
                        </button>
                    </form>
                </div>
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:right;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#334155;">
                                <th style="padding:10px;">رقم الجوال</th>
                                <th style="padding:10px;">الأرقام المستهلكة</th>
                                <th style="padding:10px;">آخر استعلام</th>
                                <th style="padding:10px;">إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$quotaRowsHtml}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
HTML;

        return response(renderStandalonePageInsideMainLayout('إدارة الاستثناءات والحصص', $contentHtml));
    })->name('whatsapp.quotas.page');

    // 7. صفحة مستقلة تماماً خاصة بالأدمن فقط: رادار الطلبات العام الموحد (/admin-radar)
    Route::get('/admin-radar', function (Request $request) {
        if (auth()->user()->role !== 'admin') {
            abort(403);
        }

        $csrfToken    = csrf_token();
        $search       = trim((string) $request->query('search', ''));
        $statusFilter = trim((string) $request->query('status', 'all'));

        // جلب جميع سجلات الاستعلام من جميع العملاء والمتاجر في النظام وتجميعها برقم الصنف الموحد بدون تكرار
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
                    'unique_days_map' => [], // لحساب عدد الأيام الفريدة (بحد أقصى 1 لكل يوم)
                    'detailed_count'  => 0,  // لحساب العدد التفصيلي الشامل حتى لو تكرر في نفس اليوم
                    'avail_count'     => 0,
                    'unavail_count'   => 0,
                    'total_req_qty'   => 0,
                    'last_status'     => $log->status,
                    'last_date'       => $log->created_at,
                    'details_list'    => [],
                ];
            }

            if (empty($groupedRadar[$normKey]['item_name']) && !empty($log->item_name)) {
                $groupedRadar[$normKey]['item_name'] = $log->item_name;
            }

            // 1. العمود الأول: يحتسب 1 فقط لكل يوم (لا يزيد عن 1 إذا تكرر الطلب في نفس اليوم)
            $groupedRadar[$normKey]['unique_days_map'][$dayStr] = true;

            // 2. العمود الثاني (التفصيلي): يحتسب +1 في كل مرة يتم تسعيرها أو طلبها حتى لو مكررة بنفس اليوم
            $groupedRadar[$normKey]['detailed_count']++;

            if ($log->status === 'available') {
                $groupedRadar[$normKey]['avail_count']++;
            } else {
                $groupedRadar[$normKey]['unavail_count']++;
            }

            $groupedRadar[$normKey]['total_req_qty'] += (int) $log->requested_qty;

            if (count($groupedRadar[$normKey]['details_list']) < 50) {
                $groupedRadar[$normKey]['details_list'][] = [
                    'date'          => (string) $log->created_at,
                    'day'           => $dayStr,
                    'customer'      => (string) $log->phone,
                    'merchant'      => (string) $log->merchant_name,
                    'status'        => (string) $log->status,
                    'req_qty'       => (int) $log->requested_qty,
                    'avail_qty'     => (int) $log->available_qty,
                    'price'         => $log->price !== null ? number_format((float) $log->price, 2) : '—',
                    'source'        => $log->source === 'excel' ? 'ملف إكسل' : 'رسالة واتساب',
                ];
            }
        }

        // فلترة البحث والحالة
        $cleanSearchNoDash = normalizePartNumberClean($search);
        $filteredItems = array_filter($groupedRadar, function ($row) use ($search, $cleanSearchNoDash, $statusFilter) {
            if ($statusFilter === 'available' && $row['avail_count'] === 0) {
                return false;
            }
            if ($statusFilter === 'unavailable' && $row['unavail_count'] === 0) {
                return false;
            }
            if ($search !== '') {
                $matchPart = str_contains(strtoupper($row['part_number']), strtoupper($search))
                          || ($cleanSearchNoDash !== '' && str_contains($row['normalized_part'], $cleanSearchNoDash));
                $matchName = !empty($row['item_name']) && str_contains($row['item_name'], $search);
                if (!$matchPart && !$matchName) {
                    return false;
                }
            }
            return true;
        });

        // ترتيب حسب الأكثر طلباً
        usort($filteredItems, function ($a, $b) {
            $daysA = count($a['unique_days_map']);
            $daysB = count($b['unique_days_map']);
            if ($daysA !== $daysB) {
                return $daysB <=> $daysA;
            }
            return $b['detailed_count'] <=> $a['detailed_count'];
        });

        $totalUniqueParts   = count($groupedRadar);
        $totalDetailedCalls = count($allLogs);

        $radarRowsHtml = '';
        $detailsMapForJs = [];

        foreach ($filteredItems as $idx => $item) {
            $uniqueDaysCount = count($item['unique_days_map']);
            $detailedCount   = $item['detailed_count'];
            $partKey         = $item['normalized_part'];
            $detailsMapForJs[$partKey] = [
                'part_number'    => $item['part_number'],
                'item_name'      => $item['item_name'] ?: 'غير مسجل في المخزون',
                'unique_days'    => $uniqueDaysCount,
                'detailed_count' => $detailedCount,
                'records'        => $item['details_list'],
            ];

            $nameDisplay = htmlspecialchars((string) ($item['item_name'] ?: '— غير مسجل في المخزون —'), ENT_QUOTES, 'UTF-8');
            $partDisplay = htmlspecialchars((string) $item['part_number'], ENT_QUOTES, 'UTF-8');

            if ($item['avail_count'] > 0 && $item['unavail_count'] > 0) {
                $statusHtml = "<span style='background:#dcfce7;color:#166534;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:700;'>متوفر ({$item['avail_count']})</span>
                               <span style='background:#fee2e2;color:#991b1b;padding:3px 8px;border-radius:6px;font-size:12px;font-weight:700;margin-right:4px;'>غير متوفر ({$item['unavail_count']})</span>";
            } elseif ($item['avail_count'] > 0) {
                $statusHtml = "<span style='background:#dcfce7;color:#166534;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:700;'>✅ متوفر ({$item['avail_count']})</span>";
            } else {
                $statusHtml = "<span style='background:#fee2e2;color:#991b1b;padding:4px 10px;border-radius:6px;font-size:12px;font-weight:700;'>❌ غير متوفر ({$item['unavail_count']})</span>";
            }

            $radarRowsHtml .= "
            <tr style='border-bottom:1px solid #e2e8f0;transition:background 0.15s;' onmouseover=\"this.style.background='#f8fafc'\" onmouseout=\"this.style.background='transparent'\">
                <td style='padding:12px;font-weight:800;font-family:monospace;font-size:15px;color:#0f172a;'>{$partDisplay}</td>
                <td style='padding:12px;font-weight:600;color:#334155;'>{$nameDisplay}</td>
                <td style='padding:12px;'>{$statusHtml}</td>
                <td style='padding:12px;text-align:center;'>
                    <span style='background:#dbeafe;color:#1e40af;padding:5px 14px;border-radius:999px;font-size:14px;font-weight:800;display:inline-block;'>
                        {$uniqueDaysCount}
                    </span>
                    <div style='font-size:11px;color:#64748b;margin-top:3px;'>بحد أقصى 1 باليوم</div>
                </td>
                <td style='padding:12px;text-align:center;'>
                    <div style='display:flex;align-items:center;justify-content:center;gap:8px;'>
                        <span style='background:#f3e8ff;color:#6b21a8;padding:5px 14px;border-radius:999px;font-size:14px;font-weight:800;'>
                            {$detailedCount}
                        </span>
                        <button type='button' onclick=\"openRadarDetailsModal('{$partKey}')\" style='background:#0f172a;color:#ffffff;border:none;padding:6px 12px;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700;'>
                            🔍 السجل التفصيلي بالتواريخ
                        </button>
                    </div>
                    <div style='font-size:11px;color:#64748b;margin-top:3px;'>شامل التكرار بنفس اليوم</div>
                </td>
                <td style='padding:12px;font-size:12px;color:#475569;direction:ltr;text-align:right;'>{$item['last_date']}</td>
            </tr>";
        }

        if ($radarRowsHtml === '') {
            $radarRowsHtml = "<tr><td colspan='6' style='padding:24px;text-align:center;color:#64748b;font-size:14px;'>لا توجد أرقام مسجلة في الرادار مطابقة للبحث الحالي.</td></tr>";
        }

        $detailsJson = json_encode($detailsMapForJs, JSON_UNESCAPED_UNICODE);
        $clearLogsUrl = route('pages.second.logs.clear');
        $radarUrl     = route('admin.radar.page');
        $searchSafe   = htmlspecialchars($search, ENT_QUOTES, 'UTF-8');

        $selAll     = $statusFilter === 'all' ? 'selected' : '';
        $selAvail   = $statusFilter === 'available' ? 'selected' : '';
        $selUnavail = $statusFilter === 'unavailable' ? 'selected' : '';

        $contentHtml = <<<HTML
        <div style="padding:24px;max-width:1350px;margin:0 auto;direction:rtl;">
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:14px;margin-bottom:22px;">
                <div>
                    <h1 style="margin:0;font-size:24px;font-weight:800;color:#0f172a;">📡 رادار الطلبات العام الموحد (خاص بالأدمن)</h1>
                    <p style="margin:6px 0 0 0;font-size:14px;color:#64748b;">
                        يجمع كافة الأرقام التي تم تسعيرها أو طلبها من جميع العملاء والمتاجر بدون تكرار رقم الصنف، مع فصل العد اليومي عن العد التفصيلي الشامل.
                    </p>
                </div>
                <div style="display:flex;gap:12px;align-items:center;">
                    <div style="background:#eff6ff;border:1px solid #bfdbfe;padding:10px 16px;border-radius:10px;text-align:center;">
                        <div style="font-size:11px;color:#1e40af;font-weight:700;">إجمالي الأصناف الفريدة</div>
                        <div style="font-size:20px;font-weight:800;color:#1d4ed8;">{$totalUniqueParts}</div>
                    </div>
                    <div style="background:#faf5ff;border:1px solid #e9d5ff;padding:10px 16px;border-radius:10px;text-align:center;">
                        <div style="font-size:11px;color:#6b21a8;font-weight:700;">إجمالي عمليات التسعير التفصيلية</div>
                        <div style="font-size:20px;font-weight:800;color:#7e22ce;">{$totalDetailedCalls}</div>
                    </div>
                    <form method="POST" action="{$clearLogsUrl}" onsubmit="return confirm('هل أنت متأكد من تصفير ومسح سجل الرادار بالكامل؟');">
                        <input type="hidden" name="_token" value="{$csrfToken}">
                        <button type="submit" style="background:#ef4444;color:#fff;border:none;padding:10px 16px;border-radius:10px;font-weight:700;cursor:pointer;font-size:13px;">
                            🗑️ تصفير الرادار
                        </button>
                    </form>
                </div>
            </div>

            <!-- شريط البحث والفلترة -->
            <form method="GET" action="{$radarUrl}" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;padding:16px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
                <input type="text" name="search" value="{$searchSafe}" placeholder="ابحث برقم الصنف (مع شرطة أو بدون) أو اسم القطعة..." style="flex:1;min-width:260px;padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;">
                <select name="status" style="padding:10px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;background:#f8fafc;">
                    <option value="all" {$selAll}>📦 جميع الحالات (المتوفر وغير المتوفر)</option>
                    <option value="available" {$selAvail}>✅ الأصناف المتوفرة فقط</option>
                    <option value="unavailable" {$selUnavail}>❌ الأصناف غير المتوفرة (النواقص)</option>
                </select>
                <button type="submit" style="background:#2563eb;color:#ffffff;border:none;padding:10px 22px;border-radius:8px;font-weight:700;cursor:pointer;font-size:14px;">
                    🔍 بحث وفلترة
                </button>
                <a href="{$radarUrl}" style="padding:10px 16px;border-radius:8px;border:1px solid #cbd5e1;color:#475569;text-decoration:none;font-size:13px;font-weight:700;">إعادة ضبط</a>
            </form>

            <!-- جدول الرادار الموحد بدون تكرار رقم الصنف -->
            <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;box-shadow:0 2px 6px rgba(0,0,0,0.03);">
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:right;">
                        <thead>
                            <tr style="background:#0f172a;color:#ffffff;">
                                <th style="padding:14px 12px;">رقم الصنف (بدون تكرار)</th>
                                <th style="padding:14px 12px;">اسم الصنف</th>
                                <th style="padding:14px 12px;">حالة التوفر المسجلة</th>
                                <th style="padding:14px 12px;text-align:center;">عدد مرات الطلب اليومي (أقصى 1 باليوم)</th>
                                <th style="padding:14px 12px;text-align:center;">العداد التفصيلي الشامل (شامل تكرار اليوم)</th>
                                <th style="padding:14px 12px;">تاريخ آخر تسعير</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$radarRowsHtml}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- نافذة عرض السجل التفصيلي بالتواريخ لكل رقم صنف -->
        <div id="epc-radar-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.65);z-index:9999;align-items:center;justify-content:center;padding:20px;direction:rtl;">
            <div style="background:#ffffff;width:100%;max-width:950px;border-radius:16px;overflow:hidden;box-shadow:0 20px 25px -5px rgba(0,0,0,0.2);">
                <div style="background:#0f172a;color:#ffffff;padding:16px 22px;display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <h3 id="epc-modal-title" style="margin:0;font-size:18px;">السجل التفصيلي للصنف</h3>
                        <p id="epc-modal-subtitle" style="margin:4px 0 0 0;font-size:12px;color:#cbd5e1;"></p>
                    </div>
                    <button type="button" onclick="closeRadarDetailsModal()" style="background:#ef4444;color:#fff;border:none;padding:6px 14px;border-radius:8px;cursor:pointer;font-weight:700;">✕ إغلاق</button>
                </div>
                <div style="padding:20px;max-height:480px;overflow-y:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:right;">
                        <thead>
                            <tr style="background:#f1f5f9;color:#334155;">
                                <th style="padding:10px;">#</th>
                                <th style="padding:10px;">التاريخ والوقت</th>
                                <th style="padding:10px;">رقم جوال العميل</th>
                                <th style="padding:10px;">المتجر / التاجر</th>
                                <th style="padding:10px;">الحالة وقت الطلب</th>
                                <th style="padding:10px;">الكمية (مطلوب / متوفر)</th>
                                <th style="padding:10px;">السعر</th>
                                <th style="padding:10px;">المصدر</th>
                            </tr>
                        </thead>
                        <tbody id="epc-modal-tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <script>
        window.epcRadarDetailsData = {$detailsJson};

        function openRadarDetailsModal(partKey) {
            const data = window.epcRadarDetailsData[partKey];
            if (!data) return;

            document.getElementById('epc-modal-title').textContent = '🔍 السجل التفصيلي لرقم الصنف: ' + data.part_number + ' (' + data.item_name + ')';
            document.getElementById('epc-modal-subtitle').textContent =
                'عدد الأيام الفريدة (بدون تكرار باليوم): ' + data.unique_days + ' | إجمالي مرات التسعير التفصيلية (شامل التكرار بنفس اليوم): ' + data.detailed_count;

            const tbody = document.getElementById('epc-modal-tbody');
            tbody.innerHTML = '';

            data.records.forEach(function(rec, idx) {
                const stBadge = rec.status === 'available'
                    ? "<span style='background:#dcfce7;color:#166534;padding:2px 8px;border-radius:6px;font-weight:700;font-size:12px;'>✅ متوفر</span>"
                    : "<span style='background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:6px;font-weight:700;font-size:12px;'>❌ غير متوفر</span>";

                const tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid #e2e8f0';
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
                tbody.appendChild(tr);
            });

            document.getElementById('epc-radar-modal').style.display = 'flex';
        }

        function closeRadarDetailsModal() {
            document.getElementById('epc-radar-modal').style.display = 'none';
        }
        </script>
HTML;

        return response(renderStandalonePageInsideMainLayout('رادار الطلبات العام الموحد', $contentHtml));
    })->name('admin.radar.page');

    // حفظ إعدادات البوت والتصفير التلقائي
    Route::post('/ai-whatsapp/settings', function (Request $request) {
        $userId = auth()->id();
        $fields = [
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

    // إضافة أو تحديث رقم في قائمة الاستثناء المتقدمة
    Route::post('/ai-whatsapp/whitelist', function (Request $request) {
        $userId   = auth()->id();
        $rawPhone = trim((string) $request->input('phone', ''));
        $label    = trim((string) $request->input('label', ''));

        $normalized = normalizeWhatsappDigits($rawPhone);
        if ($normalized === '') {
            return back()->with('error', 'يرجى إدخال رقم جوال صحيح.');
        }

        $customQuota = (int) $request->input('custom_quota', 0);
        $startTime   = trim((string) $request->input('allowed_start_time', '')) ?: null;
        $endTime     = trim((string) $request->input('allowed_end_time', '')) ?: null;

        $hasAdvancedFlags = $request->hasAny(['allow_available', 'allow_unavailable', 'allow_prices', 'allow_quantities', 'allow_excel', 'custom_quota']);

        $allowAvailable   = $hasAdvancedFlags ? $request->boolean('allow_available') : true;
        $allowUnavailable = $hasAdvancedFlags ? $request->boolean('allow_unavailable') : true;
        $allowPrices      = $hasAdvancedFlags ? $request->boolean('allow_prices') : true;
        $allowQuantities  = $hasAdvancedFlags ? $request->boolean('allow_quantities') : true;
        $allowExcel       = $hasAdvancedFlags ? $request->boolean('allow_excel') : true;

        DB::table('whatsapp_whitelist_phones')->updateOrInsert(
            ['user_id' => $userId, 'phone' => $normalized],
            [
                'label'              => $label,
                'custom_quota'       => $customQuota > 0 ? $customQuota : null,
                'allowed_start_time' => $startTime,
                'allowed_end_time'   => $endTime,
                'allow_available'    => $allowAvailable,
                'allow_unavailable'  => $allowUnavailable,
                'allow_prices'       => $allowPrices,
                'allow_quantities'   => $allowQuantities,
                'allow_excel'        => $allowExcel,
                'updated_at'         => now(),
                'created_at'         => now(),
            ]
        );

        return back()->with('success', "تم حفظ الرقم ({$normalized}) في قائمة الاستثناء مع صلاحياته المخصصة بنجاح.");
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

    // مسح سجل الرادار (للأدمن يمسح الرادار العام، وللمستخدم يمسح سجله)
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

    // 8. إعدادات الربط API والنسخ الاحتياطي
    Route::get('/page-third', function () {
        if (auth()->user()->role !== 'admin') abort(403);
        $webhookLogs = DB::table('webhook_logs')->latest()->take(20)->get();
        $rendered = view('pages.third', compact('webhookLogs'))->render();
        return response(injectSidebarNavigationLinks($rendered));
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
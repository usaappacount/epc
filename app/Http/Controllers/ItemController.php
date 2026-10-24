<?php namespace App\Http\Controllers;

use App\Models\Item;
use Illuminate\Http\Request;
use ZipArchive;

class ItemController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->query('search');

        $items = Item::smartSearch($search)->latest()->paginate(10)->withQueryString();

        $stats = [
            'total_items' => Item::count(),
            'total_quantity' => Item::sum('quantity'),
            'low_stock' => Item::where('quantity', '<=', 10)->count(),
            'total_value' => Item::selectRaw('SUM(quantity * price) as total')->value('total') ?? 0,
        ];

        return view('inventory.index', compact('items', 'stats', 'search'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'item_number' => 'required|string|max:50|unique:items,item_number',
            'item_name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:0',
            'price' => 'required|numeric|min:0',
        ]);

        Item::create($validated);

        return redirect()->route('inventory.index')->with('success', 'تمت إضافة الصنف بنجاح.');
    }

    public function update(Request $request, Item $item)
    {
        $validated = $request->validate([
            'item_number' => 'required|string|max:50|unique:items,item_number,' . $item->id,
            'item_name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:0',
            'price' => 'required|numeric|min:0',
        ]);

        $item->update($validated);

        return redirect()->route('inventory.index')->with('success', 'تم تحديث بيانات الصنف بنجاح.');
    }

    public function destroy(Item $item)
    {
        $item->delete();

        return redirect()->route('inventory.index')->with('success', 'تم حذف الصنف من المخزون.');
    }

    public function import(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file',
        ], [
            'excel_file.required' => 'يرجى اختيار ملف الإكسل أولاً.',
        ]);

        $file = $request->file('excel_file');
        $extension = strtolower($file->getClientOriginalExtension());

        if (!in_array($extension, ['xlsx', 'xls', 'csv', 'txt'])) {
            return redirect()->route('inventory.index')
                ->withErrors(['صيغة الملف غير مدعومة. يرجى رفع ملف بصيغة xlsx أو xls أو csv.']);
        }

        $rows = [];

        // 1. إذا كان الملف بصيغة الإكسل الحديث (.xlsx)
        if ($extension === 'xlsx') {
            $rows = $this->parseXlsx($file->getRealPath());
        } else {
            // 2. إذا كان الملف بصيغة CSV أو XLS نصي
            $rows = $this->parseCsvOrXls($file->getRealPath());
        }

        $importedCount = 0;

        foreach ($rows as $index => $row) {
            // تنظيف القيم من المسافات والرموز المخفية (UTF-8 BOM)
            $col0 = isset($row[0]) ? trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0])) : '';
            $col1 = isset($row[1]) ? trim((string) $row[1]) : '';
            $col2 = isset($row[2]) ? trim((string) $row[2]) : '0';
            $col3 = isset($row[3]) ? trim((string) $row[3]) : '0';

            // تخطي الصفوف الفارغة تماماً
            if ($col0 === '' && $col1 === '') {
                continue;
            }

            // تخطي سطر العناوين الأول (Header) إذا كان يحتوي على كلمات مثل "رقم الصنف" أو "item_number"
            if ($index === 0 && (
                str_contains($col0, 'رقم') ||
                str_contains($col0, 'كود') ||
                str_contains(strtolower($col0), 'item') ||
                str_contains(strtolower($col0), 'code') ||
                str_contains($col1, 'اسم الصنف')
            )) {
                continue;
            }

            // إذا كان اسم الصنف فارغاً نضع اسم افتراضي، وإذا كان رقم الصنف فارغاً نولد رقماً تلقائياً
            $itemNumber = $col0 !== '' ? $col0 : 'ITM-' . rand(10000, 99999);
            $itemName   = $col1 !== '' ? $col1 : $itemNumber;

            // تنظيف أرقام الكمية والسعر من أي فواصل أو عملات مكتوبة داخل الخلية
            $quantity = (int) preg_replace('/[^0-9\-]/', '', $col2);
            $price    = (float) preg_replace('/[^0-9.]/', '', str_replace(',', '.', $col3));

            Item::updateOrCreate(
                ['item_number' => $itemNumber],
                [
                    'item_name' => $itemName,
                    'quantity'  => max(0, $quantity),
                    'price'     => max(0, $price),
                ]
            );

            $importedCount++;
        }

        if ($importedCount === 0) {
            return redirect()->route('inventory.index')
                ->withErrors(['لم يتم العثور على أصناف صالحة داخل الملف! تأكد أن الملف يحتوي على أعمدة: (رقم الصنف، اسم الصنف، الكمية، السعر).']);
        }

        return redirect()->route('inventory.index')
            ->with('success', "تم استيراد وتحديث {$importedCount} صنف في المخزون بنجاح!");
    }

    /**
     * قراءة ملفات الإكسل (.xlsx) مباشرة بدون مكتبات خارجية
     */
    private function parseXlsx(string $filePath): array
    {
        $rows = [];
        $zip = new ZipArchive;

        if ($zip->open($filePath) === true) {
            // قراءة النصوص المشتركة داخل ملف الإكسل (Shared Strings)
            $sharedStrings = [];
            if (($xmlStrings = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
                $xml = simplexml_load_string($xmlStrings);
                if ($xml && isset($xml->si)) {
                    foreach ($xml->si as $val) {
                        if (isset($val->t)) {
                            $sharedStrings[] = (string) $val->t;
                        } elseif (isset($val->r)) {
                            $text = '';
                            foreach ($val->r as $r) {
                                $text .= (string) $r->t;
                            }
                            $sharedStrings[] = $text;
                        } else {
                            $sharedStrings[] = '';
                        }
                    }
                }
            }

            // قراءة الورقة الأولى (Sheet1)
            if (($xmlSheet = $zip->getFromName('xl/worksheets/sheet1.xml')) !== false) {
                $xml = simplexml_load_string($xmlSheet);
                if ($xml && isset($xml->sheetData->row)) {
                    foreach ($xml->sheetData->row as $row) {
                        $rowData = [];
                        $colIndex = 0;

                        foreach ($row->c as $c) {
                            // معرفة ترتيب العمود (A=0, B=1, C=2, D=3)
                            $cellRef = (string) $c['r'];
                            if (preg_match('/([A-Z]+)/', $cellRef, $matches)) {
                                $colLetters = $matches[1];
                                $calculatedIndex = 0;
                                for ($i = 0; $i < strlen($colLetters); $i++) {
                                    $calculatedIndex = $calculatedIndex * 26 + (ord($colLetters[$i]) - 64);
                                }
                                $colIndex = $calculatedIndex - 1;
                            }

                            while (count($rowData) < $colIndex) {
                                $rowData[] = '';
                            }

                            $type = (string) $c['t'];
                            $value = (string) $c->v;

                            if ($type === 's') {
                                $value = $sharedStrings[(int) $value] ?? '';
                            } elseif ($type === 'inlineStr' && isset($c->is->t)) {
                                $value = (string) $c->is->t;
                            }

                            $rowData[$colIndex] = $value;
                            $colIndex++;
                        }
                        $rows[] = $rowData;
                    }
                }
            }
            $zip->close();
        }

        return $rows;
    }

    /**
     * قراءة ملفات CSV و XLS مع اكتشاف نوع الفاصل تلقائياً (, أو ; أو Tab)
     */
    private function parseCsvOrXls(string $filePath): array
    {
        $rows = [];
        $content = file_get_contents($filePath);

        // إذا كان ملف XLS عبارة عن جدول HTML محفوظ من إكسل
        if (stripos($content, '<table') !== false || stripos($content, '<tr') !== false) {
            preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $content, $trMatches);
            foreach ($trMatches[1] as $tr) {
                preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $tr, $tdMatches);
                if (!empty($tdMatches[1])) {
                    $rows[] = array_map(fn($cell) => trim(strip_tags(html_entity_decode($cell))), $tdMatches[1]);
                }
            }
            return $rows;
        }

        $lines = preg_split('/\r\n|\r|\n/', $content);
        if (empty($lines)) {
            return $rows;
        }

        // اكتشاف الفاصل تلقائياً من السطر الأول (فاصلة منقوطة ; أو فاصلة , أو Tab)
        $firstLine = $lines[0];
        $delimiter = ',';
        if (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
            $delimiter = ';';
        } elseif (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
            $delimiter = "\t";
        }

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = str_getcsv($line, $delimiter);
        }

        return $rows;
    }
}
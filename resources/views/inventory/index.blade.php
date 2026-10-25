@extends('layouts.dashboard')

@section('title', 'لوحة التحكم - إدارة المخزون')

@section('content')
<div class="space-y-6">

    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <div>
            <h2 class="text-2xl font-bold text-slate-800">
                المخزون 
                @if(isset($viewingClient))
                    <span class="text-base text-emerald-600 font-semibold">(مخزون العميل: {{ $viewingClient->name }})</span>
                @else
                    <span class="text-base text-slate-500 font-semibold">({{ auth()->user()->role === 'admin' ? 'مخزونك العام' : ' ' }})</span>
                @endif
            </h2>
            <p class="text-sm text-slate-500 mt-1">اكتب في خانة البحث ليتم الفرز تلقائيًا دون الحاجة للضغط على Enter.</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            @if(isset($viewingClient))
                <a href="{{ route('clients.index') }}" class="bg-slate-200 hover:bg-slate-300 text-slate-800 px-4 py-2 rounded-lg text-sm font-semibold transition">
                    🔙 عودة لقائمة العملاء
                </a>
            @endif

            <div class="relative">
                <input type="text" id="live-search" value="{{ request('search') }}"
                       placeholder="ابحث برقم أو اسم الصنف تلقائيًا..."
                       class="w-72 border border-slate-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-emerald-500">
            </div>

            @if(!isset($viewingClient))
                <!-- زر الاسترجاع الفوري بضغطة زر (One-Click Rollback) يظهر تلقائياً عند توفر نقطة استرجاع -->
                <button type="button" id="top-rollback-btn"
                        onclick="handleOneClickRollback()"
                        class="hidden bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-300 px-3.5 py-2 rounded-lg text-sm font-semibold transition flex items-center gap-1.5"
                        title="التراجع الفوري عن آخر عملية استيراد واستعادة المخزون السابق">
                    <span>↩️</span>
                    <span>تراجع عن آخر استيراد</span>
                </button>

                <button type="button"
                        onclick="openSmartExcelModal()"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition flex items-center gap-1.5 shadow-sm">
                    <span>📥</span>
                    <span>استيراد من Excel</span>
                </button>
            @endif
        </div>
    </div>

    <!-- شريط حالة الاستيراد النشط في الخلفية (يعمل حتى لو تم التنقل لصفحات أخرى) -->
    <div id="inline-bg-import-banner" class="hidden bg-indigo-50 border border-indigo-200 rounded-xl p-4 shadow-sm transition-all">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-2">
            <div class="flex items-center gap-2.5">
                <span id="inline-import-pulse" class="w-3 h-3 rounded-full bg-indigo-600 animate-ping"></span>
                <div>
                    <h4 id="inline-import-title" class="text-sm font-bold text-indigo-950">جاري استيراد ومعالجة ملف الإكسل في الخلفية...</h4>
                    <p class="text-xs text-indigo-700">يمكنك تصفح النظام أو الانتقال لأي صفحة أخرى بحرية دون توقف عملية الرفع.</p>
                </div>
            </div>
            <div class="flex items-center gap-3 text-xs font-bold">
                <span class="bg-white text-indigo-900 border border-indigo-200 px-3 py-1 rounded-full">
                    تمت معالجة <span id="inline-import-done" class="text-indigo-600">0</span> من <span id="inline-import-total">0</span>
                </span>
                <span class="bg-emerald-100 text-emerald-800 px-2.5 py-1 rounded-full">
                    جديد: <span id="inline-import-new">0</span>
                </span>
                <span class="bg-blue-100 text-blue-800 px-2.5 py-1 rounded-full">
                    محدث: <span id="inline-import-updated">0</span>
                </span>
            </div>
        </div>
        <div class="w-full bg-indigo-100 rounded-full h-3 overflow-hidden">
            <div id="inline-import-bar" class="bg-indigo-600 h-3 rounded-full transition-all duration-300" style="width: 0%"></div>
        </div>
    </div>

    <!-- نموذج إضافة صنف جديد -->
    @if(auth()->user()->role === 'admin' || !isset($viewingClient))
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <h3 class="text-base font-bold text-slate-700 mb-4">إضافة صنف جديد</h3>

        <form method="POST" action="{{ route('inventory.store') }}" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            @csrf
            <input type="text" name="item_number" value="{{ old('item_number') }}" placeholder="رقم الصنف (فريد)" required
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm uppercase">

            <input type="text" name="item_name" value="{{ old('item_name') }}" placeholder="اسم الصنف / الترجمة" required
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm">

            <input type="number" name="quantity" value="{{ old('quantity') }}" placeholder="الكمية" min="0" required
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm">

            <input type="number" step="0.01" name="price" value="{{ old('price') }}" placeholder="السعر" min="0" required
                   class="border border-slate-300 rounded-lg px-3 py-2 text-sm">

            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold py-2 px-4 rounded-lg text-sm transition">
                + حفظ الصنف
            </button>
        </form>
    </div>
    @endif

    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-600 text-sm">
                        <th class="py-4 px-6 font-bold">رقم الصنف </th>
                        <th class="py-4 px-6 font-bold">اسم الصنف</th>
                        <th class="py-4 px-6 font-bold">الكمية</th>
                        <th class="py-4 px-6 font-bold">السعر</th>
                        <th class="py-4 px-6 font-bold">الإجراءات</th>
                    </tr>
                </thead>

                <tbody id="inventory-table-body" class="divide-y divide-slate-200 text-sm">
                    @include('inventory.partials.table_rows', ['items' => $items])
                </tbody>
            </table>
        </div>

        @if(isset($items) && method_exists($items, 'hasPages') && $items->hasPages())
            <div class="p-4 border-t border-slate-200">
                {{ $items->withQueryString()->links() }}
            </div>
        @endif
    </div>
</div>

<!-- نافذة استيراد الإكسل الذكية (مع المعاينة الذكية للأعمدة + الرفع الخلفي السريع + نقطة الاسترجاع) -->
<div id="excel-modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-xl p-6 w-full max-w-lg space-y-4 border border-slate-100">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div>
                <h3 class="text-lg font-bold text-slate-800">استيراد ذكي وسريع من Excel</h3>
                <p class="text-xs text-slate-500 mt-0.5">يعمل في الخلفية بسرعة فائقة مع حفظ نسخة استرجاع تلقائية قبل الرفع</p>
            </div>
            <button type="button" onclick="closeSmartExcelModal()" class="text-slate-400 hover:text-slate-600 text-xl font-bold">&times;</button>
        </div>

        <form id="excel-import-form" method="POST" action="{{ route('inventory.import') }}" enctype="multipart/form-data" class="space-y-4" onsubmit="return handleExcelSubmit(event)">
            @csrf

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1.5">اختر ملف الإكسل أو CSV (.xlsx, .xls, .csv)</label>
                <input type="file" id="excel-file-input" name="excel_file" accept=".xlsx,.xls,.csv" required
                       onchange="handleExcelFilePreview(this)"
                       class="w-full border border-slate-300 rounded-xl p-2.5 text-sm bg-slate-50 focus:outline-none focus:border-indigo-500">
            </div>

            <!-- قسم الربط الذكي للأعمدة (Smart Column Mapping) يظهر تلقائياً فور اختيار الملف -->
            <div id="smart-column-mapping-box" class="hidden bg-slate-50 p-4 rounded-xl border border-slate-200 space-y-3">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-indigo-700 flex items-center gap-1">
                        ✨ تم التعرف الذكي على أعمدة الملف (<span id="preview-rows-count">0</span> سطر)
                    </span>
                    <span class="text-[11px] text-slate-500">يمكنك تعديل الربط إذا لزم الأمر</span>
                </div>

                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div>
                        <label class="font-semibold text-slate-700 block mb-1">عمود رقم الصنف <span class="text-rose-500">*</span></label>
                        <select id="map-col-number" class="w-full border border-slate-300 rounded-lg px-2 py-1.5 bg-white text-slate-800"></select>
                    </div>
                    <div>
                        <label class="font-semibold text-slate-700 block mb-1">عمود اسم الصنف <span class="text-rose-500">*</span></label>
                        <select id="map-col-name" class="w-full border border-slate-300 rounded-lg px-2 py-1.5 bg-white text-slate-800"></select>
                    </div>
                    <div>
                        <label class="font-semibold text-slate-700 block mb-1">عمود الكمية</label>
                        <select id="map-col-qty" class="w-full border border-slate-300 rounded-lg px-2 py-1.5 bg-white text-slate-800"></select>
                    </div>
                    <div>
                        <label class="font-semibold text-slate-700 block mb-1">عمود السعر</label>
                        <select id="map-col-price" class="w-full border border-slate-300 rounded-lg px-2 py-1.5 bg-white text-slate-800"></select>
                    </div>
                </div>

                <div id="sample-preview-row" class="text-[11px] text-slate-600 bg-white p-2.5 rounded-lg border border-slate-200 truncate"></div>
            </div>

            <!-- شريط التقدم داخل النافذة -->
            <div id="import-progress-box" class="hidden space-y-2 pt-2 border-t border-slate-100">
                <div class="flex justify-between items-center text-xs font-bold">
                    <span id="import-status-label" class="text-indigo-600">جاري إرسال الدفعات في الخلفية...</span>
                    <span class="bg-slate-100 text-slate-800 px-2.5 py-1 rounded-full">
                        تم رفع <span id="import-done-count" class="text-indigo-600">0</span> من <span id="import-total-count">0</span>
                    </span>
                </div>
                <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden">
                    <div id="import-progress-bar" class="bg-indigo-600 h-3 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
                <p class="text-[11px] text-emerald-700 font-semibold text-center pt-1">
                    💡 يمكنك إغلاق هذه النافذة أو الانتقال لأي صفحة أخرى وسيستمر الرفع تلقائياً في الخلفية!
                </p>
            </div>

            <div class="flex justify-between items-center pt-2 border-t border-slate-100">
                <span class="text-[11px] text-slate-500">🛡️ يتم حفظ نقطة استرجاع تلقائية قبل البدء</span>
                <div class="flex gap-2">
                    <button type="button" id="cancel-import-btn" onclick="closeSmartExcelModal()"
                            class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-800 rounded-lg text-sm font-semibold transition">
                        إغلاق / استمرار بالخلفية
                    </button>

                    <button type="submit" id="submit-import-btn" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-semibold transition shadow-sm">
                        🚀 بدء الاستيراد السريع
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- نافذة التعديل (رقم الصنف مقفول readonly لا يمكن تعديله نهائياً من قبل العميل) -->
<div id="edit-modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-lg p-6 w-full max-w-md space-y-4">
        <h3 class="text-lg font-bold text-slate-800">تعديل بيانات الصنف</h3>
        <p class="text-xs text-rose-600 font-semibold">ملاحظة: رقم الصنف محمي وثابت ولا يمكن تعديله بعد إنشائه.</p>

        <form id="edit-form" method="POST" class="space-y-3">
            @csrf
            @method('PUT')

            <div>
                <label for="edit-number" class="text-xs font-semibold text-slate-600">رقم الصنف (غير قابل للتعديل)</label>
                <input type="text" id="edit-number" name="item_number" readonly
                       class="w-full border border-slate-300 bg-slate-100 text-slate-500 cursor-not-allowed rounded-lg px-3 py-2 text-sm mt-1 font-mono">
            </div>

            <div>
                <label for="edit-name" class="text-xs font-semibold text-slate-600">اسم الصنف</label>
                <input type="text" id="edit-name" name="item_name" required
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mt-1">
            </div>

            <div>
                <label for="edit-quantity" class="text-xs font-semibold text-slate-600">الكمية</label>
                <input type="number" id="edit-quantity" name="quantity" min="0" required
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mt-1">
            </div>

            <div>
                <label for="edit-price" class="text-xs font-semibold text-slate-600">السعر</label>
                <input type="number" step="0.01" id="edit-price" name="price" min="0" required
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm mt-1">
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('edit-modal').classList.add('hidden')"
                        class="px-4 py-2 bg-slate-200 rounded-lg text-sm font-semibold">
                    إلغاء
                </button>

                <button type="submit" class="px-4 py-2 bg-amber-500 text-white rounded-lg text-sm font-semibold">
                    حفظ التعديلات
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ودجت عائم احتياطي في أسفل الشاشة في حال كان القالب المستخدم هو layouts.dashboard -->
<div id="floating-import-tracker" class="hidden fixed bottom-5 left-5 z-50 w-80 sm:w-96 bg-white rounded-2xl shadow-2xl border border-indigo-200 p-4 transition-all duration-300">
    <div class="flex items-center justify-between mb-2">
        <div class="flex items-center gap-2">
            <span id="float-import-dot" class="inline-block w-2.5 h-2.5 rounded-full bg-indigo-600 animate-ping"></span>
            <h4 id="float-import-title" class="text-xs font-bold text-slate-800">جاري الرفع في الخلفية...</h4>
        </div>
        <button type="button" onclick="document.getElementById('floating-import-tracker').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
    </div>
    <div class="w-full bg-slate-100 rounded-full h-2 overflow-hidden mb-1.5">
        <div id="float-import-bar" class="bg-indigo-600 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
    </div>
    <div class="flex items-center justify-between text-[11px] text-slate-600 font-semibold">
        <span id="float-import-text">0 / 0</span>
        <span id="float-import-pct" class="text-indigo-600 font-bold">0%</span>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
    let searchTimer = null;
    let parsedSheetRows = [];
    let hasHeaderRow = false;
    let statusMonitorInterval = null;

    const searchInput = document.getElementById('live-search');
    const tableBody = document.getElementById('inventory-table-body');

    // تحديث جدول الأصناف تلقائياً بدون إعادة تحميل الصفحة
    window.refreshInventoryTableSilently = function () {
        const query = searchInput ? searchInput.value : '';
        fetch(`{{ route('inventory.index') }}?search=${encodeURIComponent(query)}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(res => res.json())
        .then(data => {
            if (tableBody && data.html) {
                tableBody.innerHTML = data.html;
            }
        })
        .catch(() => {});
    };

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            const query = this.value;

            searchTimer = setTimeout(() => {
                fetch(`{{ route('inventory.index') }}?search=${encodeURIComponent(query)}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                })
                .then(response => {
                    if (!response.ok) throw new Error('تعذر تحميل النتائج');
                    return response.json();
                })
                .then(data => {
                    if (tableBody && data.html) {
                        tableBody.innerHTML = data.html;
                    }
                })
                .catch(error => console.error(error));
            }, 250);
        });
    }

    function openEditModal(id, number, name, quantity, price) {
        document.getElementById('edit-form').action = `/inventory/${id}`;
        document.getElementById('edit-number').value = number;
        document.getElementById('edit-name').value = name;
        document.getElementById('edit-quantity').value = quantity;
        document.getElementById('edit-price').value = price;
        document.getElementById('edit-modal').classList.remove('hidden');
    }

    function openSmartExcelModal() {
        document.getElementById('excel-modal').classList.remove('hidden');
    }

    function closeSmartExcelModal() {
        document.getElementById('excel-modal').classList.add('hidden');
    }

    // التعرف الذكي على أعمدة الإكسل محلياً وعبر السيرفر
    async function handleExcelFilePreview(input) {
        const mappingBox = document.getElementById('smart-column-mapping-box');
        if (!input.files || !input.files.length || typeof XLSX === 'undefined') {
            mappingBox.classList.add('hidden');
            return;
        }

        try {
            const file = input.files[0];
            const buffer = await file.arrayBuffer();
            const workbook = XLSX.read(buffer, { type: 'array' });
            const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
            const rows = XLSX.utils.sheet_to_json(firstSheet, { header: 1, defval: '' });

            parsedSheetRows = rows.filter(r => Array.isArray(r) && r.some(cell => String(cell).trim() !== ''));
            if (parsedSheetRows.length === 0) {
                mappingBox.classList.add('hidden');
                return;
            }

            const firstRow = parsedSheetRows[0].map(c => String(c ?? '').trim());

            // طلب التعرف الذكي على الأعمدة من السيرفر
            const res = await fetch("{{ route('inventory.import.detect_columns') }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ headers: firstRow })
            });

            let detected = { item_number: 0, item_name: 1, quantity: 2, price: 3 };
            if (res.ok) {
                const data = await res.json();
                if (data.column_mapping) {
                    detected = data.column_mapping;
                }
            }

            // تحديد ما إذا كان السطر الأول عنواناً للأعمدة أم بيانات مباشرة
            const row0Str = firstRow.join(' ').toLowerCase();
            hasHeaderRow = (
                row0Str.includes('رقم') ||
                row0Str.includes('اسم') ||
                row0Str.includes('صنف') ||
                row0Str.includes('كمية') ||
                row0Str.includes('كميه') ||
                row0Str.includes('سعر') ||
                row0Str.includes('رصيد') ||
                row0Str.includes('number') ||
                row0Str.includes('name') ||
                row0Str.includes('code') ||
                row0Str.includes('price') ||
                row0Str.includes('qty')
            );

            const selects = [
                { id: 'map-col-number', key: 'item_number', fallback: 0 },
                { id: 'map-col-name',   key: 'item_name',   fallback: 1 },
                { id: 'map-col-qty',    key: 'quantity',    fallback: 2 },
                { id: 'map-col-price',  key: 'price',       fallback: 3 }
            ];

            const maxCols = Math.max(firstRow.length, 4);
            selects.forEach(selInfo => {
                const selectEl = document.getElementById(selInfo.id);
                selectEl.innerHTML = '';
                for (let c = 0; c < maxCols; c++) {
                    const colTitle = firstRow[c] ? `عمود ${c + 1}: ${firstRow[c]}` : `عمود ${c + 1}`;
                    const opt = document.createElement('option');
                    opt.value = c;
                    opt.textContent = colTitle;
                    const targetIdx = (detected[selInfo.key] !== null && detected[selInfo.key] !== undefined) ? Number(detected[selInfo.key]) : selInfo.fallback;
                    if (c === targetIdx) opt.selected = true;
                    selectEl.appendChild(opt);
                }
                selectEl.onchange = updateSamplePreviewText;
            });

            const actualItemsCount = hasHeaderRow ? Math.max(0, parsedSheetRows.length - 1) : parsedSheetRows.length;
            document.getElementById('preview-rows-count').innerText = actualItemsCount;
            mappingBox.classList.remove('hidden');
            updateSamplePreviewText();
        } catch (err) {
            console.error(err);
        }
    }

    function updateSamplePreviewText() {
        const sampleRow = (hasHeaderRow && parsedSheetRows.length > 1) ? parsedSheetRows[1] : parsedSheetRows[0];
        if (!sampleRow) return;

        const numIdx = Number(document.getElementById('map-col-number').value || 0);
        const nameIdx = Number(document.getElementById('map-col-name').value || 1);
        const qtyIdx = Number(document.getElementById('map-col-qty').value || 2);
        const prcIdx = Number(document.getElementById('map-col-price').value || 3);

        document.getElementById('sample-preview-row').innerHTML =
            `<strong>معاينة لأول صنف:</strong> الرقم: <span class="text-indigo-600 font-mono">${sampleRow[numIdx] ?? '-'}</span> | الاسم: <span class="text-slate-800 font-semibold">${sampleRow[nameIdx] ?? '-'}</span> | الكمية: <span class="text-emerald-600">${sampleRow[qtyIdx] ?? '0'}</span> | السعر: <span class="text-amber-600">${sampleRow[prcIdx] ?? '0'}</span>`;
    }

    // رفع الملف في الخلفية بالكامل أو على دفعات كبيرة فائقة السرعة (400 صنف في الدفعة الواحدة)
    async function handleExcelSubmit(e) {
        e.preventDefault();
        const fileInput = document.getElementById('excel-file-input');
        if (!fileInput.files.length) return false;

        const file = fileInput.files[0];
        const submitBtn = document.getElementById('submit-import-btn');
        const progressBox = document.getElementById('import-progress-box');
        const progressBar = document.getElementById('import-progress-bar');
        const doneCountEl = document.getElementById('import-done-count');
        const totalCountEl = document.getElementById('import-total-count');
        const statusLabel = document.getElementById('import-status-label');

        submitBtn.disabled = true;
        submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
        progressBox.classList.remove('hidden');

        const selectedMapping = {
            item_number: Number(document.getElementById('map-col-number')?.value ?? 0),
            item_name:   Number(document.getElementById('map-col-name')?.value ?? 1),
            quantity:    Number(document.getElementById('map-col-qty')?.value ?? 2),
            price:       Number(document.getElementById('map-col-price')?.value ?? 3)
        };

        // 1. محاولة إرسال الملف بالكامل إلى معالج الخلفية المباشر في السيرفر (بحيث يعمل حتى لو أغلق المستخدم الصفحة فوراً)
        const fileExt = file.name.split('.').pop().toLowerCase();
        if (fileExt === 'csv' || fileExt === 'txt' || typeof XLSX === 'undefined') {
            try {
                const formData = new FormData();
                formData.append('excel_file', file);
                formData.append('column_mapping[item_number]', selectedMapping.item_number);
                formData.append('column_mapping[item_name]', selectedMapping.item_name);
                formData.append('column_mapping[quantity]', selectedMapping.quantity);
                formData.append('column_mapping[price]', selectedMapping.price);

                const res = await fetch("{{ route('inventory.import') }}", {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                });

                if (res.ok) {
                    const data = await res.json();
                    startInventoryStatusMonitor(data.import_job_id);
                    if (typeof window.startGlobalImportPolling === 'function') {
                        window.startGlobalImportPolling(data.import_job_id);
                    }
                    closeSmartExcelModal();
                    return false;
                }
            } catch (err) {
                console.error(err);
            }
        }

        // 2. المعالجة السريعة جداً عبر الدفعات الكبيرة (400 صنف لكل دفعة مع keepalive لعدم التأثر بالتنقل)
        try {
            if (!parsedSheetRows.length && typeof XLSX !== 'undefined') {
                const buffer = await file.arrayBuffer();
                const workbook = XLSX.read(buffer, { type: 'array' });
                const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                parsedSheetRows = XLSX.utils.sheet_to_json(firstSheet, { header: 1, defval: '' });
            }

            const startIdx = hasHeaderRow ? 1 : 0;
            const validItems = [];

            for (let i = startIdx; i < parsedSheetRows.length; i++) {
                const r = parsedSheetRows[i];
                if (!r || !Array.isArray(r)) continue;

                const colNum  = String(r[selectedMapping.item_number] ?? '').trim();
                const colName = String(r[selectedMapping.item_name] ?? '').trim();

                if (colNum === '' || colName === '') continue;

                validItems.push({
                    item_number: colNum,
                    item_name:   colName,
                    quantity:    parseInt(r[selectedMapping.quantity]) || 0,
                    price:       parseFloat(r[selectedMapping.price]) || 0
                });
            }

            const total = validItems.length;
            if (total === 0) {
                alert('لم يتم العثور على أصناف صالحة في الملف، يرجى التأكد من اختيار الأعمدة الصحيحة.');
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                return false;
            }

            totalCountEl.innerText = total;
            doneCountEl.innerText = 0;
            progressBar.style.width = '0%';

            // إظهار شريط الحالة المدمج في الصفحة أيضاً
            startInventoryStatusMonitor();

            // دفعات كبيرة (400 صنف في الطلب الواحد بدلاً من 25) لأن السيرفر يعالجها دفعة واحدة في الذاكرة (RAM Cache Bulk Upsert)
            const batchSize = 400;
            let uploaded = 0;
            let currentJobId = null;

            for (let i = 0; i < total; i += batchSize) {
                const chunk = validItems.slice(i, i + batchSize);
                const isFirst = (i === 0);
                const isLast = (i + batchSize >= total);

                const res = await fetch("{{ route('inventory.import') }}", {
                    method: 'POST',
                    keepalive: true,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        items_batch: chunk,
                        file_name: file.name,
                        total_rows: total,
                        is_first_chunk: isFirst,
                        is_last_chunk: isLast,
                        import_job_id: currentJobId
                    })
                });

                if (res.ok) {
                    const data = await res.json();
                    if (data.import_job_id) {
                        currentJobId = data.import_job_id;
                        if (isFirst && typeof window.startGlobalImportPolling === 'function') {
                            window.startGlobalImportPolling(currentJobId);
                        }
                    }

                    uploaded += chunk.length;
                    if (uploaded > total) uploaded = total;
                    const percent = Math.round((uploaded / total) * 100);
                    doneCountEl.innerText = uploaded;
                    progressBar.style.width = percent + '%';
                    updateInlineBannerProgress(uploaded, total, percent, data.new_items, data.updated_items);
                }
            }

            statusLabel.innerText = '✅ اكتمل الاستيراد بنجاح!';
            window.refreshInventoryTableSilently();
            checkImportStatusAndSnapshot();

            setTimeout(() => {
                closeSmartExcelModal();
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            }, 900);
        } catch (err) {
            console.error(err);
            document.getElementById('excel-import-form').submit();
        }

        return false;
    }

    function updateInlineBannerProgress(done, total, percent) {
        const banner = document.getElementById('inline-bg-import-banner');
        if (!banner) return;
        banner.classList.remove('hidden');
        document.getElementById('inline-import-done').innerText = done;
        document.getElementById('inline-import-total').innerText = total;
        document.getElementById('inline-import-bar').style.width = percent + '%';
    }

    // متابعة حالة الاستيراد في الخلفية وفحص توفر نقطة الاسترجاع الفورية (Rollback Snapshot)
    async function checkImportStatusAndSnapshot() {
        try {
            const res = await fetch("{{ route('inventory.import.status') }}", {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!res.ok) return;
            const data = await res.json();

            // إظهار زر التراجع الفوري إذا كانت هناك نقطة استرجاع محفوظة
            const rollbackBtn = document.getElementById('top-rollback-btn');
            if (rollbackBtn && data.latest_snapshot) {
                rollbackBtn.classList.remove('hidden');
                rollbackBtn.title = `${data.latest_snapshot.label} (${data.latest_snapshot.items_count} صنف)`;
            }

            const job = data.job;
            if (!job) return;

            const banner = document.getElementById('inline-bg-import-banner');
            const floatTracker = document.getElementById('floating-import-tracker');

            if (job.status === 'pending' || job.status === 'processing') {
                banner.classList.remove('hidden');
                document.getElementById('inline-import-title').innerText = `جاري استيراد (${job.file_name || 'ملف الإكسل'}) في الخلفية...`;
                document.getElementById('inline-import-done').innerText = job.processed_rows || 0;
                document.getElementById('inline-import-total').innerText = job.total_rows || 0;
                document.getElementById('inline-import-new').innerText = job.imported_count || 0;
                document.getElementById('inline-import-updated').innerText = job.updated_count || 0;
                document.getElementById('inline-import-bar').style.width = (data.percentage || 0) + '%';

                if (!document.getElementById('global-bg-import-widget')) {
                    floatTracker.classList.remove('hidden');
                    document.getElementById('float-import-bar').style.width = (data.percentage || 0) + '%';
                    document.getElementById('float-import-text').innerText = `${job.processed_rows || 0} / ${job.total_rows || 0} صنف`;
                    document.getElementById('float-import-pct').innerText = (data.percentage || 0) + '%';
                }

                if (!statusMonitorInterval) {
                    statusMonitorInterval = setInterval(checkImportStatusAndSnapshot, 2000);
                }
            } else if (job.status === 'completed') {
                if (statusMonitorInterval) {
                    clearInterval(statusMonitorInterval);
                    statusMonitorInterval = null;
                    document.getElementById('inline-import-title').innerText = '✅ اكتملت معالجة ملف الإكسل في الخلفية بنجاح!';
                    document.getElementById('inline-import-pulse').className = 'w-3 h-3 rounded-full bg-emerald-500';
                    document.getElementById('inline-import-bar').style.width = '100%';
                    document.getElementById('inline-import-bar').className = 'bg-emerald-500 h-3 rounded-full transition-all duration-300';
                    document.getElementById('inline-import-done').innerText = job.processed_rows || 0;
                    document.getElementById('inline-import-total').innerText = job.total_rows || 0;
                    document.getElementById('inline-import-new').innerText = job.imported_count || 0;
                    document.getElementById('inline-import-updated').innerText = job.updated_count || 0;
                    window.refreshInventoryTableSilently();
                }
            }
        } catch (e) {
            // تجاهل الأخطاء العابرة
        }
    }

    function startInventoryStatusMonitor() {
        checkImportStatusAndSnapshot();
        if (!statusMonitorInterval) {
            statusMonitorInterval = setInterval(checkImportStatusAndSnapshot, 2000);
        }
    }

    // دالة التراجع الفوري بضغطة زر (One-Click Rollback)
    async function handleOneClickRollback() {
        if (!confirm('هل أنت متأكد من التراجع عن آخر استيراد إكسل واستعادة حالة المخزون السابقة بالكامل؟')) {
            return;
        }

        const btn = document.getElementById('top-rollback-btn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span>⏳ جاري الاسترجاع...</span>';
        }

        try {
            const res = await fetch("{{ route('inventory.import.rollback') }}", {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({})
            });

            const data = await res.json();
            alert(data.message || 'تمت استعادة المخزون بنجاح.');
            window.location.reload();
        } catch (err) {
            alert('تعذر تنفيذ الاسترجاع الفوري.');
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<span>↩️</span><span>تراجع عن آخر استيراد</span>';
            }
        }
    }

    document.addEventListener('DOMContentLoaded', checkImportStatusAndSnapshot);
</script>
@endsection
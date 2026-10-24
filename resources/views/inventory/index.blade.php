@extends('layouts.dashboard')

@section('title', 'لوحة التحكم - إدارة المخزون')

@section('content')
<div class="space-y-6">

    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <div>
            <h2 class="text-2xl font-bold text-slate-800">
                جدول المخزون 
                @if(isset($viewingClient))
                    <span class="text-base text-emerald-600 font-semibold">(مخزون العميل: {{ $viewingClient->name }})</span>
                @else
                    <span class="text-base text-slate-500 font-semibold">({{ auth()->user()->role === 'admin' ? 'مخزونك العام' : 'مخزونك الخاص' }})</span>
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

            @if(auth()->user()->role === 'admin' && !isset($viewingClient))
                <button type="button"
                        onclick="document.getElementById('excel-modal').classList.remove('hidden')"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">
                    📥 استيراد من Excel
                </button>
            @endif
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
                        <th class="py-4 px-6 font-bold">رقم الصنف (محمي وثابت)</th>
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

<!-- نافذة استيراد الإكسل -->
<div id="excel-modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-lg p-6 w-full max-w-md space-y-4">
        <h3 class="text-lg font-bold text-slate-800">استيراد الأصناف من ملف Excel</h3>

        <p class="text-xs text-slate-500">
            تأكد أن أعمدة الملف بالترتيب التالي:
            <strong class="text-slate-700">رقم الصنف، اسم الصنف، الكمية، السعر.</strong>
        </p>

        <form method="POST" action="{{ route('inventory.import') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <input type="file" name="excel_file" accept=".xlsx,.xls,.csv" required
                   class="w-full border border-slate-300 rounded-lg p-2 text-sm">

            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('excel-modal').classList.add('hidden')"
                        class="px-4 py-2 bg-slate-200 rounded-lg text-sm font-semibold">
                    إلغاء
                </button>

                <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold">
                    بدء الاستيراد
                </button>
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

<script>
    let searchTimer = null;
    const searchInput = document.getElementById('live-search');
    const tableBody = document.getElementById('inventory-table-body');

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
</script>
@endsection
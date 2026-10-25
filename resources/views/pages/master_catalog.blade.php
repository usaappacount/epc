@extends('layouts.dashboard')
@section('title', 'المخزون المشترك - قلب النظام')
@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">
        <div class="flex flex-wrap justify-between items-center gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800">🌐 المخزون المشترك (قلب النظام)</h2>
                <p class="text-sm text-slate-500 mt-1">
                    دمج أرقام الأصناف من جميع العملاء بدون تكرار، وعرض ترجمة أول عميل وإجمالي الكميات. اضغط على <strong>رقم الصنف</strong> لاستعراض التفاصيل والكلمات المفتاحية.
                </p>
            </div>
            <a href="{{ route('master.catalog', ['sync' => 1]) }}"
               onclick="return confirm('هل تريد إعادة فحص ومزامنة جميع الأصناف مع القاموس الآن؟');"
               class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg text-xs font-bold border border-slate-300 transition">
                🔄 مزامنة وتنظيف القاموس
            </a>
        </div>

        <form method="GET" action="{{ route('master.catalog') }}" class="flex flex-wrap gap-3 pt-2">
            <input
                type="text"
                name="search"
                value="{{ request('search') }}"
                placeholder="ابحث برقم الصنف، اسم القطعة (مثل: صدام امامي)، أو اسم السيارة..."
                class="flex-1 min-w-[260px] border border-slate-300 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:border-emerald-600"
            >
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2.5 rounded-lg text-sm font-bold transition">
                🔍 بحث
            </button>
            @if(request()->filled('search'))
                <a href="{{ route('master.catalog') }}" class="bg-slate-200 hover:bg-slate-300 text-slate-700 px-5 py-2.5 rounded-lg text-sm font-semibold transition">
                    إلغاء البحث
                </a>
            @endif
        </form>
    </div>

    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">
        <div class="flex justify-between items-center text-xs text-slate-500 font-semibold">
            <span>إجمالي الأصناف المطابقة: <strong class="text-slate-800">{{ $masterItems->total() }}</strong> صنف</span>
            <span>صفحة {{ $masterItems->currentPage() }} من {{ $masterItems->lastPage() }}</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-600">
                        <th class="py-3 px-4 font-semibold">رقم الصنف</th>
                        <th class="py-3 px-4 font-semibold">اسم الصنف (أول عميل)</th>
                        <th class="py-3 px-4 font-semibold">إجمالي الكمية</th>
                        <th class="py-3 px-4 font-semibold">عدد العملاء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($masterItems as $mItem)
                        <tr class="hover:bg-slate-50 transition">
                            <td class="py-3 px-4">
                                <a href="{{ route('master.item.details', $mItem->item_number) }}"
                                   class="inline-flex items-center gap-2 font-mono font-bold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 px-3 py-1.5 rounded-lg border border-emerald-200 transition">
                                    <span>🔍 {{ $mItem->item_number }}</span>
                                </a>
                            </td>
                            <td class="py-3 px-4 font-bold text-slate-800">{{ $mItem->first_item_name }}</td>
                            <td class="py-3 px-4">
                                <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full font-bold text-xs">
                                    {{ $mItem->total_quantity }} قطعة
                                </span>
                            </td>
                            <td class="py-3 px-4 text-slate-600 text-xs font-semibold">
                                {{ $mItem->clients_count }} عميل
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-8 text-center text-slate-500">لا توجد أصناف مطابقة لبحثك.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pt-4 border-t border-slate-100" dir="ltr">
            {{ $masterItems->links() }}
        </div>
    </div>
</div>
@endsection
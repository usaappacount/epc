@extends('layouts.dashboard')

@section('title', 'المخزون المشترك')

@section('content')
<div class="space-y-6">

    <!-- كرت رأس الصفحة والبحث -->
    <div class="ledger-panel ledger-panel-padding space-y-4">
        <div class="flex flex-wrap justify-between items-center gap-4">
            <div>
                <h2 class="ledger-page-title">
                        <span>المخزون المشترك</span>
                </h2>
                <p class="text-sm text-slate-500 mt-1">
                    أصناف مدمجة لجميع العملاء بدون تكرار. اضغط على <strong>رقم الصنف</strong> لاستعراض التفاصيل والكلمات المفتاحية.
                </p>
            </div>
            <a href="{{ route('master.catalog', ['sync' => 1]) }}"
               onclick="return confirm('هل تريد إعادة فحص ومزامنة جميع الأصناف مع القاموس الآن؟');"
               class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg text-xs font-bold border border-slate-300 transition flex items-center gap-1.5">
                <span>🔄</span>
                <span>مزامنة وتنظيف القاموس</span>
            </a>
        </div>

        <form method="GET" action="{{ route('master.catalog') }}" class="flex flex-wrap gap-2 pt-2">
            <input
                type="text"
                name="search"
                value="{{ request('search') }}"
                placeholder="ابحث برقم الصنف، اسم القطعة، أو السيارة..."
                class="flex-1 min-w-[240px] border border-slate-300 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:border-emerald-600"
            >
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2.5 rounded-lg text-sm font-bold transition shadow-sm">
                🔍 بحث
            </button>
            @if(request()->filled('search'))
                <a href="{{ route('master.catalog') }}" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2.5 rounded-lg text-sm font-semibold transition border border-slate-200">
                    إلغاء البحث
                </a>
            @endif
        </form>
    </div>

    <!-- جدول الأصناف المشتركة -->
    <div class="ledger-panel ledger-panel-padding space-y-4">
        <div class="flex justify-between items-center text-xs text-slate-500 font-semibold border-b border-slate-100 pb-3">
            <span>إجمالي الأصناف: <strong class="text-slate-800">{{ $masterItems->total() }}</strong> صنف</span>
            <span>صفحة {{ $masterItems->currentPage() }} من {{ $masterItems->lastPage() }}</span>
        </div>

        <div class="ledger-table-wrap">
            <table class="ledger-table">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-600">
                        <th class="py-3 px-4 font-semibold">رقم الصنف</th>
                        <th class="py-3 px-4 font-semibold">اسم الصنف</th>
                        <th class="py-3 px-4 font-semibold">إجمالي الكمية</th>
                        <th class="py-3 px-4 font-semibold">عدد المتاجر / العملاء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($masterItems as $mItem)
                        <tr class="hover:bg-slate-50 transition">
                            <td class="py-3 px-4">
                                <a href="{{ route('master.item.details', $mItem->item_number) }}"
                                   class="inline-flex items-center gap-1.5 font-mono font-bold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 px-3 py-1 rounded-lg border border-emerald-200 transition">
                                    <span>🔍</span>
                                    <span>{{ $mItem->item_number }}</span>
                                </a>
                            </td>
                            <td class="py-3 px-4 font-bold text-slate-800">{{ $mItem->first_item_name }}</td>
                            <td class="py-3 px-4">
                                <span class="px-2.5 py-0.5 bg-blue-50 text-blue-800 rounded-full font-bold text-xs">
                                    {{ $mItem->total_quantity }} قطعة
                                </span>
                            </td>
                            <td class="py-3 px-4 text-slate-600 text-xs font-semibold">
                                {{ $mItem->clients_count }} عميل
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-8 text-center text-slate-400">لا توجد أصناف مطابقة لبحثك.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="pt-3 border-t border-slate-100" dir="ltr">
            {{ $masterItems->links() }}
        </div>
    </div>
</div>
@endsection

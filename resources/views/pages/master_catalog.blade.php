@extends('layouts.dashboard')
@section('title', 'المخزون المشترك - قلب النظام')
@section('content')
<div class="space-y-6">
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <h2 class="text-2xl font-bold text-slate-800">🌐 المخزون المشترك الموحد (قلب النظام)</h2>
        <p class="text-sm text-slate-500 mt-1">
            دمج أرقام الأصناف من جميع العملاء بدون تكرار، وعرض ترجمة أول عميل وإجمالي الكميات. اضغط على <strong>رقم الصنف</strong> لاستعراض التفاصيل والكلمات المفتاحية.
        </p>
    </div>

    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-600">
                        <th class="py-3 px-4 font-semibold">رقم الصنف</th>
                        <th class="py-3 px-4 font-semibold">اسم الصنف (أول عميل)</th>
                        <th class="py-3 px-4 font-semibold">إجمالي الكمية</th>
                        <th class="py-3 px-4 font-semibold">عدد العملاء المالكين</th>
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
                            <td colspan="4" class="py-8 text-center text-slate-500">لا توجد أصناف مدمجة حتى الآن.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
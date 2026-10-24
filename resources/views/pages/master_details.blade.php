@extends('layouts.dashboard')
@section('title', 'تفاصيل الصنف المشترك - ' . $itemNumber)
@section('content')
<div class="space-y-6">

    <!-- رأس الصفحة -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-slate-800">
                🔍 تفاصيل الصنف المشترك: <span class="text-emerald-600 font-mono">{{ $itemNumber }}</span>
            </h2>
            <p class="text-sm text-slate-500 mt-1">
                الاسم المسجل بالقلب (أول عميل): <strong class="text-slate-700">{{ $master->first_item_name ?? 'غير محدد' }}</strong>
            </p>
        </div>
        <a href="{{ route('master.catalog') }}" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg text-sm font-bold transition">
            ← العودة للمخزون المشترك
        </a>
    </div>

    @if(session('success'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl font-bold text-sm">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        <!-- القسم الأول: تواجد الصنف لدى العملاء -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">
            <h3 class="text-base font-bold text-slate-800 border-b border-slate-100 pb-3">📦 تواجد الصنف لدى العملاء</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-slate-600">
                            <th class="py-3 px-4 font-semibold">العميل</th>
                            <th class="py-3 px-4 font-semibold">مسمى الصنف لديه</th>
                            <th class="py-3 px-4 font-semibold">الكمية</th>
                            <th class="py-3 px-4 font-semibold">السعر</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($clientHoldings as $holding)
                            <tr class="hover:bg-slate-50 transition">
                                <td class="py-3 px-4 font-bold text-slate-800">{{ $holding->client_name }}</td>
                                <td class="py-3 px-4 text-slate-600">{{ $holding->item_name }}</td>
                                <td class="py-3 px-4">
                                    <span class="px-2.5 py-1 bg-emerald-100 text-emerald-800 rounded-full font-mono font-bold text-xs">
                                        {{ $holding->quantity }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 font-mono text-slate-700">{{ number_format($holding->price, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-6 text-center text-slate-400">لا يوجد مخزون حالي لدى العملاء لهذا الرقم.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- القسم الثاني: الكلمات المفتاحية الذكية للصنف -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">
            <h3 class="text-base font-bold text-slate-800 border-b border-slate-100 pb-3">🔑 الكلمات المفتاحية (للبحث والرد الآلي)</h3>
            
            <form method="POST" action="{{ route('master.item.keyword.add', $itemNumber) }}" class="flex gap-2">
                @csrf
                <input type="text" name="keyword" required placeholder="أضف كلمة مفتاحية أو مرادف جديد..." 
                    class="flex-1 border border-slate-300 rounded-lg px-3.5 py-2 text-sm focus:outline-none focus:border-emerald-500">
                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2 rounded-lg text-sm font-bold transition shadow-sm">
                    إضافة
                </button>
            </form>

            <div class="flex flex-wrap gap-2 pt-2">
                @forelse($keywords as $kw)
                    <span class="bg-slate-100 text-slate-700 border border-slate-200 px-3 py-1.5 rounded-lg text-xs font-bold">
                        # {{ $kw->keyword }}
                    </span>
                @empty
                    <p class="text-sm text-slate-400 py-2">لا توجد كلمات مفتاحية مسجلة لهذا الصنف حتى الآن.</p>
                @endforelse
            </div>
        </div>

    </div>
</div>
@endsection
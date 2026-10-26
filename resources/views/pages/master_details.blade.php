@extends('layouts.dashboard')

@section('title', 'تفاصيل الصنف - ' . $itemNumber)

@section('content')
<div class="space-y-6">

    <!-- شريط الرأس -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                <span>🔍</span>
                <span>الصنف: <span class="text-emerald-700 font-mono">{{ $itemNumber }}</span></span>
            </h2>
            <p class="text-sm text-slate-500 mt-1">
                المسمى الأساسي: <strong class="text-slate-700">{{ $master->first_item_name ?? 'غير محدد' }}</strong>
            </p>
        </div>
        <a href="{{ route('master.catalog') }}" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg text-sm font-semibold transition border border-slate-200 inline-flex items-center gap-1.5">
            <span>←</span>
            <span>العودة للمخزون المشترك</span>
        </a>
    </div>

    @if(session('success'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl font-bold text-sm">
            ✅ {{ session('success') }}
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        
        <!-- تواجد الصنف لدى العملاء -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">
            <h3 class="font-bold text-slate-800 border-b border-slate-100 pb-3 flex items-center gap-2">
                <span>📦</span>
                <span>توفر الصنف لدى العملاء</span>
            </h3>
            <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-slate-600">
                            <th class="py-2.5 px-3 font-semibold">العميل</th>
                            <th class="py-2.5 px-3 font-semibold">المسمى لديه</th>
                            <th class="py-2.5 px-3 font-semibold">الكمية</th>
                            <th class="py-2.5 px-3 font-semibold">السعر</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($clientHoldings as $holding)
                            <tr class="hover:bg-slate-50 transition">
                                <td class="py-2.5 px-3 font-bold text-slate-800">{{ $holding->client_name }}</td>
                                <td class="py-2.5 px-3 text-slate-600 text-xs">{{ $holding->item_name }}</td>
                                <td class="py-2.5 px-3">
                                    <span class="px-2 py-0.5 bg-emerald-50 text-emerald-800 rounded-full font-mono font-bold text-xs border border-emerald-200">
                                        {{ $holding->quantity }}
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 font-mono text-slate-700 text-xs">{{ number_format($holding->price, 2) }} ر.س</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-6 text-center text-slate-400">لا يوجد مخزون حالي لدى العملاء لهذا الصنف.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <!-- الكلمات المفتاحية -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">
            <h3 class="font-bold text-slate-800 border-b border-slate-100 pb-3 flex items-center gap-2">
                <span>🔑</span>
                <span>الكلمات المفتاحية (للبحث والرد الآلي)</span>
            </h3>
            
            <form method="POST" action="{{ route('master.item.keyword.add', $itemNumber) }}" class="flex gap-2">
                @csrf
                <input type="text" name="keyword" required placeholder="أضف كلمة مفتاحية جديدة..." 
                    class="flex-1 border border-slate-300 rounded-lg px-3.5 py-2 text-sm focus:outline-none focus:border-emerald-600">
                <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2 rounded-lg text-sm font-semibold transition shadow-sm whitespace-nowrap">
                    + إضافة
                </button>
            </form>

            <div class="flex flex-wrap gap-2 pt-2">
                @forelse($keywords as $kw)
                    <span class="bg-slate-100 text-slate-700 border border-slate-200 px-3 py-1 rounded-lg text-xs font-semibold">
                        # {{ $kw->keyword }}
                    </span>
                @empty
                    <p class="text-sm text-slate-400 py-3">لا توجد كلمات مفتاحية مسجلة لهذا الصنف حتى الآن.</p>
                @endforelse
            </div>
        </div>

    </div>
</div>
@endsection

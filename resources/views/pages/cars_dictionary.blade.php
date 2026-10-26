@extends('layouts.dashboard')

@section('title', 'قاموس السيارات وسنوات الصنع')

@section('content')
<div class="space-y-6">

    <!-- العنوان العام -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
            <span>🚗</span>
            <span>قاموس السيارات وسنوات الصنع</span>
        </h2>
        <p class="text-sm text-slate-500 mt-1">
            تعريف أسماء السيارات وماركاتها وأنماط السنوات؛ لتصفية الكلمات المفتاحية وتحسين دقة التعرف الآلي.
        </p>
    </div>

    <!-- القسم الأول: تعريف السيارات -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-5">
        <div class="border-b border-slate-100 pb-3">
            <h3 class="font-bold text-slate-800">1. قائمة السيارات الأساسية</h3>
            <p class="text-xs text-slate-500 mt-0.5">أضف اسم السيارة وماركتها، ثم اضغط على الاسم لإدارة المرادفات الخاصة بها.</p>
        </div>

        <form method="POST" action="{{ route('cars.store') }}" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
            @csrf
            <div class="sm:col-span-5">
                <input type="text" name="car_name" required placeholder="اسم السيارة (مثال: يارس صالون)"
                       class="w-full border border-slate-300 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:border-emerald-600">
            </div>
            <div class="sm:col-span-4">
                <input type="text" name="category" required placeholder="الماركة (مثال: تويوتا / TOYOTA)"
                       class="w-full border border-slate-300 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:border-emerald-600">
            </div>
            <div class="sm:col-span-3">
                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition shadow-sm whitespace-nowrap">
                    + إضافة السيارة
                </button>
            </div>
        </form>

        <!-- جدول السيارات -->
        <div class="overflow-x-auto pt-2">
            <table class="w-full text-right border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-600">
                        <th class="py-3 px-4 font-semibold">اسم السيارة</th>
                        <th class="py-3 px-4 font-semibold">الماركة</th>
                        <th class="py-3 px-4 font-semibold">المرادفات المسجلة</th>
                        <th class="py-3 px-4 font-semibold text-center w-28">الإجراء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($cars as $car)
                        @php
                            $aliasesCount = DB::table('car_aliases')->where('car_id', $car->id)->count();
                        @endphp
                        <tr class="hover:bg-slate-50 transition">
                            <td class="py-3 px-4">
                                <a href="{{ route('cars.aliases', $car->id) }}"
                                   class="font-bold text-emerald-700 hover:text-emerald-800 inline-flex items-center gap-1.5 transition">
                                    <span>🚗 {{ $car->car_name }}</span>
                                    <span class="text-[11px] bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-full font-medium">إدارة المرادفات ➔</span>
                                </a>
                            </td>
                            <td class="py-3 px-4 text-slate-700 font-medium">{{ $car->category }}</td>
                            <td class="py-3 px-4">
                                <span class="px-2.5 py-0.5 bg-blue-50 text-blue-700 rounded-full text-xs font-semibold">
                                    {{ $aliasesCount }} مرادف
                                </span>
                            </td>
                            <td class="py-3 px-4 text-center">
                                <form method="POST" action="{{ route('cars.destroy', $car->id) }}" onsubmit="return confirm('هل أنت متأكد من حذف هذه السيارة ومرادفاتها نهائياً؟');" class="inline-block m-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-3 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 rounded-md text-xs font-semibold transition">
                                        حذف
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-8 text-center text-slate-400">لا توجد سيارات مضافة حالياً.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- القسم الثاني: أنماط سنوات الصنع -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-5">
        <div class="border-b border-slate-100 pb-3">
            <h3 class="font-bold text-slate-800">2. أنماط سنوات الصنع (الموديل)</h3>
            <p class="text-xs text-slate-500 mt-0.5">تسجيل صيغ كتابة السنوات ليتعرف عليها النظام بذكاء (مثال: 14-18 أو 14/25).</p>
        </div>

        <form method="POST" action="{{ route('year.pattern.store') }}" class="flex flex-col sm:flex-row gap-3">
            @csrf
            <div class="flex-1">
                <input type="text" name="pattern_example" required placeholder="اكتب صيغة النمط (مثال: 14-18 أو 98-97)"
                       class="w-full border border-slate-300 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:border-slate-800 font-mono">
            </div>
            <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white px-6 py-2.5 rounded-lg text-sm font-semibold transition shadow-sm whitespace-nowrap">
                + حفظ النمط
            </button>
        </form>

        <!-- جدول الأنماط -->
        <div class="overflow-x-auto pt-2">
            <table class="w-full text-right border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-600">
                        <th class="py-3 px-4 font-semibold">صيغة النمط</th>
                        <th class="py-3 px-4 font-semibold">تاريخ الإضافة</th>
                        <th class="py-3 px-4 font-semibold text-center w-28">الإجراء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($yearPatterns as $pattern)
                        <tr class="hover:bg-slate-50 transition">
                            <td class="py-3 px-4 font-mono font-bold text-slate-800">{{ $pattern->pattern_example }}</td>
                            <td class="py-3 px-4 text-slate-400 text-xs">{{ $pattern->created_at }}</td>
                            <td class="py-3 px-4 text-center">
                                <form method="POST" action="{{ route('year.pattern.destroy', $pattern->id) }}" onsubmit="return confirm('حذف هذا النمط؟');" class="inline-block m-0">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-3 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 rounded-md text-xs font-semibold transition">
                                        حذف
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-6 text-center text-slate-400">لا توجد أنماط مسجلة بعد.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection

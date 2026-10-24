@extends('layouts.dashboard')

@section('title', 'إعدادات السيارات وسنوات الصنع - الـ AI')

@section('content')
<div class="space-y-8">

    <!-- العنوان العام -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <h2 class="text-2xl font-bold text-slate-800">🚗اعدادات التعريف بالسيارات</h2>
        <p class="text-sm text-slate-500 mt-1">
            النظام يقسم الإعدادات لقسمين: تعريف السيارات وفئاتها ومرادفاتها، وقسم أنماط سنوات الصنع. وعند تحليل الأصناف، يتم استبعاد أسماء السيارات والسنوات من الكلمات المفتاحية تلقائياً.
        </p>
    </div>

    <!-- ================= القسم الأول: التعريف بالسيارات والفئات ================= -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-6">
        <div class="border-b border-slate-100 pb-4">
            <h3 class="text-lg font-bold text-slate-800">📌 القسم الأول: التعريف بالسيارات والفئات الأساسية</h3>
            <p class="text-xs text-slate-500 mt-1">أدخل اسم السيارة والفئة (مثال: الاسم: يارس - صالون، الفئة: سيدان). بعد إضافتها في الجدول، اضغط على اسم السيارة لإضافة المرادفات.</p>
        </div>

        <form method="POST" action="{{ route('cars.store') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">اسم السيارة</label>
                <input type="text" name="car_name" required placeholder="مثال: يارس - صالون"
                       class="w-full border border-slate-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">ماركة السيارة</label>
                <input type="text" name="category" required placeholder="مثال: TOYOTA / CHANGAN"
                       class="w-full border border-slate-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-emerald-500">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2 rounded-lg text-sm font-semibold transition">
                    + إضافة السيارة للقائمة
                </button>
            </div>
        </form>

        <!-- جدول السيارات -->
        <div class="overflow-x-auto pt-2">
            <table class="w-full text-right border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-600">
                        <th class="py-3 px-4 font-semibold">اسم السيارة </th>
                        <th class="py-3 px-4 font-semibold">ماركة السيارة</th>
                        <th class="py-3 px-4 font-semibold">عدد المرادفات المسجلة</th>
                        <th class="py-3 px-4 font-semibold">الاجراء</th>
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
                                   class="font-bold text-emerald-700 hover:underline flex items-center gap-1.5">
                                    <span>🚗 {{ $car->car_name }}</span>
                                    <span class="text-xs bg-emerald-100 text-emerald-800 px-2 py-0.5 rounded-full">إدارة المرادفات ➔</span>
                                </a>
                            </td>
                            <td class="py-3 px-4 font-semibold text-slate-700">{{ $car->category }}</td>
                            <td class="py-3 px-4">
                                <span class="px-2.5 py-1 bg-blue-50 text-blue-700 rounded-full text-xs font-bold">
                                    {{ $aliasesCount }} مرادف
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                <form method="POST" action="{{ route('cars.destroy', $car->id) }}" onsubmit="return confirm('هل أنت متأكد من حذف هذه السيارة ومرادفاتها نهائياً؟');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-3 py-1 bg-rose-100 hover:bg-rose-200 text-rose-700 rounded text-xs font-semibold">
                                        حذف السيارة
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-8 text-center text-slate-400">لم يتم إضافة أي سيارات أساسية حتى الآن.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>


    <!-- ================= القسم الثاني: أنماط سنوات الصنع ================= -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-6">
        <div class="border-b border-slate-100 pb-4">
            <h3 class="text-lg font-bold text-slate-800">📅 القسم الثاني: تعريف أنماط سنوات الصنع للـ AI</h3>
            <p class="text-xs text-slate-500 mt-1">
                عرف النظام بأشكال وأنماط كتابة السنوات المختلفة (مثل: <code>14-18</code> لتعني 2014-2018، أو <code>98-97</code>، أو <code>14 / 25</code>).
            </p>
        </div>

        <form method="POST" action="{{ route('year.pattern.store') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @csrf
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-600 mb-1">نمط سنة الصنع (مثال مكتوب)</label>
                <input type="text" name="pattern_example" required placeholder="مثال: 14-18 أو 98-97 أو 14 / 25"
                       class="w-full border border-slate-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-emerald-500 font-mono">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full bg-slate-800 hover:bg-slate-900 text-white px-6 py-2 rounded-lg text-sm font-semibold transition">
                    + حفظ النمط للـ AI
                </button>
            </div>
        </form>

        <!-- جدول الأنماط -->
        <div class="overflow-x-auto pt-2">
            <table class="w-full text-right border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-600">
                        <th class="py-3 px-4 font-semibold">النمط المسجل</th>
                        <th class="py-3 px-4 font-semibold">تاريخ الإضافة</th>
                        <th class="py-3 px-4 font-semibold">إجراء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($yearPatterns as $pattern)
                        <tr class="hover:bg-slate-50">
                            <td class="py-3 px-4 font-mono font-bold text-slate-800">{{ $pattern->pattern_example }}</td>
                            <td class="py-3 px-4 text-slate-400 text-xs">{{ $pattern->created_at }}</td>
                            <td class="py-3 px-4">
                                <form method="POST" action="{{ route('year.pattern.destroy', $pattern->id) }}" onsubmit="return confirm('حذف هذا النمط؟');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-rose-600 hover:text-rose-800 text-xs font-semibold">حذف</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-6 text-center text-slate-400">لم يتم إدخال أي أنماط سنوات بعد.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
@extends('layouts.dashboard')

@section('title', 'إدارة مرادفات السيارة - ' . $car->car_name)

@section('content')
<div class="space-y-8">

    <!-- العنوان العام وزر الرجوع -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-800">
                🚗 مرادفات السيارة: <span class="text-emerald-600">{{ $car->car_name }}</span>
            </h2>
            <p class="text-sm text-slate-500 mt-1">
                ماركة السيارة / الفئة: <span class="font-semibold text-slate-700">{{ $car->category }}</span> — أضف جميع الأسماء البديلة أو المرادفات التي قد يكتبها العميل لهذه السيارة.
            </p>
        </div>
        <div>
            <a href="{{ route('cars.dictionary') }}" class="inline-flex items-center gap-2 bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg text-sm font-semibold transition">
                <span>➔</span>
                <span>العودة لقائمة السيارات</span>
            </a>
        </div>
    </div>

    <!-- قسم إضافة وعرض المرادفات -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-6">
        <div class="border-b border-slate-100 pb-4">
            <h3 class="text-lg font-bold text-slate-800">📌 إضافة مرادف جديد</h3>
            <p class="text-xs text-slate-500 mt-1">أدخل الاسم البديل أو طريقة الكتابة المختلفة للسيارة (مثال: ياريس، Yaris، كرولا، Corolla).</p>
        </div>

        <form method="POST" action="{{ route('cars.aliases.store', $car->id) }}" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            @csrf
            <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-slate-600 mb-1">المرادف (الاسم البديل)</label>
                <input type="text" name="alias" required placeholder="أدخل المرادف هنا..."
                       class="w-full border border-slate-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-emerald-500">
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2 rounded-lg text-sm font-semibold transition">
                    + إضافة المرادف
                </button>
            </div>
        </form>

        <!-- جدول المرادفات المسجلة -->
        <div class="overflow-x-auto pt-2">
            <table class="w-full text-right border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-600">
                        <th class="py-3 px-4 font-semibold">#</th>
                        <th class="py-3 px-4 font-semibold">المرادف المسجل</th>
                        <th class="py-3 px-4 font-semibold">تاريخ الإضافة</th>
                        <th class="py-3 px-4 font-semibold">الإجراء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($aliases as $index => $alias)
                        <tr class="hover:bg-slate-50 transition">
                            <td class="py-3 px-4 text-slate-500">{{ $index + 1 }}</td>
                            <td class="py-3 px-4 font-bold text-slate-800">{{ $alias->alias }}</td>
                            <td class="py-3 px-4 text-slate-400 text-xs">{{ $alias->created_at }}</td>
                            <td class="py-3 px-4">
                                <form method="POST" action="{{ route('cars.aliases.delete', $alias->id) }}" onsubmit="return confirm('هل أنت متأكد من حذف هذا المرادف؟');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-3 py-1 bg-rose-100 hover:bg-rose-200 text-rose-700 rounded text-xs font-semibold">
                                        حذف
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-8 text-center text-slate-400">لم يتم إضافة أي مرادفات لهذه السيارة حتى الآن.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection

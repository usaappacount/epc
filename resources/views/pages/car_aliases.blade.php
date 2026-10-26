@extends('layouts.dashboard')

@section('title', 'مرادفات السيارة - ' . $car->car_name)

@section('content')
<div class="space-y-6">

    <!-- شريط العنوان والرجوع -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                <span>🚗</span>
                <span>مرادفات: <span class="text-emerald-700 font-extrabold">{{ $car->car_name }}</span></span>
            </h2>
            <p class="text-sm text-slate-500 mt-1">
                الماركة: <strong class="text-slate-700">{{ $car->category }}</strong> — الأسماء البديلة التي قد يستخدمها العميل في البحث.
            </p>
        </div>
        <div>
            <a href="{{ route('cars.dictionary') }}" class="inline-flex items-center gap-2 bg-slate-100 hover:bg-slate-200 text-slate-700 px-4 py-2 rounded-lg text-sm font-semibold transition border border-slate-200">
                <span>←</span>
                <span>العودة لقائمة السيارات</span>
            </a>
        </div>
    </div>

    <!-- إضافة وعرض المرادفات -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-5">
        <div class="border-b border-slate-100 pb-3">
            <h3 class="font-bold text-slate-800">إضافة مرادف جديد</h3>
            <p class="text-xs text-slate-500 mt-0.5">اكتب الاسم البديل بالعربي أو الإنجليزي (مثل: ياريس، Yaris).</p>
        </div>

        <form method="POST" action="{{ route('cars.aliases.store', $car->id) }}" class="flex flex-col sm:flex-row gap-3">
            @csrf
            <div class="flex-1">
                <input type="text" name="alias" required placeholder="اكتب المرادف هنا..."
                       class="w-full border border-slate-300 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:border-emerald-600">
            </div>
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2.5 rounded-lg text-sm font-semibold transition shadow-sm whitespace-nowrap">
                + إضافة المرادف
            </button>
        </form>

        <!-- جدول المرادفات -->
        <div class="overflow-x-auto pt-2">
            <table class="w-full text-right border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-600">
                        <th class="py-3 px-4 font-semibold w-16">#</th>
                        <th class="py-3 px-4 font-semibold">المرادف</th>
                        <th class="py-3 px-4 font-semibold">تاريخ الإضافة</th>
                        <th class="py-3 px-4 font-semibold text-center w-28">الإجراء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($aliases as $index => $alias)
                        <tr class="hover:bg-slate-50 transition">
                            <td class="py-3 px-4 text-slate-400">{{ $index + 1 }}</td>
                            <td class="py-3 px-4 font-bold text-slate-800">{{ $alias->alias }}</td>
                            <td class="py-3 px-4 text-slate-400 text-xs">{{ $alias->created_at }}</td>
                            <td class="py-3 px-4 text-center">
                                <form method="POST" action="{{ route('cars.aliases.delete', $alias->id) }}" onsubmit="return confirm('هل أنت متأكد من حذف هذا المرادف؟');" class="inline-block m-0">
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
                            <td colspan="4" class="py-8 text-center text-slate-400">لا توجد مرادفات مسجلة لهذه السيارة حتى الآن.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection

@extends('layouts.dashboard')

@section('title', 'إدارة العملاء')

@section('content')
<div class="space-y-6">

    <!-- 1. كرت إضافة عميل جديد -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800">👥العملاء </h2>
                <p class="text-sm text-slate-500 mt-1">
                    إضافة العملاء بصيغة الجوال المعتمدة (050 XXX XXXX) وتوليد كود التحقق المؤقت للواتساب (4 أرقام).
                </p>
            </div>
            <a href="{{ route('login') }}" target="_blank" class="bg-slate-800 hover:bg-slate-900 text-white px-4 py-2 rounded-lg text-xs font-semibold transition">
                📱 فتح شاشة دخول العملاء بالـ OTP
            </a>
        </div>

        <form method="POST" action="{{ route('clients.store') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4 pt-3 border-t border-slate-100">
            @csrf
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">اسم العميل</label>
                <input type="text" name="name" required placeholder="مثال: مؤسسة إبراهيم لقطع الغيار"
                    class="w-full border border-slate-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-emerald-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">رقم الجوال (مرتبط بالواتساب)</label>
                <input type="text" name="phone" required dir="ltr" placeholder="050 XXX XXXX"
                    oninput="formatSaudiPhone(this)" maxlength="12"
                    class="w-full border border-slate-300 rounded-lg px-4 py-2 text-sm font-mono text-left focus:outline-none focus:border-emerald-500">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">المدينة</label>
                <input type="text" name="city" required placeholder="مثال: الرياض"
                    class="w-full border border-slate-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-emerald-500">
            </div>

            <div class="flex items-end">
                <button type="submit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2 rounded-lg text-sm font-semibold transition">
                    + إضافة العميل وتوليد كود التحقق
                </button>
            </div>
        </form>
    </div>

    <!-- 2. جدول بيانات العملاء -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-slate-800">📋 جدول بيانات العملاء</h3>
            <span class="text-xs bg-emerald-100 text-emerald-800 px-3 py-1 rounded-full font-semibold">
                إجمالي العملاء: {{ isset($clients) ? $clients->count() : 0 }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-600">
                        <th class="py-3 px-4 font-semibold">اسم العميل</th>
                        <th class="py-3 px-4 font-semibold">رقم الجوال</th>
                        <th class="py-3 px-4 font-semibold">المدينة</th>
                        <th class="py-3 px-4 font-semibold">كود التحقق المؤقت (OTP)</th>
                        <th class="py-3 px-4 font-semibold">عدد الأصناف</th>
                        <th class="py-3 px-4 font-semibold">الحالة</th>
                        <th class="py-3 px-4 font-semibold">المخزون الخاص</th>
                        <th class="py-3 px-4 font-semibold">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @if(isset($clients))
                        @forelse($clients as $client)
                            <tr class="hover:bg-slate-50 transition">
                                <td class="py-3 px-4 font-bold text-slate-800">{{ $client->name }}</td>
                                <td class="py-3 px-4 font-mono text-slate-700" dir="ltr">{{ $client->phone }}</td>
                                <td class="py-3 px-4 text-slate-600">{{ $client->city }}</td>
                                <td class="py-3 px-4">
                                    <span class="px-3 py-1 bg-amber-100 text-amber-800 font-mono font-bold rounded-md text-sm tracking-widest">
                                        {{ $client->otp_code ?? '----' }}
                                    </span>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="px-2.5 py-1 bg-blue-50 text-blue-700 rounded-full text-xs font-bold">
                                        {{ $client->items_count }} صنف
                                    </span>
                                </td>
                                <td class="py-3 px-4">
                                    @if($client->is_active)
                                        <span class="px-2.5 py-1 bg-emerald-100 text-emerald-700 rounded-full text-xs font-semibold">نشط</span>
                                    @else
                                        <span class="px-2.5 py-1 bg-rose-100 text-rose-700 rounded-full text-xs font-semibold">موقوف</span>
                                    @endif
                                </td>
                                <td class="py-3 px-4">
                                    <a href="{{ route('inventory.index', ['client_id' => $client->id]) }}"
                                       class="inline-flex items-center gap-1 bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg text-xs font-semibold transition">
                                        📦 المخزون
                                    </a>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-2">
                                        <button type="button"
                                            onclick="openEditClientModal({{ $client->id }}, '{{ addslashes($client->name) }}', '{{ $client->phone }}', '{{ addslashes($client->city) }}')"
                                            class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded text-xs font-semibold">
                                            تعديل
                                        </button>

                                        <form method="POST" action="{{ route('clients.toggle', $client->id) }}">
                                            @csrf
                                            <button type="submit" class="px-2.5 py-1 {{ $client->is_active ? 'bg-amber-100 text-amber-800 hover:bg-amber-200' : 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200' }} rounded text-xs font-semibold">
                                                {{ $client->is_active ? 'إيقاف' : 'تفعيل' }}
                                            </button>
                                        </form>

                                        <form method="POST" action="{{ route('clients.destroy', $client->id) }}" onsubmit="return confirm('هل أنت متأكد من حذف هذا العميل وجميع أصنافه؟');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="px-2.5 py-1 bg-rose-100 hover:bg-rose-200 text-rose-700 rounded text-xs font-semibold">
                                                حذف
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-8 text-center text-slate-500">لا يوجد عملاء مسجلون حالياً. قم بإضافة أول عميل من الأعلى.</td>
                            </tr>
                        @endforelse
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- نافذة تعديل بيانات العميل -->
<div id="editClientModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl p-6 w-full max-w-md space-y-4 shadow-xl">
        <h3 class="text-lg font-bold text-slate-800">✏️ تعديل بيانات العميل</h3>
        <form id="editClientForm" method="POST" class="space-y-3">
            @csrf
            @method('PUT')
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">اسم العميل</label>
                <input type="text" name="name" id="edit_name" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">رقم الجوال</label>
                <input type="text" name="phone" id="edit_phone" required dir="ltr" oninput="formatSaudiPhone(this)" maxlength="12" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm font-mono text-left">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-1">المدينة</label>
                <input type="text" name="city" id="edit_city" required class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="document.getElementById('editClientModal').classList.add('hidden')" class="px-4 py-2 bg-slate-200 rounded-lg text-sm">إلغاء</button>
                <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold">حفظ التعديلات</button>
            </div>
        </form>
    </div>
</div>

<script>
function formatSaudiPhone(input) {
    let digits = input.value.replace(/\D/g, '').substring(0, 10);
    let formatted = '';
    if (digits.length > 0) formatted += digits.substring(0, 3);
    if (digits.length > 3) formatted += ' ' + digits.substring(3, 6);
    if (digits.length > 6) formatted += ' ' + digits.substring(6, 10);
    input.value = formatted;
}

function openEditClientModal(id, name, phone, city) {
    document.getElementById('editClientForm').action = '/clients/' + id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_phone').value = phone;
    document.getElementById('edit_city').value = city;
    const modal = document.getElementById('editClientModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
</script>
@endsection
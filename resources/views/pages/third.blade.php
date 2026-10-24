@extends('layouts.dashboard')

@section('title', 'إعدادات الربط API والـ Webhook')

@section('content')
<div class="space-y-6">

    <!-- رسائل النجاح أو الخطأ -->
    @if(session('success'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl font-bold text-sm shadow-sm">
            ✅ {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl font-bold text-sm shadow-sm">
            ❌ يرجى التأكد من اختيار ملف قاعدة بيانات صالح (.sqlite).
        </div>
    @endif

    <!-- كرت إعدادات الـ Webhook -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <h2 class="text-2xl font-bold text-slate-800">
            ⚙️ إعدادات الربط API والـ Webhook
        </h2>

        <p class="text-sm text-slate-500 mt-1">
            استخدم هذا الرابط في منصة الواتساب (GreatWhats) لربط الرسائل والأحداث الواردة تلقائياً.
        </p>

        <div class="mt-4 flex gap-3">
            <input
                type="text"
                readonly
                id="webhookInput"
                value="{{ url('/api/webhook/receiver') }}"
                class="flex-1 border border-slate-300 rounded-lg px-4 py-2 text-sm bg-slate-50 font-mono text-slate-700 focus:outline-none"
            >

            <button
                type="button"
                onclick="navigator.clipboard.writeText(document.getElementById('webhookInput').value); alert('تم نسخ الرابط بنجاح!');"
                class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2 rounded-lg text-sm font-semibold transition">
                نسخ الرابط
            </button>
        </div>
    </div>

    <!-- كرت النسخة الاحتياطية واستعادة البيانات -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 flex flex-wrap justify-between items-center gap-4">
        <div>
            <h3 class="font-bold text-slate-800">💾 النسخ الاحتياطي لقاعدة البيانات</h3>
            <p class="text-xs text-slate-500 mt-1">تحميل نسخة احتياطية من الأصناف والسجلات إلى جهازك بصيغة SQLite أو استعادة نسخة سابقة.</p>
        </div>

        <div class="flex items-center gap-3">
            <!-- زر تحميل النسخة الاحتياطية -->
            <a href="{{ route('backup.download') }}" 
               class="bg-slate-800 hover:bg-slate-900 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition shadow-sm inline-flex items-center gap-2">
                <span>⬇️</span> تحميل النسخة
            </a>

            <!-- زر استعادة النسخة الاحتياطية الجديد -->
            <form action="{{ route('backup.restore') }}" method="POST" enctype="multipart/form-data" class="inline-block">
                @csrf
                <label class="cursor-pointer bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition shadow-sm inline-flex items-center gap-2">
                    <span>⬆️</span> استعادة النسخة
                    <input type="file" name="backup_file" accept=".sqlite,.db" class="hidden" 
                           onchange="if(confirm('هل أنت متأكد من استبدال قاعدة البيانات الحالية بالملف المختار؟ سيتم تحديث جميع بيانات النظام فوراً.')) { this.form.submit(); } else { this.value = ''; }">
                </label>
            </form>
        </div>
    </div>

    <!-- كرت سجلات الـ Webhook -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">
        <h3 class="font-bold text-slate-700">
            📋 سجلات العمليات والطلبات الواردة (Webhook Logs):
        </h3>

        @if(isset($webhookLogs) && $webhookLogs->count() > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-slate-500 text-xs">
                            <th class="py-2.5 px-3">نوع الحدث</th>
                            <th class="py-2.5 px-3">البيانات (Payload)</th>
                            <th class="py-2.5 px-3">الوقت</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($webhookLogs as $log)
                            <tr>
                                <td class="py-3 px-3 font-semibold text-emerald-600">{{ $log->event_type }}</td>
                                <td class="py-3 px-3 text-slate-600 font-mono text-xs max-w-md truncate">{{ $log->payload }}</td>
                                <td class="py-3 px-3 text-slate-400 text-xs whitespace-nowrap">{{ $log->created_at }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-slate-500 py-4 text-center">
                لا توجد طلبات أو سجلات واردة حتى الآن.
            </p>
        @endif
    </div>

</div>
@endsection
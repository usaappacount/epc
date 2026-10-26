@extends('layouts.dashboard')

@section('title', 'الإعدادات العامة')

@section('content')
<div class="space-y-6">

    @if(session('success'))
        <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl font-bold text-sm shadow-sm flex items-center gap-2">
            <span>✅</span>
            <span>{{ session('success') }}</span>
        </div>
    @endif

    @if($errors->any())
        <div class="p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl font-bold text-sm shadow-sm flex items-center gap-2">
            <span>❌</span>
            <span>يرجى اختيار ملف قاعدة بيانات صالح بصيغة (.sqlite).</span>
        </div>
    @endif

    <!-- إعدادات رابط الويب هوك -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
            <span>⚙️</span>
            <span>رابط الويب هوك (Webhook)</span>
        </h2>
        <p class="text-sm text-slate-500 mt-1">
            الرابط المعتمد لاستقبال الرسائل وتحديثات النظام تلقائياً.
        </p>

        <div class="mt-4 flex flex-col sm:flex-row gap-3">
            <input
                type="text"
                readonly
                id="webhookInput"
                value="{{ url('/api/webhook/receiver') }}"
                class="flex-1 border border-slate-300 rounded-lg px-4 py-2.5 text-sm bg-slate-50 font-mono text-slate-700 focus:outline-none"
            >
            <button
                type="button"
                onclick="navigator.clipboard.writeText(document.getElementById('webhookInput').value); alert('تم نسخ الرابط بنجاح!');"
                class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2.5 rounded-lg text-sm font-semibold transition flex items-center justify-center gap-2 shadow-sm whitespace-nowrap">
                <span>📋</span>
                <span>نسخ الرابط</span>
            </button>
        </div>
    </div>

    <!-- النسخ الاحتياطي واستعادة البيانات -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h3 class="font-bold text-slate-800 flex items-center gap-2">
                <span>💾</span>
                <span>النسخ الاحتياطي لقاعدة البيانات</span>
            </h3>
            <p class="text-xs text-slate-500 mt-1">
                تصدير نسخة كاملة من البيانات أو استعادة نسخة سابقة بصيغة SQLite.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('backup.download') }}" 
               class="bg-slate-800 hover:bg-slate-900 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition shadow-sm inline-flex items-center gap-2">
                <span>⬇️</span>
                <span>تحميل نسخة</span>
            </a>

            <form action="{{ route('backup.restore') }}" method="POST" enctype="multipart/form-data" class="inline-block m-0">
                @csrf
                <label class="cursor-pointer bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded-lg text-sm font-semibold transition shadow-sm inline-flex items-center gap-2">
                    <span>⬆️</span>
                    <span>استعادة نسخة</span>
                    <input type="file" name="backup_file" accept=".sqlite,.db" class="hidden" 
                           onchange="if(confirm('هل أنت متأكد من استبدال قاعدة البيانات الحالية بالملف المختار؟ سيتم تحديث جميع بيانات النظام فوراً.')) { this.form.submit(); } else { this.value = ''; }">
                </label>
            </form>
        </div>
    </div>

    <!-- سجلات الويب هوك -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">
        <h3 class="font-bold text-slate-800 flex items-center gap-2">
            <span>📋</span>
            <span>سجل العمليات الواردة (Webhook Logs)</span>
        </h3>

        @if(isset($webhookLogs) && $webhookLogs->count() > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-slate-600 text-xs">
                            <th class="py-3 px-4 font-semibold">نوع الحدث</th>
                            <th class="py-3 px-4 font-semibold">البيانات الواردة</th>
                            <th class="py-3 px-4 font-semibold">الوقت</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($webhookLogs as $log)
                            <tr class="hover:bg-slate-50 transition">
                                <td class="py-3 px-4 font-semibold text-emerald-700">{{ $log->event_type }}</td>
                                <td class="py-3 px-4 text-slate-600 font-mono text-xs max-w-md truncate">{{ $log->payload }}</td>
                                <td class="py-3 px-4 text-slate-400 text-xs whitespace-nowrap">{{ $log->created_at }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-sm text-slate-400 py-6 text-center">
                لا توجد سجلات واردة حتى الآن.
            </p>
        @endif
    </div>

</div>
@endsection

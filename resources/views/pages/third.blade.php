<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight text-right">
            {{ __('إعدادات الربط API') }}
        </h2>
    </x-slot>

    <div class="py-12" dir="rtl">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            <!-- قسم معلومات رابط الـ Webhook -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-bold mb-2 text-gray-900">🔗 رابط استقبال الـ Webhook (Webhook URL)</h3>
                <p class="text-gray-600 text-sm mb-4">استخدم هذا الرابط في إعدادات منصة الواتساب (GreatWhats) لربط الرسائل والأحداث:</p>
                
                <div class="flex items-center gap-2 bg-gray-100 p-3 rounded-md border border-gray-300">
                    <input type="text" readonly value="{{ url('/api/webhook/receiver') }}" class="w-full bg-transparent border-none text-gray-800 focus:ring-0 text-sm" id="webhookUrl">
                    <button onclick="navigator.clipboard.writeText(document.getElementById('webhookUrl').value); alert('تم نسخ الرابط بنجاح!');" class="bg-blue-600 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700 transition">
                        نسخ الرابط
                    </button>
                </div>
            </div>

            <!-- قسم سجلات الـ Webhook الواردة -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-bold mb-4 text-gray-900">📋 سجلات الطلبات الواردة (Webhook Logs)</h3>
                
                @if(isset($webhookLogs) && $webhookLogs->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-right">
                            <thead class="bg-gray-50">
                                رقم الحدث
                                <tr>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">نوع الحدث</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">البيانات المستقبلية (Payload)</th>
                                    <th class="px-6 py-3 text-xs font-medium text-gray-500 uppercase">الوقت</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200 text-sm">
                                @foreach($webhookLogs as $log)
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap font-semibold text-blue-600">{{ $log->event_type }}</td>
                                        <td class="px-6 py-4 text-gray-600 max-w-xs truncate" title="{{ $log->payload }}">{{ $log->payload }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-gray-400 text-xs">{{ $log->created_at }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-gray-500 text-center py-6">لا توجد سجلات وتفاعلات ورادت حتى الآن.</p>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>

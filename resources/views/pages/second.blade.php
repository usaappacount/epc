
@extends('layouts.dashboard')

@section('title', 'مساعد الذكاء الاصطناعي والواتساب')

@section('content')
<div class="space-y-6">

    <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
        <h2 class="text-2xl font-bold text-slate-800">
            🤖 مساعد الذكاء الاصطناعي والواتساب
        </h2>

        <p class="text-sm text-slate-500 mt-1">
            هذه الصفحة مهيأة لربط WhatsApp API مع المخزون.
        </p>

        <form method="GET" action="{{ route('pages.second') }}" class="mt-4 flex gap-3">
            <input
                type="text"
                name="message"
                value="{{ $customerMessage }}"
                placeholder="اسال عن اي قطعة في مخزونك سواء برقم الصنف او اسم الصنف "
                class="flex-1 border border-slate-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-emerald-500"
            >

            <button
                type="submit"
                class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2 rounded-lg text-sm font-semibold">
                تجربة التعرف على الصنف
            </button>
        </form>
    </div>

    @if($customerMessage)
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 space-y-4">

            <h3 class="font-bold text-slate-700">
                نتيجة تعرف النظام على رسالة الزبون:
            </h3>

            @forelse($matchedItems as $item)
                <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-lg flex justify-between items-center gap-4">
                    <div>
                        <p class="font-bold text-slate-800">
                            {{ $item->item_name }}
                            <span class="text-xs font-mono text-slate-500">
                                ({{ $item->item_number }})
                            </span>
                        </p>

                        <p class="text-xs text-emerald-700 mt-1">
                            الكمية المتوفرة: {{ $item->quantity }}
                            |
                            السعر: {{ number_format($item->price, 2) }} ر.س
                        </p>
                    </div>

                    <span class="text-xs bg-emerald-600 text-white px-3 py-1 rounded-full">
                        تم التعرف بنجاح
                    </span>
                </div>
            @empty
                <p class="text-sm text-rose-600">
                    لم يتم العثور على صنف مطابق لهذه الكلمات في المخزون.
                </p>
            @endforelse

        </div>
    @endif

</div>
@endsection

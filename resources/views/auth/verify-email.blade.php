<x-guest-layout>
    <div class="mb-4 text-sm text-slate-600">
        تم إرسال رابط التحقق إلى بريدك الإلكتروني. يرجى فتح الرابط لإكمال التحقق، أو طلب رابط جديد.
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="ledger-alert-success mb-4">
            تم إرسال رابط تحقق جديد إلى بريدك الإلكتروني.
        </div>
    @endif

    <div class="mt-4 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <div>
                <x-primary-button>
                    إعادة إرسال رابط التحقق
                </x-primary-button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit" class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                تسجيل الخروج
            </button>
        </form>
    </div>
</x-guest-layout>

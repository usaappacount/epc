<x-guest-layout>
    <div dir="rtl" class="space-y-5 text-right font-sans">
        <h2 class="ledger-section-title text-center">
            التحقق عبر واتساب
        </h2>
        <p class="text-center text-sm text-slate-500">
            تم إرسال رمز مكون من 4 أرقام إلى الرقم: 
            <span dir="ltr" class="font-bold text-emerald-700">{{ session('otp_phone') }}</span>
        </p>

        @if(session('debug_otp'))
            <div class="ledger-alert-success text-center">
                رمز التجربة الحالي هو: <strong class="font-mono">{{ session('debug_otp') }}</strong>
            </div>
        @endif

        <form method="POST" action="{{ route('otp.verify') }}" id="otpForm">
            @csrf
            <div>
                <label for="otp_code" class="mb-1 block text-center text-xs font-bold text-slate-600">
                    أدخل الرمز المكون من 4 أرقام (سيتم الدخول تلقائياً)
                </label>
                <input id="otp_code" 
                       type="text" 
                       inputmode="numeric"
                       name="otp_code" 
                       maxlength="4" 
                       required 
                       autofocus
                       autocomplete="one-time-code"
                       placeholder="----"
                       class="ledger-input mt-1 block w-full text-center font-mono text-2xl tracking-[0.5em]" />
                <x-input-error :messages="$errors->get('otp_code')" class="mt-2 text-center" />
            </div>

            <div class="mt-6">
                <button type="submit" id="verifyBtn"
                        class="ledger-button-primary w-full !min-h-12">
                    تأكيد الرمز والدخول
                </button>
            </div>
            
            <div class="mt-4 text-center">
                <a href="{{ route('login') }}" class="text-xs text-slate-500 underline">
                    العودة لتسجيل الدخول
                </a>
            </div>
        </form>
    </div>

    <script>
        const otpInput = document.getElementById('otp_code');
        const otpForm = document.getElementById('otpForm');
        const verifyBtn = document.getElementById('verifyBtn');

        otpInput.addEventListener('input', function () {
            // السماح بالأرقام الإنجليزية فقط ومنع أي حروف أو مسافات وبحد أقصى 4 أرقام
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 4);

            // بمجرد اكتمال الـ 4 أرقام يتم تسجيل الدخول تلقائياً فوراً
            if (this.value.length === 4) {
                verifyBtn.textContent = 'جاري التحقق والدخول تلقائياً...';
                verifyBtn.style.backgroundColor = 'var(--ledger-primary-hover)';
                verifyBtn.disabled = true;
                otpForm.submit();
            }
        });
    </script>
</x-guest-layout>
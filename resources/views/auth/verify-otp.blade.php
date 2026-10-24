<x-guest-layout>
    <div dir="rtl" class="text-right font-sans">
        <h2 style="font-size: 1.25rem; font-weight: bold; text-align: center; color: #1f2937; margin-bottom: 0.5rem;">
            التحقق عبر واتساب
        </h2>
        <p style="font-size: 0.875rem; text-align: center; color: #4b5563; margin-bottom: 1.5rem;">
            تم إرسال رمز مكون من 4 أرقام إلى الرقم: 
            <span dir="ltr" style="font-weight: bold; color: #059669;">{{ session('otp_phone') }}</span>
        </p>

        @if(session('debug_otp'))
            <div style="margin-bottom: 1rem; padding: 12px; background-color: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px; text-align: center; font-size: 0.875rem; color: #92400e;">
                رمز التجربة الحالي هو: <strong style="font-size: 1.2rem; font-family: monospace;">{{ session('debug_otp') }}</strong>
            </div>
        @endif

        <form method="POST" action="{{ route('otp.verify') }}" id="otpForm">
            @csrf
            <div>
                <label for="otp_code" class="block font-medium text-sm text-gray-700 mb-1" style="text-align: center;">
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
                       style="text-align: center; font-size: 1.5rem; letter-spacing: 0.5em; font-family: monospace;"
                       class="block mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                <x-input-error :messages="$errors->get('otp_code')" class="mt-2" style="text-align: center;" />
            </div>

            <div class="mt-6">
                <button type="submit" id="verifyBtn"
                        style="width: 100%; background-color: #059669; color: #ffffff; padding: 12px 16px; border-radius: 8px; font-weight: 600; font-size: 0.95rem; text-align: center; display: block; border: none; cursor: pointer;">
                    تأكيد الرمز والدخول
                </button>
            </div>
            
            <div class="mt-4" style="text-align: center; margin-top: 1rem;">
                <a href="{{ route('login') }}" style="font-size: 0.8rem; color: #6b7280; text-decoration: underline;">
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
                verifyBtn.style.backgroundColor = '#047857';
                verifyBtn.disabled = true;
                otpForm.submit();
            }
        });
    </script>
</x-guest-layout>
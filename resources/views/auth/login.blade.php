<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>تسجيل الدخول - نظام المخزون</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Cairo', sans-serif; }</style>
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl p-8 w-full max-w-md space-y-6">
        <div class="text-center">
            <div class="flex justify-center mb-3">
                <div class="w-12 h-12 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-center text-slate-700 shadow-sm">
                    <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                </div>
            </div>
            <h1 class="text-lg font-bold text-slate-800">تسجيل الدخول - نظام المخزون</h1>
        </div>

        @if(session('error'))
            <div class="p-3 bg-rose-100 text-rose-700 rounded-lg text-xs font-semibold text-center">
                {{ session('error') }}
            </div>
        @endif

        @if(session('success'))
            <div class="p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl text-center">
                <p class="text-xs font-bold">رمز التحقق ( مؤقت )  </p>
                <p class="text-3xl font-extrabold tracking-widest py-2" dir="ltr">{{ session('success') }}</p>
                <p class="text-xs text-emerald-700"></p>
            </div>
        @endif

        <form id="loginForm" method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf
            <div>
                <label for="loginInput" class="block text-xs font-semibold text-slate-600 mb-1">اسم المستخدم أو رقم الجوال</label>
                <input type="text" name="login" id="loginInput" required
                    placeholder="05X XXX XXXX"
                    value="{{ old('login', session('phone_for_otp')) }}"
                    class="w-full border border-slate-300 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:border-emerald-500 font-mono text-left"
                    dir="ltr" autocomplete="username">
                <p id="whatsappHint" class="text-[11px] text-emerald-600 font-medium mt-1.5 hidden items-center gap-1">
                    <span>✓</span><span>تسجيل الدخول باستخدام رقم الجوال</span>
                </p>
            </div>

            <div id="passwordFieldContainer">
                <label for="passwordInput" id="passwordLabel" class="block text-xs font-semibold text-slate-600 mb-1">
                    {{ session('success') ? 'رمز التحقق' : 'كلمة المرور' }}
                </label>
                <input type="password" name="password" id="passwordInput"
                    placeholder="{{ session('success') ? 'أدخل رمز التحقق' : '' }}"
                    class="w-full border border-slate-300 rounded-lg px-4 py-2.5 text-sm focus:outline-none focus:border-emerald-500 font-mono"
                    {{ session('success') ? 'required' : '' }}
                    autocomplete="{{ session('success') ? 'one-time-code' : 'current-password' }}">
            </div>

            <button type="submit" id="submitBtn"
                class="w-full bg-slate-800 hover:bg-slate-900 text-white py-3 rounded-lg font-bold text-sm transition shadow-md flex items-center justify-center gap-2">
                {{ session('success') ? 'تأكيد ودخول' : 'تسجيل الدخول' }}
            </button>
        </form>
    </div>

    <script>
        const loginForm = document.getElementById('loginForm');
        const loginInput = document.getElementById('loginInput');
        const passwordContainer = document.getElementById('passwordFieldContainer');
        const passwordInput = document.getElementById('passwordInput');
        const passwordLabel = document.getElementById('passwordLabel');
        const submitBtn = document.getElementById('submitBtn');
        const whatsappHint = document.getElementById('whatsappHint');
        const otpMode = @json((bool) session('success'));
        let otpSubmitting = false;

        function handleLoginInput(input) {
            let value = input.value.trim();

            if (otpMode) return;

            // تحديد رقم الجوال المحلي بعشرة أرقام عند إدخال أرقام فقط.
            if (/^[0-9]+$/.test(value) && value.length > 10) {
                value = value.slice(0, 10);
                input.value = value;
            }

            const isPhone = /^0[0-9]{9}$/.test(value);

            if (isPhone) {
                passwordContainer.style.display = 'none';
                passwordInput.required = false;
                passwordInput.value = '';
                submitBtn.textContent = 'استلام رمز تحقق واتساب';
                submitBtn.className = 'w-full bg-[#00a859] hover:bg-[#008f4c] text-white py-3 rounded-lg font-bold text-sm transition shadow-md flex items-center justify-center gap-2';
                whatsappHint.style.display = 'flex';
            } else {
                passwordContainer.style.display = 'block';
                passwordInput.required = true;
                passwordLabel.textContent = 'كلمة المرور';
                passwordInput.placeholder = 'أدخل كلمة المرور';
                submitBtn.textContent = 'تسجيل الدخول';
                submitBtn.className = 'w-full bg-slate-800 hover:bg-slate-900 text-white py-3 rounded-lg font-bold text-sm transition shadow-md flex items-center justify-center gap-2';
                whatsappHint.style.display = 'none';
            }
        }

        loginInput.addEventListener('input', function () {
            handleLoginInput(this);
        });

        if (otpMode) {
            passwordContainer.style.display = 'block';
            passwordInput.required = true;
            passwordInput.maxLength = 4;
            passwordInput.inputMode = 'numeric';
            passwordInput.pattern = '[0-9]{4}';
            passwordInput.autocomplete = 'one-time-code';
            passwordLabel.textContent = 'رمز التحقق';
            passwordInput.placeholder = 'أدخل رمز التحقق المكوّن من 4 أرقام';
            submitBtn.textContent = 'جارٍ التحقق...';
            submitBtn.textContent = 'تأكيد ودخول';
            submitBtn.className = 'w-full bg-[#00a859] hover:bg-[#008f4c] text-white py-3 rounded-lg font-bold text-sm transition shadow-md flex items-center justify-center gap-2';
            whatsappHint.style.display = 'none';

            // إرسال النموذج تلقائيًا فور اكتمال 4 أرقام.
            passwordInput.addEventListener('input', function () {
                const digits = this.value.replace(/\D/g, '').slice(0, 4);
                if (this.value !== digits) this.value = digits;

                if (digits.length === 4 && !otpSubmitting) {
                    otpSubmitting = true;
                    passwordInput.readOnly = true;
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'جارٍ التحقق وتسجيل الدخول...';
                    loginForm.requestSubmit();
                }
            });
        } else if (loginInput.value) {
            handleLoginInput(loginInput);
        }
    </script>
</body>
</html>

<x-guest-layout>
    <div dir="rtl" class="text-right font-sans">
        <h2 style="font-size: 1.25rem; font-weight: bold; text-align: center; color: #1f2937; margin-bottom: 1.5rem;">
           تسجيل الدخول - نظام المخزون
        </h2>

        <!-- عرض رسائل الخطأ أو النجاح -->
        <x-auth-session-status class="mb-4" :status="session('status')" />

        <form method="POST" action="{{ route('login') }}" id="loginForm">
            @csrf

            <!-- حقل اسم المستخدم أو رقم الجوال -->
            <div>
                <label for="login_identifier" class="block font-medium text-sm text-gray-700 mb-1">
                    اسم المستخدم أو رقم الجوال
                </label>
                <input id="login_identifier" 
                       type="text" 
                       name="login_identifier" 
                       value="{{ old('login_identifier') }}" 
                       required 
                       autofocus 
                       placeholder="05X XXX XXXX"
                       class="block mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                
                <!-- تنبيه صغير يظهر عند اكتشاف رقم جوال -->
                <p id="phoneHint" class="hidden mt-2" style="color: #059669; font-size: 0.8rem; font-weight: 600; display: none;">
                    ✓ سيتم ارسال رمز تحقق مكون من 4 ارقام
                </p>

                <x-input-error :messages="$errors->get('login_identifier')" class="mt-2" />
            </div>

            <!-- حقل كلمة المرور (يختفي تلقائياً عند كتابة رقم جوال) -->
            <div class="mt-4" id="passwordContainer">
                <label for="password" class="block font-medium text-sm text-gray-700 mb-1">
                    كلمة المرور
                </label>
                <input id="password" 
                       type="password" 
                       name="password" 
                       placeholder="أدخل كلمة المرور"
                       class="block mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />

                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <!-- زر الدخول المتغير الذكي -->
            <div class="mt-6">
                <button type="submit" id="submitBtn" 
                        style="width: 100%; background-color: #1f2937; color: #ffffff; padding: 12px 16px; border-radius: 8px; font-weight: 600; font-size: 0.95rem; text-align: center; display: block; border: none; cursor: pointer; transition: background-color 0.2s;">
                    تسجيل الدخول
                </button>
            </div>
        </form>
    </div>

    <script>
        const identifierInput = document.getElementById('login_identifier');
        const passwordContainer = document.getElementById('passwordContainer');
        const passwordInput = document.getElementById('password');
        const submitBtn = document.getElementById('submitBtn');
        const phoneHint = document.getElementById('phoneHint');

        function checkInputType() {
            let val = identifierInput.value.trim();

            // إذا بدأ بكتابة صفر وأرقام فقط (رقم جوال)، نمنعه من تجاوز 10 أرقام نهائياً
            if (/^0\d*$/.test(val)) {
                if (val.length > 10) {
                    val = val.slice(0, 10);
                    identifierInput.value = val;
                }
            }

            // فحص هل اكتمل الرقم إلى 10 أرقام ويبدأ بـ 0
            const isTenDigitPhone = /^0\d{9}$/.test(val);

            if (isTenDigitPhone) {
                passwordContainer.style.display = 'none';
                passwordInput.removeAttribute('required');
                passwordInput.value = '';
                phoneHint.style.display = 'block';

                submitBtn.textContent = 'تسجيل الدخول من خلال واتساب ';
                submitBtn.style.backgroundColor = '#0b9c6e';
            } else {
                passwordContainer.style.display = 'block';
                phoneHint.style.display = 'none';

                submitBtn.textContent = 'تسجيل الدخول';
                submitBtn.style.backgroundColor = '#1f2937';
            }
        }

        identifierInput.addEventListener('input', checkInputType);
        window.addEventListener('DOMContentLoaded', checkInputType);
    </script>
</x-guest-layout>
<?php namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'login_identifier' => ['required', 'string'],
        ]);

        $identifier = trim($request->input('login_identifier'));

        // الحالة الأولى: إذا كتب 10 أرقام تبدأ بـ 0 (رقم جوال)
        if (preg_match('/^0\d{9}$/', $identifier)) {
            // حذف الصفر الأول وإضافة +966 تلقائياً في الخلفية
            $formattedPhone = '+966' . substr($identifier, 1);

            $user = User::where('phone', $formattedPhone)->first();

            if (! $user) {
                throw ValidationException::withMessages([
                    'login_identifier' => 'رقم الجوال هذا غير مسجل في النظام.',
                ]);
            }

            // توليد رمز عشوائي من 4 أرقام صالح لمدة 5 دقائق
            $otp = (string) rand(1000, 9999);
            $user->update([
                'otp_code' => $otp,
                'otp_expires_at' => now()->addMinutes(5),
            ]);

            // حفظ رقم الجوال في الجلسة والانتقال لصفحة إدخال رمز الواتساب
            session([
                'otp_user_id' => $user->id,
                'otp_phone' => $formattedPhone,
                'debug_otp' => $otp, // يظهر على الشاشة مؤقتاً للتجربة
            ]);

            return redirect()->route('otp.form');
        }

        // الحالة الثانية: إذا كتب اسم مستخدم (يطلب كلمة مرور)
        $request->validate([
            'password' => ['required', 'string'],
        ], [
            'password.required' => 'يرجى إدخال كلمة المرور الخاصة باسم المستخدم.',
        ]);

        if (! Auth::attempt(['username' => $identifier, 'password' => $request->input('password')], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'login_identifier' => 'اسم المستخدم أو كلمة المرور غير صحيحة.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('inventory.index', absolute: false));
    }

    public function showOtpForm(): View|RedirectResponse
    {
        if (! session('otp_user_id')) {
            return redirect()->route('login');
        }
        return view('auth.verify-otp');
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $request->validate([
            'otp_code' => ['required', 'digits:4'],
        ], [
            'otp_code.required' => 'يرجى إدخال رمز التحقق.',
            'otp_code.digits' => 'رمز التحقق يجب أن يتكون من 4 أرقام.',
        ]);

        $user = User::find(session('otp_user_id'));

        if (! $user || $user->otp_code !== $request->otp_code || now()->greaterThan($user->otp_expires_at)) {
            throw ValidationException::withMessages([
                'otp_code' => 'رمز التحقق غير صحيح أو منتهي الصلاحية.',
            ]);
        }

        // مسح الرمز بعد الاستخدام وتسجيل دخول المستخدم
        $user->update(['otp_code' => null, 'otp_expires_at' => null]);
        session()->forget(['otp_user_id', 'otp_phone', 'debug_otp']);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('inventory.index', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
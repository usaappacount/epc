<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // إنشاء أو تحديث حساب الأدمن تلقائياً عند التشغيل
        User::updateOrCreate(
            ['phone' => '0556666037'], // ضع رقم الجوال الذي تريد تسجيل الدخول به هنا
            [
                'name' => 'Admin',
                'password' => Hash::make('112233'), // ضع كلمة المرور التي تريدها هنا
                // إذا كان جدولك يحتاج حقول إضافية مثل role أضفها هنا، مثلاً: 'role' => 'admin'
            ]
        );
    }
}

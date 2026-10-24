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
        // إنشاء أو تحديث حساب الأدمن تلقائياً دون التأثير على باقي البيانات
        User::updateOrCreate(
            ['phone' => '0556666037'], // ضع رقم جوال الأدمن هنا
            [
                'name' => 'Admin',
                'password' => Hash::make('112233'), // ضع كلمة المرور هنا
            ]
        );
    }
}

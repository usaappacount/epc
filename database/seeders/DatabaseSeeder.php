<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // مسح أي مستخدم قديم عالق وإدخال الأدمن بصيغة الواتساب الدولية
        DB::table('users')->where('username', 'admin')->delete();

        DB::table('users')->insert([
            'username' => 'admin',
            'name' => 'مدير النظام',
            'email' => 'admin@example.com',
            'phone' => '+966556666037', // الصيغة التي يحولها النظام بالخلفية
            'role' => 'admin',
            'password' => Hash::make('112233'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = [
            ['item_number' => 'ITM-1001', 'item_name' => 'شاشة عرض مكتبية 27 بوصة', 'quantity' => 15, 'price' => 850.00],
            ['item_number' => 'ITM-1002', 'item_name' => 'لوحة مفاتيح ميكانيكية لاسلكية', 'quantity' => 42, 'price' => 220.50],
            ['item_number' => 'ITM-1003', 'item_name' => 'فأرة ضوئية احترافية', 'quantity' => 60, 'price' => 115.00],
            ['item_number' => 'ITM-1004', 'item_name' => 'وحدة تخزين خارجية SSD 1TB', 'quantity' => 8, 'price' => 430.00],
            ['item_number' => 'ITM-1005', 'item_name' => 'سماعة رأس عازلة للضوضاء', 'quantity' => 25, 'price' => 540.00],
        ];

        foreach ($items as $item) {
            Item::updateOrCreate(
                ['item_number' => $item['item_number']],
                $item
            );
        }
    }
}

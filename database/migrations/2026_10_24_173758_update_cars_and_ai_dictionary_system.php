<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. جدول السيارات الرئيسي (القسم الأول: اسم السيارة وفئتها)
        if (!Schema::hasTable('cars')) {
            Schema::create('cars', function (Blueprint $table) {
                $table->id();
                $table->string('car_name');     // مثال: يارس - صالون
                $table->string('category');     // مثال: سيدان / جيب / إلخ
                $table->timestamps();
            });
        }

        // 2. جدول مرادفات السيارات (مرتبط بالسيارة الأساسية)
        if (!Schema::hasTable('car_aliases')) {
            Schema::create('car_aliases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('car_id')->constrained('cars')->onDelete('cascade');
                $table->string('alias');        // مثال: هايلكس، هيلوكس
                $table->timestamps();
            });
        }

        // 3. جدول أنماط سنوات الصنع (القسم الثاني: 14-18، 98-97، 14 / 25)
        if (!Schema::hasTable('year_patterns')) {
            Schema::create('year_patterns', function (Blueprint $table) {
                $table->id();
                $table->string('pattern_example'); // مثال: 14-18 (2014-2018)
                $table->timestamps();
            });
        }

        // 4. تحديث جدول الأصناف لحفظ بيانات السيارة وسنة الصنع المستخرجة لكل صنف
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'detected_car_name')) {
                $table->string('detected_car_name')->nullable();
            }
            if (!Schema::hasColumn('items', 'detected_category')) {
                $table->string('detected_category')->nullable();
            }
            if (!Schema::hasColumn('items', 'detected_years')) {
                $table->string('detected_years')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_aliases');
        Schema::dropIfExists('cars');
        Schema::dropIfExists('year_patterns');
    }
};
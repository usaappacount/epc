<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. تحديث جدول المستخدمين لدعم العملاء (الجوال، المدينة، كود التحقق، حالة الحساب)
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->nullable()->unique();
            }
            if (!Schema::hasColumn('users', 'city')) {
                $table->string('city')->nullable();
            }
            if (!Schema::hasColumn('users', 'otp_code')) {
                $table->string('otp_code', 4)->nullable();
            }
            if (!Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role')->default('client');
            }
        });

        // 2. تحديث جدول أصناف العملاء لربط كل صنف بصاحبه
        Schema::table('items', function (Blueprint $table) {
            if (!Schema::hasColumn('items', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->index();
            }
        });

        // 3. جدول قلب النظام (المخزون المركزي الموحد - بدون تكرار لرقم الصنف)
        if (!Schema::hasTable('master_items')) {
            Schema::create('master_items', function (Blueprint $table) {
                $table->id();$table->string('item_number')->unique(); // رقم فريد لا يتكرر أبداً
                $table->string('first_item_name');       // ترجمة أول عميل رفع الصنف
                $table->unsignedBigInteger('first_user_id')->nullable();$table->timestamps();
            });
        }

        // 4. جدول الكلمات المفتاحية المستخرجة تلقائياً لكل صنف (بدون تكرار للكلمة)
        if (!Schema::hasTable('item_keywords')) {
            Schema::create('item_keywords', function (Blueprint $table) {$table->id();
                $table->string('item_number')->index();$table->string('keyword');
                $table->timestamps();$table->unique(['item_number', 'keyword']); // يمنع تكرار نفس الكلمة لنفس الصنف
            });
        }

        // 5. جدول قاموس السيارات وسنوات الصنع لتعليم الذكاء الاصطناعي (AI)
        if (!Schema::hasTable('car_dictionaries')) {
            Schema::create('car_dictionaries', function (Blueprint $table) {
                $table->id();$table->string('car_name');      // الاسم الأساسي (مثلاً: تويوتا كامري)
                $table->text('car_aliases');     // المرادفات (كامري، camry، كمر، غراندي)
                $table->text('year_patterns');   // صيغ السنوات (20-23، 2020-2023، 20 21 22)
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('car_dictionaries');
        Schema::dropIfExists('item_keywords');
        Schema::dropIfExists('master_items');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('properties') || !Schema::hasColumn('properties', 'payment_mode')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("UPDATE `properties` SET `payment_mode` = 'UPI' WHERE `payment_mode` = 'UPI QR'");
        DB::statement("UPDATE `properties` SET `payment_mode` = 'Cash' WHERE `payment_mode` = 'Bank Transfer'");
        DB::statement("ALTER TABLE `properties` MODIFY `payment_mode` ENUM('UPI','Cash','Credit/Debit Cards') NOT NULL");
    }

    public function down(): void
    {
        if (!Schema::hasTable('properties') || !Schema::hasColumn('properties', 'payment_mode')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("UPDATE `properties` SET `payment_mode` = 'UPI' WHERE `payment_mode` = 'Cash'");
        DB::statement("ALTER TABLE `properties` MODIFY `payment_mode` ENUM('UPI','UPI QR','Credit/Debit Cards') NOT NULL");
    }
};

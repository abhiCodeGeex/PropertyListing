<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payments') || !Schema::hasColumn('payments', 'rent_schedule_id')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `payments` MODIFY `rent_schedule_id` BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        // Intentionally left empty. Non-rent payment flows require nullable rent_schedule_id.
    }
};

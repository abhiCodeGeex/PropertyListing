<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rent_schedules') || Schema::hasColumn('rent_schedules', 'last_reminder_at')) {
            return;
        }

        Schema::table('rent_schedules', function (Blueprint $table) {
            $table->timestamp('last_reminder_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('rent_schedules') || !Schema::hasColumn('rent_schedules', 'last_reminder_at')) {
            return;
        }

        Schema::table('rent_schedules', function (Blueprint $table) {
            $table->dropColumn('last_reminder_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rent_deeds', function (Blueprint $table) {
            if (!Schema::hasColumn('rent_deeds', 'rent_due_date')) {
                $table->unsignedTinyInteger('rent_due_date')->nullable()->after('due_date');
            }

            if (!Schema::hasColumn('rent_deeds', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rent_deeds', function (Blueprint $table) {
            if (Schema::hasColumn('rent_deeds', 'deleted_at')) {
                $table->dropSoftDeletes();
            }

            if (Schema::hasColumn('rent_deeds', 'rent_due_date')) {
                $table->dropColumn('rent_due_date');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('properties') || !Schema::hasColumn('properties', 'due_date')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('due_date');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('properties') || Schema::hasColumn('properties', 'due_date')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('monthly_rent');
        });
    }
};

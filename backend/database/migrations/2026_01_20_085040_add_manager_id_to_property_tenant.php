<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('property_tenant', function (Blueprint $table) {
            $table->foreignId('manager_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->after('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('property_tenant', function (Blueprint $table) {
            //
        });
    }
};

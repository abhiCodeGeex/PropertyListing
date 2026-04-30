<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'tenancy_id')) {
                $table->foreignId('tenancy_id')
                    ->nullable()
                    ->after('property_id')
                    ->constrained('property_tenant')
                    ->nullOnDelete();
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['tenancy_id', 'type', 'status'], 'payments_tenancy_type_status_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'tenancy_id')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_tenancy_type_status_idx');
            $table->dropConstrainedForeignId('tenancy_id');
        });
    }
};

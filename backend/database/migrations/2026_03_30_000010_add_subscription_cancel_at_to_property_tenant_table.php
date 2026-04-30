<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_tenant', function (Blueprint $table) {
            if (! Schema::hasColumn('property_tenant', 'subscription_cancel_at')) {
                $table->timestamp('subscription_cancel_at')->nullable()->after('subscription_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('property_tenant', function (Blueprint $table) {
            if (Schema::hasColumn('property_tenant', 'subscription_cancel_at')) {
                $table->dropColumn('subscription_cancel_at');
            }
        });
    }
};

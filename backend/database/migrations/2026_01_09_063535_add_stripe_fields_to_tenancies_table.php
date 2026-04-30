<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_tenant', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->after('id');
            $table->string('stripe_subscription_id')->nullable()->after('stripe_customer_id');
            $table->string('stripe_price_id')->nullable()->after('stripe_subscription_id');
            $table->boolean('subscription_active')->default(false)->after('stripe_price_id');
        });
    }

    public function down(): void
    {
        Schema::table('property_tenant', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_customer_id',
                'stripe_subscription_id',
                'stripe_price_id',
                'subscription_active',
            ]);
        });
    }
};

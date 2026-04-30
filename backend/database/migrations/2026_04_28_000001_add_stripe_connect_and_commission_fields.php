<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'stripe_connect_account_id')) {
                $table->string('stripe_connect_account_id')->nullable()->after('photo_url');
            }

            if (! Schema::hasColumn('users', 'stripe_connect_details_submitted')) {
                $table->boolean('stripe_connect_details_submitted')->default(false)->after('stripe_connect_account_id');
            }

            if (! Schema::hasColumn('users', 'stripe_connect_charges_enabled')) {
                $table->boolean('stripe_connect_charges_enabled')->default(false)->after('stripe_connect_details_submitted');
            }

            if (! Schema::hasColumn('users', 'stripe_connect_payouts_enabled')) {
                $table->boolean('stripe_connect_payouts_enabled')->default(false)->after('stripe_connect_charges_enabled');
            }

            if (! Schema::hasColumn('users', 'stripe_connect_onboarded_at')) {
                $table->timestamp('stripe_connect_onboarded_at')->nullable()->after('stripe_connect_payouts_enabled');
            }
        });

        Schema::table('properties', function (Blueprint $table) {
            if (! Schema::hasColumn('properties', 'owner_commission_percent')) {
                $table->decimal('owner_commission_percent', 5, 2)->default(100)->after('payment_mode');
            }

            if (! Schema::hasColumn('properties', 'manager_commission_percent')) {
                $table->decimal('manager_commission_percent', 5, 2)->default(0)->after('owner_commission_percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('properties', 'owner_commission_percent') ? 'owner_commission_percent' : null,
                Schema::hasColumn('properties', 'manager_commission_percent') ? 'manager_commission_percent' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('users', 'stripe_connect_account_id') ? 'stripe_connect_account_id' : null,
                Schema::hasColumn('users', 'stripe_connect_details_submitted') ? 'stripe_connect_details_submitted' : null,
                Schema::hasColumn('users', 'stripe_connect_charges_enabled') ? 'stripe_connect_charges_enabled' : null,
                Schema::hasColumn('users', 'stripe_connect_payouts_enabled') ? 'stripe_connect_payouts_enabled' : null,
                Schema::hasColumn('users', 'stripe_connect_onboarded_at') ? 'stripe_connect_onboarded_at' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

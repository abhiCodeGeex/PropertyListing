<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rent_deeds', function (Blueprint $table) {
            $columns = [
                'address',
                'payment_mode',
                'monthly_rent',
                'due_date',
                'security_deposit',
                'refund_rules',
                'termination_renewal',
                'liability',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('rent_deeds', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('rent_deeds', function (Blueprint $table) {
            if (!Schema::hasColumn('rent_deeds', 'address')) {
                $table->string('address')->nullable()->after('property_id');
            }

            if (!Schema::hasColumn('rent_deeds', 'payment_mode')) {
                $table->string('payment_mode')->nullable()->after('usage');
            }

            if (!Schema::hasColumn('rent_deeds', 'monthly_rent')) {
                $table->decimal('monthly_rent', 12, 2)->nullable()->after('payment_mode');
            }

            if (!Schema::hasColumn('rent_deeds', 'due_date')) {
                $table->date('due_date')->nullable()->after('monthly_rent');
            }

            if (!Schema::hasColumn('rent_deeds', 'security_deposit')) {
                $table->decimal('security_deposit', 12, 2)->nullable()->after('maintenance_charges');
            }

            if (!Schema::hasColumn('rent_deeds', 'refund_rules')) {
                $table->text('refund_rules')->nullable()->after('security_deposit');
            }

            if (!Schema::hasColumn('rent_deeds', 'termination_renewal')) {
                $table->text('termination_renewal')->nullable()->after('refund_rules');
            }

            if (!Schema::hasColumn('rent_deeds', 'liability')) {
                $table->text('liability')->nullable()->after('termination_renewal');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('property_tenant')) {
            Schema::table('property_tenant', function (Blueprint $table) {
                if (!Schema::hasColumn('property_tenant', 'security_deposit_amount')) {
                    $table->decimal('security_deposit_amount', 10, 2)->default(0);
                }

                if (!Schema::hasColumn('property_tenant', 'security_deposit_status')) {
                    $table->string('security_deposit_status', 32)->default('pending');
                }
            });
        }

        if (Schema::hasTable('rent_schedules')) {
            Schema::table('rent_schedules', function (Blueprint $table) {
                if (!Schema::hasColumn('rent_schedules', 'due_date')) {
                    $table->date('due_date')->nullable();
                }
            });

            Schema::table('rent_schedules', function (Blueprint $table) {
                $table->string('status', 32)->default('pending')->change();
            });

            DB::table('rent_schedules')
                ->whereNull('due_date')
                ->update(['due_date' => DB::raw('month')]);
        }

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->foreignId('rent_schedule_id')->nullable()->change();
            });

            Schema::table('payments', function (Blueprint $table) {
                if (!Schema::hasColumn('payments', 'property_id')) {
                    $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
                }

                if (!Schema::hasColumn('payments', 'type')) {
                    $table->string('type', 32)->default('rent_deposit');
                }

                if (!Schema::hasColumn('payments', 'payment_mode')) {
                    $table->string('payment_mode', 32)->nullable();
                }

                if (!Schema::hasColumn('payments', 'failure_reason')) {
                    $table->text('failure_reason')->nullable();
                }

                if (!Schema::hasColumn('payments', 'stripe_event_id')) {
                    $table->string('stripe_event_id')->nullable()->index();
                }

                if (!Schema::hasColumn('payments', 'stripe_charge_id')) {
                    $table->string('stripe_charge_id')->nullable()->index();
                }
            });

            Schema::table('payments', function (Blueprint $table) {
                $table->string('status', 32)->default('pending')->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table) {
                $columns = [];

                foreach ([
                    'property_id',
                    'type',
                    'payment_mode',
                    'failure_reason',
                    'stripe_event_id',
                    'stripe_charge_id',
                ] as $column) {
                    if (Schema::hasColumn('payments', $column)) {
                        $columns[] = $column;
                    }
                }

                if (!empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('rent_schedules') && Schema::hasColumn('rent_schedules', 'due_date')) {
            Schema::table('rent_schedules', function (Blueprint $table) {
                $table->dropColumn('due_date');
            });
        }

        if (Schema::hasTable('property_tenant')) {
            Schema::table('property_tenant', function (Blueprint $table) {
                $columns = [];

                foreach (['security_deposit_amount', 'security_deposit_status'] as $column) {
                    if (Schema::hasColumn('property_tenant', $column)) {
                        $columns[] = $column;
                    }
                }

                if (!empty($columns)) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};

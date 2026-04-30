<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rent_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('tenancy_id')
                ->constrained('property_tenant')
                ->cascadeOnDelete();

            $table->date('month'); // YYYY-MM-01 format recommended
            $table->decimal('amount', 10, 2);

            $table->enum('status', ['pending', 'paid'])
                ->default('pending');

            $table->timestamps();

            // Prevent duplicate rent rows for same tenancy & month
            $table->unique(['tenancy_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rent_schedules');
    }
};

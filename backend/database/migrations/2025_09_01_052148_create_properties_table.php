<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // owner
            $table->string('property_name');
            $table->enum('property_type', ['Residential', 'Commercial']);
            $table->string('state', 100);
            $table->string('city', 100);
            $table->text('address');
            $table->decimal('monthly_rent', 10, 2);
            $table->date('due_date');
            $table->enum('payment_mode', ['UPI', 'UPI QR', 'Credit/Debit Cards']);
            $table->decimal('security_amount', 10, 2)->default(0);
            $table->text('refund_terms')->nullable();
            $table->string('agreement_duration')->nullable();
            $table->text('maintenance_responsibilities')->nullable();
            $table->text('termination_clause')->nullable();
            $table->string('late_payment_penalty')->nullable();
            $table->enum('electricity_bill_paid_by', ['owner', 'tenant']);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};

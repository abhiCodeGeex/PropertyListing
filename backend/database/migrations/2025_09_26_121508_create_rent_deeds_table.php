<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rent_deeds', function (Blueprint $table) {
            $table->id();
            $table->string('agreement_number');
            $table->date('agreement_date');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('property_id');
            $table->string('address')->nullable();
            $table->string('size')->nullable();
            $table->string('usage')->nullable();
            $table->string('payment_mode')->nullable();
            $table->decimal('monthly_rent', 12, 2)->nullable();
            $table->date('due_date')->nullable();
            $table->string('maintenance_charges')->nullable();
            $table->decimal('security_deposit', 12, 2)->nullable();
            $table->text('refund_rules')->nullable();
            $table->text('termination_renewal')->nullable();
            $table->text('liability')->nullable();
            $table->text('other_details')->nullable();
            $table->timestamps();
            
            $table->foreign('owner_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('tenant_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('property_id')->references('id')->on('properties')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rent_deeds');
    }
};

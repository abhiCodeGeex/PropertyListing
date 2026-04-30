<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->string('source_key')->unique();
            $table->string('type');
            $table->string('status')->default('issued');
            $table->string('delivery_status')->default('pending');
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenancy_id')->nullable()->constrained('property_tenant')->nullOnDelete();
            $table->foreignId('primary_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('stripe_invoice_id')->nullable()->index();
            $table->string('stripe_payment_intent_id')->nullable()->index();
            $table->string('currency', 3)->default('INR');
            $table->string('subject');
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->date('billing_period_start')->nullable();
            $table->date('billing_period_end')->nullable();
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->json('billing_snapshot');
            $table->json('line_items');
            $table->string('pdf_disk')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('pdf_hash', 64)->nullable();
            $table->timestamp('pdf_generated_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedInteger('send_attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['recipient_user_id', 'status']);
            $table->index(['property_id', 'type']);
            $table->index(['tenancy_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'invoice_number',
        'source_key',
        'type',
        'status',
        'delivery_status',
        'recipient_user_id',
        'tenant_id',
        'property_id',
        'tenancy_id',
        'primary_payment_id',
        'stripe_invoice_id',
        'stripe_payment_intent_id',
        'currency',
        'subject',
        'recipient_name',
        'recipient_email',
        'issue_date',
        'due_date',
        'paid_at',
        'billing_period_start',
        'billing_period_end',
        'subtotal',
        'tax_total',
        'discount_total',
        'total',
        'billing_snapshot',
        'line_items',
        'pdf_disk',
        'pdf_path',
        'pdf_hash',
        'pdf_generated_at',
        'sent_at',
        'last_sent_at',
        'send_attempts',
        'failure_reason',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'billing_period_start' => 'date',
        'billing_period_end' => 'date',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'total' => 'decimal:2',
        'billing_snapshot' => 'array',
        'line_items' => 'array',
        'pdf_generated_at' => 'datetime',
        'sent_at' => 'datetime',
        'last_sent_at' => 'datetime',
    ];

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function tenant()
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function property()
    {
        return $this->belongsTo(Property::class);
    }

    public function tenancy()
    {
        return $this->belongsTo(PropertyTenant::class, 'tenancy_id');
    }

    public function primaryPayment()
    {
        return $this->belongsTo(Payment::class, 'primary_payment_id');
    }

    public function toDocumentArray(): array
    {
        return [
            'invoice_number' => $this->invoice_number,
            'invoice_type' => $this->type,
            'subject' => $this->subject,
            'property' => $this->billing_snapshot['property_name'] ?? 'Property',
            'property_address' => $this->billing_snapshot['property_address'] ?? null,
            'tenant' => $this->billing_snapshot['tenant_name'] ?? 'Tenant',
            'tenant_email' => $this->billing_snapshot['tenant_email'] ?? null,
            'currency' => $this->currency,
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'paid_at' => $this->paid_at?->toDateTimeString(),
            'billing_period_start' => $this->billing_period_start?->toDateString(),
            'billing_period_end' => $this->billing_period_end?->toDateString(),
            'items' => $this->line_items ?? [],
            'subtotal' => (float) $this->subtotal,
            'tax_total' => (float) $this->tax_total,
            'discount_total' => (float) $this->discount_total,
            'total' => (float) $this->total,
            'payment_reference' => $this->stripe_payment_intent_id ?: ($this->billing_snapshot['payment_reference'] ?? null),
            'stripe_invoice_id' => $this->stripe_invoice_id,
        ];
    }
}

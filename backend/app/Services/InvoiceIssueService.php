<?php

namespace App\Services;

use App\Events\NotificationCreated;
use App\Jobs\SendInvoiceEmail;
use App\Models\Invoice;
use App\Models\Notification as NotificationModel;
use App\Models\PropertyTenant;
use App\Support\Currency;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceIssueService
{
    public function __construct(
        private readonly InvoiceNumberService $invoiceNumberService,
        private readonly InvoicePdfService $invoicePdfService,
    ) {}

    public function issueTenantInvoice(
        PropertyTenant $tenancy,
        string $type,
        string $sourceKey,
        array $context = []
    ): Invoice {
        $existing = Invoice::query()->where('source_key', $sourceKey)->first();

        if ($existing) {
            return $existing;
        }

        $invoice = DB::transaction(function () use ($tenancy, $type, $sourceKey, $context): Invoice {
            $locked = Invoice::query()->where('source_key', $sourceKey)->lockForUpdate()->first();
            if ($locked) {
                return $locked;
            }

            $document = $this->buildDocument($tenancy, $type, $context);
            $invoice = Invoice::create([
                'invoice_number' => $this->invoiceNumberService->nextNumber(),
                'source_key' => $sourceKey,
                'type' => $type,
                'status' => 'paid',
                'delivery_status' => 'pending',
                'recipient_user_id' => $tenancy->tenant_id,
                'tenant_id' => $tenancy->tenant_id,
                'property_id' => $tenancy->property_id,
                'tenancy_id' => $tenancy->id,
                'primary_payment_id' => $context['primary_payment_id'] ?? null,
                'stripe_invoice_id' => $context['stripe_invoice_id'] ?? null,
                'stripe_payment_intent_id' => $context['stripe_payment_intent_id'] ?? null,
                'currency' => $document['currency'],
                'subject' => $document['subject'],
                'recipient_name' => $document['recipient_name'],
                'recipient_email' => $document['recipient_email'],
                'issue_date' => $document['issue_date'],
                'due_date' => $document['due_date'],
                'paid_at' => $document['paid_at'],
                'billing_period_start' => $document['billing_period_start'],
                'billing_period_end' => $document['billing_period_end'],
                'subtotal' => $document['subtotal'],
                'tax_total' => $document['tax_total'],
                'discount_total' => $document['discount_total'],
                'total' => $document['total'],
                'billing_snapshot' => $document['billing_snapshot'],
                'line_items' => $document['line_items'],
            ]);

            return $this->invoicePdfService->store($invoice);
        });

        $this->notifyIssued($invoice);
        SendInvoiceEmail::dispatch($invoice->id)->delay(now()->addSecond());

        return $invoice;
    }

    private function buildDocument(PropertyTenant $tenancy, string $type, array $context): array
    {
        $tenancy->loadMissing(['property.owner', 'tenant']);

        if ($type === 'rent' && isset($context['rent_schedules'])) {
            $tenancy->setRelation('rentSchedule', $context['rent_schedules']);
        }

        if ($type === 'overdue' && isset($context['overdue_summary'])) {
            $tenancy->setRelation('overdueSummary', collect($context['overdue_summary']));
        }

        $lineItems = $this->lineItemsFor($tenancy, $type, $context);
        $legacyType = match ($type) {
            'security_deposit' => 'deposit',
            default => $type,
        };
        $legacy = InvoiceService::buildRentInvoice($tenancy, $legacyType);
        $dueDate = $context['due_date'] ?? ($legacy['due_date'] ?? null);
        $issueDate = isset($context['issue_date']) ? Carbon::parse($context['issue_date']) : now();
        $paidAt = isset($context['paid_at']) ? Carbon::parse($context['paid_at']) : now();
        $subtotal = collect($lineItems)->sum(fn (array $item) => (float) $item['amount']);

        return [
            'subject' => $legacy['subject'],
            'currency' => Currency::code(),
            'recipient_name' => $tenancy->tenant?->name,
            'recipient_email' => $tenancy->tenant?->email,
            'issue_date' => $issueDate->toDateString(),
            'due_date' => $dueDate ? Carbon::parse($dueDate)->toDateString() : null,
            'paid_at' => $paidAt,
            'billing_period_start' => $context['billing_period_start'] ?? $this->billingPeriodStart($lineItems),
            'billing_period_end' => $context['billing_period_end'] ?? $this->billingPeriodEnd($lineItems),
            'subtotal' => $subtotal,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => $subtotal,
            'billing_snapshot' => [
                'property_name' => $tenancy->property?->property_name,
                'property_address' => $tenancy->property?->address,
                'tenant_name' => $tenancy->tenant?->name,
                'tenant_email' => $tenancy->tenant?->email,
                'owner_name' => $tenancy->property?->owner?->name,
                'late_payment_penalty_policy' => $tenancy->property?->late_payment_penalty,
                'payment_reference' => $context['stripe_payment_intent_id'] ?? null,
            ],
            'line_items' => $lineItems,
        ];
    }

    private function lineItemsFor(PropertyTenant $tenancy, string $type, array $context): array
    {
        return match ($type) {
            'rent' => collect($context['rent_schedules'] ?? [])
                ->map(fn ($schedule) => [
                    'label' => 'Monthly Rent - '.Carbon::parse($schedule->month)->format('F Y'),
                    'description' => 'Monthly rent charge',
                    'period_start' => Carbon::parse($schedule->month)->startOfMonth()->toDateString(),
                    'period_end' => Carbon::parse($schedule->month)->endOfMonth()->toDateString(),
                    'quantity' => 1,
                    'unit_amount' => (float) $schedule->amount,
                    'amount' => (float) $schedule->amount,
                    'currency' => Currency::code(),
                ])
                ->concat(
                    collect($context['late_fee_summary']['items'] ?? [])
                        ->map(fn (array $fee) => [
                            'label' => 'Late Fee - '.($fee['month'] ?? 'Rent'),
                            'description' => $fee['policy_text'] ?? 'Late payment fee',
                            'period_start' => isset($fee['due_date']) ? Carbon::parse($fee['due_date'])->startOfMonth()->toDateString() : null,
                            'period_end' => isset($fee['due_date']) ? Carbon::parse($fee['due_date'])->endOfMonth()->toDateString() : null,
                            'quantity' => 1,
                            'unit_amount' => (float) ($fee['amount'] ?? 0),
                            'amount' => (float) ($fee['amount'] ?? 0),
                            'currency' => Currency::code(),
                        ])
                )->values()->all(),
            'overdue' => collect($context['overdue_summary']['months'] ?? [])
                ->map(fn (array $month) => [
                    'label' => 'Overdue Rent - '.($month['month'] ?? 'Unknown Period'),
                    'description' => 'Overdue rent settlement',
                    'period_start' => isset($month['due_date']) ? Carbon::parse($month['due_date'])->startOfMonth()->toDateString() : null,
                    'period_end' => isset($month['due_date']) ? Carbon::parse($month['due_date'])->endOfMonth()->toDateString() : null,
                    'quantity' => 1,
                    'unit_amount' => (float) ($month['amount'] ?? 0),
                    'amount' => (float) ($month['amount'] ?? 0),
                    'currency' => Currency::code(),
                ])
                ->concat(
                    collect($context['overdue_summary']['late_fee_items'] ?? [])
                        ->map(fn (array $fee) => [
                            'label' => 'Late Fee - '.($fee['month'] ?? 'Rent'),
                            'description' => $fee['policy_text'] ?? 'Late payment fee',
                            'period_start' => isset($fee['due_date']) ? Carbon::parse($fee['due_date'])->startOfMonth()->toDateString() : null,
                            'period_end' => isset($fee['due_date']) ? Carbon::parse($fee['due_date'])->endOfMonth()->toDateString() : null,
                            'quantity' => 1,
                            'unit_amount' => (float) ($fee['amount'] ?? 0),
                            'amount' => (float) ($fee['amount'] ?? 0),
                            'currency' => Currency::code(),
                        ])
                )->values()->all(),
            'security_deposit' => [[
                'label' => 'Security Deposit',
                'description' => 'Tenancy security deposit',
                'period_start' => $tenancy->start_date ? Carbon::parse($tenancy->start_date)->toDateString() : null,
                'period_end' => $tenancy->end_date ? Carbon::parse($tenancy->end_date)->toDateString() : null,
                'quantity' => 1,
                'unit_amount' => (float) $tenancy->security_deposit_amount,
                'amount' => (float) $tenancy->security_deposit_amount,
                'currency' => Currency::code(),
            ]],
            default => [],
        };
    }

    private function billingPeriodStart(array $lineItems): ?string
    {
        return collect($lineItems)->pluck('period_start')->filter()->sort()->first();
    }

    private function billingPeriodEnd(array $lineItems): ?string
    {
        return collect($lineItems)->pluck('period_end')->filter()->sort()->last();
    }

    private function notifyIssued(Invoice $invoice): void
    {
        $title = 'Invoice Issued: '.$invoice->invoice_number;
        $message = sprintf(
            'Your %s invoice for %s has been issued for %s.',
            str_replace('_', ' ', $invoice->type),
            $invoice->billing_snapshot['property_name'] ?? 'your property',
            Currency::format($invoice->total)
        );

        $existing = NotificationModel::query()
            ->where('user_id', $invoice->recipient_user_id)
            ->where('type', 'invoice')
            ->where('notifiable_type', Invoice::class)
            ->where('notifiable_id', $invoice->id)
            ->first();

        if ($existing) {
            return;
        }

        $notification = NotificationModel::create([
            'user_id' => $invoice->recipient_user_id,
            'type' => 'invoice',
            'title' => $title,
            'message' => $message,
            'notifiable_type' => Invoice::class,
            'notifiable_id' => $invoice->id,
        ]);

        try {
            broadcast(new NotificationCreated(
                notification: $notification->toArray(),
                userId: $invoice->recipient_user_id,
                context: ['invoice_id' => $invoice->id]
            ))->toOthers();
        } catch (\Throwable $e) {
            Log::warning('Invoice notification broadcast failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

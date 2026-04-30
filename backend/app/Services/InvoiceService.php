<?php

namespace App\Services;

use App\Models\PropertyTenant;
use App\Models\RentSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    /**
     * Build a rent, deposit, or overdue invoice
     *
     * @param  string  $type  'rent', 'deposit', 'rent_and_deposit', 'overdue'
     */
    public static function buildRentInvoice(
        RentSchedule|PropertyTenant $model,
        string $type
    ): array {

        Log::info('Starting buildRentInvoice', [
            'model_type' => get_class($model),
            'type' => $type,
        ]);

        Log::info('Initializing variables');
        $subject = 'Invoice for Rent Deposit';
        $items = [];
        $total = 0;

        // Normalize rentSchedule(s) to a collection
        Log::info('Normalizing rentSchedule');

        if ($model instanceof PropertyTenant) {
            Log::info('Model is PropertyTenant');

            $rentSchedules = $model->rentSchedule instanceof \Illuminate\Support\Collection
                ? $model->rentSchedule
                : collect($model->rentSchedule ? [$model->rentSchedule] : []);

            Log::info('rentSchedules prepared for PropertyTenant', [
                'count' => $rentSchedules->count(),
            ]);
        } else {
            Log::info('Model is RentSchedule');

            $rentSchedules = collect([$model]);

            Log::info('rentSchedules prepared for RentSchedule', [
                'count' => $rentSchedules->count(),
            ]);
        }

        // Resolve property
        Log::info('Resolving property name');

        $property = $model->property?->property_name
            ?? $model->tenancy?->property?->property_name
            ?? 'Property';

        Log::info('Property resolved', [
            'property' => $property,
        ]);

        // Resolve tenant
        Log::info('Resolving tenant name');

        $tenant = $model->tenant?->name ?? 'Tenant';

        Log::info('Tenant resolved', [
            'tenant' => $tenant,
        ]);

        // Resolve due date
        Log::info('Resolving due date from rentSchedules');

        $dueDate = $rentSchedules
            ->pluck('due_date')
            ->filter()
            ->sort()
            ->last();

        Log::info('Latest due date extracted', [
            'due_date_raw' => $dueDate,
        ]);

        $dueDate = $dueDate ? Carbon::parse($dueDate)->toDateString() : ($model->due_date ?? now()->toDateString());

        Log::info('Final due date resolved', [
            'due_date' => $dueDate,
        ]);

        // Rent / Rent + Deposit
        Log::info('Checking if rent items should be added', [
            'type' => $type,
        ]);

        if (in_array($type, ['rent', 'rent_and_deposit'])) {
            Log::info('Adding rent items', [
                'count' => $rentSchedules->count(),
            ]);

            foreach ($rentSchedules as $rent) {

                Log::info('Processing rent schedule', [
                    'rent_id' => $rent->id ?? null,
                ]);

                if (! $rent) {
                    Log::warning('Encountered null rent schedule, skipping');

                    continue;
                }

                $items[] = [
                    'label' => 'Monthly Rent',
                    'amount' => $rent->amount,
                ];

                Log::info('Rent item pushed to items array', [
                    'rent_id' => $rent->id ?? null,
                    'amount' => $rent->amount,
                ]);

                $total += $rent->amount;

                Log::info('Total updated after rent addition', [
                    'running_total' => $total,
                ]);

                Log::info('Rent item added', [
                    'rent_id' => $rent->id ?? null,
                    'amount' => $rent->amount,
                ]);
            }
        }

        // Security deposit
        Log::info('Checking if security deposit should be added', [
            'type' => $type,
        ]);

        if (in_array($type, ['deposit', 'rent_and_deposit'])) {

            Log::info('Setting subject for security deposit invoice');

            $subject = 'Invoice for Security Deposit';

            if (isset($model->security_deposit_amount)) {

                Log::info('Security deposit amount found', [
                    'amount' => $model->security_deposit_amount,
                ]);

                $items[] = [
                    'label' => 'Security Deposit',
                    'amount' => $model->security_deposit_amount,
                ];

                Log::info('Security deposit item pushed to items array');

                $total += $model->security_deposit_amount;

                Log::info('Total updated after security deposit addition', [
                    'running_total' => $total,
                ]);

                Log::info('Security deposit item added', [
                    'amount' => $model->security_deposit_amount,
                ]);
            } else {
                Log::warning('Security deposit amount not set on model');
            }
        }

        // Overdue
        Log::info('Checking overdue condition', [
            'type' => $type,
            'relation_loaded' => $model?->relationLoaded('overdueSummary'),
        ]);

        if ($type === 'overdue' && $model?->relationLoaded('overdueSummary')) {

            Log::info('Processing overdue invoice');

            $summary = $model->overdueSummary;

            Log::info('Overdue summary fetched', [
                'months_count' => count($summary['months'] ?? []),
            ]);

            foreach ($summary['months'] as $m) {

                Log::info('Processing overdue month', [
                    'month' => $m['month'] ?? null,
                    'amount' => $m['amount'] ?? null,
                ]);

                $items[] = [
                    'label' => 'Rent – '.$m['month'],
                    'amount' => $m['amount'],
                ];

                Log::info('Overdue item pushed to items array');
            }

            $total = $summary['total_amount'] ?? 0;
            $dueDate = $summary['latest_due'] ?? $dueDate;

            Log::info('Overdue totals resolved', [
                'total' => $total,
                'due_date' => $dueDate,
            ]);
        }

        Log::info('Preparing final invoice array');

        $invoice = [
            'subject' => $subject,
            'property' => $property,
            'tenant' => $tenant,
            'due_date' => $dueDate,
            'items' => $items,
            'total' => $total,
        ];

        Log::info('Invoice built successfully', $invoice);

        Log::info('Ending buildRentInvoice');

        return $invoice;
    }
}

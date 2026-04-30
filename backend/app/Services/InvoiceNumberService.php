<?php

namespace App\Services;

use App\Models\Invoice;
use Carbon\Carbon;

class InvoiceNumberService
{
    public function nextNumber(): string
    {
        $year = Carbon::now()->format('Y');
        $prefix = "INV-{$year}-";

        $latest = Invoice::query()
            ->where('invoice_number', 'like', $prefix.'%')
            ->orderByDesc('invoice_number')
            ->lockForUpdate()
            ->value('invoice_number');

        $next = 1;

        if ($latest) {
            $numeric = (int) substr($latest, strrpos($latest, '-') + 1);
            $next = $numeric + 1;
        }

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}

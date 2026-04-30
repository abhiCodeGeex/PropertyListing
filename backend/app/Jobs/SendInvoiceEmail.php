<?php

namespace App\Jobs;

use App\Mail\RentInvoiceMail;
use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendInvoiceEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $invoiceId) {}

    public function handle(): void
    {
        $invoice = Invoice::with('recipient')->find($this->invoiceId);

        if (! $invoice || ! $invoice->recipient?->email) {
            return;
        }

        try {
            Mail::to($invoice->recipient->email)->send(new RentInvoiceMail($invoice));

            $invoice->forceFill([
                'delivery_status' => 'sent',
                'sent_at' => $invoice->sent_at ?? now(),
                'last_sent_at' => now(),
                'send_attempts' => (int) $invoice->send_attempts + 1,
                'failure_reason' => null,
            ])->save();
        } catch (\Throwable $e) {
            Log::error('Invoice email delivery failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            $invoice->forceFill([
                'delivery_status' => 'failed',
                'last_sent_at' => now(),
                'send_attempts' => (int) $invoice->send_attempts + 1,
                'failure_reason' => $e->getMessage(),
            ])->save();

            throw $e;
        }
    }
}

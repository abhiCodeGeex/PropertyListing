<?php

namespace App\Services;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class InvoicePdfService
{
    public function store(Invoice $invoice): Invoice
    {
        $disk = 'local';
        $payload = $invoice->toDocumentArray();
        $pdf = Pdf::loadView('pdf.invoice', ['invoice' => $payload]);
        $contents = $pdf->output();
        $path = 'invoices/'.now()->format('Y/m').'/'.$invoice->invoice_number.'.pdf';

        Storage::disk($disk)->put($path, $contents);

        $invoice->forceFill([
            'pdf_disk' => $disk,
            'pdf_path' => $path,
            'pdf_hash' => hash('sha256', $contents),
            'pdf_generated_at' => now(),
        ])->save();

        return $invoice->fresh();
    }
}

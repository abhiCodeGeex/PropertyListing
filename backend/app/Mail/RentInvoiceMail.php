<?php

namespace App\Mail;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class RentInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public Invoice $invoice;

    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    public function build()
    {
        $payload = $this->invoice->toDocumentArray();

        $attachment = $this->invoice->pdf_path && $this->invoice->pdf_disk
            ? Storage::disk($this->invoice->pdf_disk)->get($this->invoice->pdf_path)
            : Pdf::loadView('pdf.invoice', ['invoice' => $payload])->output();

        return $this
            ->subject($payload['subject'])
            ->view('emails.invoice')
            ->with(['invoice' => $payload])
            ->attachData(
                $attachment,
                $this->invoice->invoice_number.'.pdf',
                ['mime' => 'application/pdf']
            );
    }
}

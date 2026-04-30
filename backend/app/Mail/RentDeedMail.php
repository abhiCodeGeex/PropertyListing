<?php

namespace App\Mail;

use App\Models\RentDeed;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RentDeedMail extends Mailable
{
    use Queueable, SerializesModels;

    public RentDeed $rentDeed;

    public User $recipient;

    public function __construct(RentDeed $rentDeed, User $recipient)
    {
        $this->rentDeed = $rentDeed;
        $this->recipient = $recipient;
    }

    public function build()
    {
        $document = $this->toDocumentArray();
        $pdf = Pdf::loadView('pdf.rent-deed', ['rentDeed' => $document])->output();

        return $this
            ->subject('Rent Deed for '.$document['property_name'])
            ->view('emails.rent-deed')
            ->with([
                'rentDeed' => $document,
                'recipient' => $this->recipient,
            ])
            ->attachData(
                $pdf,
                $document['agreement_number'].'.pdf',
                ['mime' => 'application/pdf']
            );
    }

    private function toDocumentArray(): array
    {
        $this->rentDeed->loadMissing(['property.owner', 'property.manager', 'owner', 'tenant']);

        return [
            'agreement_number' => $this->rentDeed->agreement_number,
            'agreement_date' => optional($this->rentDeed->agreement_date)->format('Y-m-d'),
            'property_name' => $this->rentDeed->property?->property_name ?? 'Property',
            'property_address' => $this->rentDeed->property?->address,
            'owner_name' => $this->rentDeed->owner?->name,
            'tenant_name' => $this->rentDeed->tenant?->name,
            'manager_name' => $this->rentDeed->property?->manager?->name,
            'monthly_rent' => $this->rentDeed->property?->monthly_rent,
            'rent_due_date' => $this->rentDeed->rent_due_date,
            'maintenance_charges' => $this->rentDeed->maintenance_charges,
            'other_details' => $this->rentDeed->other_details,
        ];
    }
}

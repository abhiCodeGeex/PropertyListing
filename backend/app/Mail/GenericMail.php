<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class GenericMail extends Mailable
{
    public function __construct(
        public string $messageHtml,
        public string $subjectLine
    ) {}

    public function build()
    {
        return $this->subject($this->subjectLine)
            ->view('emails.rent_mail')
            ->with([
                'messageHtml' => $this->messageHtml,
            ]);
    }
}

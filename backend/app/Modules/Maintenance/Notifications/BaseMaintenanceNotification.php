<?php

namespace App\Modules\Maintenance\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

abstract class BaseMaintenanceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payload = $this->payload($notifiable);

        return (new MailMessage)
            ->subject($payload['subject'])
            ->view('emails.notification', [
                'mailData' => $payload,
            ]);
    }

    abstract protected function payload(object $notifiable): array;
}

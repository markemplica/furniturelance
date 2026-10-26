<?php

namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const SUBJECT = 'Reservation request approved - confirm item readiness';

    public function __construct(public Reservation $reservation) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(self::SUBJECT)
            ->view([
                'html' => 'emails.customer-notification',
                'text' => 'emails.customer-notification-text',
            ], [
                'eyebrow' => 'RESERVATION UPDATE',
                'heading' => 'Your request was approved',
                'intro' => 'Good news, '.$notifiable->name.'. Lance Furniture Shop reviewed and approved your reservation request.',
                'codeLabel' => null,
                'code' => null,
                'expiry' => null,
                'details' => [
                    ['label' => 'REFERENCE', 'value' => $this->reservation->reference],
                    ['label' => 'FURNITURE', 'value' => $this->reservation->product->name],
                    ['label' => 'SHOP', 'value' => $this->reservation->branch],
                ],
                'buttonLabel' => 'View reservation request',
                'buttonUrl' => rtrim((string) config('app.url'), '/').route('reservations.index', [], false),
                'note' => 'Approval confirms the request review only. Please contact the shop before visiting to confirm physical availability, item readiness, and operating hours. No purchase or payment has been made through FurniCraft.',
            ]);
    }
}

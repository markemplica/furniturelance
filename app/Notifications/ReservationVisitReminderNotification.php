<?php
namespace App\Notifications;

use App\Models\Reservation;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReservationVisitReminderNotification extends Notification
{
    public function __construct(public Reservation $reservation) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Reminder: planned visit to Lance Furniture Shop')
            ->view([
                'html' => 'emails.customer-notification',
                'text' => 'emails.customer-notification-text',
            ], [
                'eyebrow' => 'VISIT REMINDER',
                'heading' => 'Your planned visit is coming up',
                'intro' => 'Hello '.$notifiable->name.'. Here are the details you saved for your shop visit.',
                'codeLabel' => null,
                'code' => null,
                'expiry' => null,
                'details' => array_values(array_filter([
                    ['label' => 'PLANNED VISIT', 'value' => $this->reservation->preferred_visit_at?->format('F j, Y · g:i A')],
                    ['label' => 'REFERENCE', 'value' => $this->reservation->reference],
                    ['label' => 'SHOP', 'value' => $this->reservation->branch],
                ], fn (array $detail) => filled($detail['value']))),
                'buttonLabel' => 'View reservation request',
                'buttonUrl' => rtrim((string) config('app.url'), '/').route('reservations.index', [], false),
                'note' => 'Please contact Lance Furniture Shop before travelling to confirm the item is available and ready. FurniCraft does not track live inventory.',
            ]);
    }
}

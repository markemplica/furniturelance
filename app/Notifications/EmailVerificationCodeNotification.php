<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmailVerificationCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $code)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your FurniCraft account')
            ->view([
                'html' => 'emails.customer-notification',
                'text' => 'emails.customer-notification-text',
            ], [
                'eyebrow' => 'ACCOUNT SECURITY',
                'heading' => 'Verify your email',
                'intro' => 'Welcome to FurniCraft, '.$notifiable->name.'. Enter this code on the verification page to activate your customer account.',
                'codeLabel' => 'YOUR VERIFICATION CODE',
                'code' => $this->code,
                'expiry' => 'This code expires in 15 minutes and can only be used once.',
                'details' => [],
                'buttonLabel' => null,
                'buttonUrl' => null,
                'note' => 'If you did not create this account, you can safely ignore this email.',
            ]);
    }
}

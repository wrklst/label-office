<?php

namespace App\Notifications;

use App\Models\LoginCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails the one-time code a signature is confirmed with. Encrypted
 * because the queue payload carries the code in plain text.
 */
class AgreementSigningCode extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $code) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your code to sign: :code', ['code' => $this->code]))
            ->line(__('Enter this code to sign the agreement you have open:'))
            ->line("**{$this->code}**")
            ->line(__('Entering it is your signature. The code expires in :minutes minutes and works once.', ['minutes' => LoginCode::MINUTES_VALID]))
            ->line(__('If you did not ask for a code, do not pass it on and tell the label.'));
    }
}

<?php

namespace App\Notifications;

use App\Models\Agreement;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone that an agreement waits for their signature.
 */
class AgreementToSign extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Agreement $agreement, public readonly string $labelName) {}

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
    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('An agreement with :label is waiting for your signature', ['label' => $this->labelName]))
            ->line(__('An agreement is ready for you to read and sign.'))
            ->action(__('Read the agreement'), route('my-agreements.show', $this->agreement->id))
            ->line(__('Nothing is agreed until every party has signed. If you did not expect this, you can ignore this email.'));
    }
}

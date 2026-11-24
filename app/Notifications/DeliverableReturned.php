<?php

namespace App\Notifications;

use App\Models\Agreement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the payee that the label asks for something to be delivered
 * again, and why.
 */
class DeliverableReturned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Agreement $agreement, public readonly string $title, public readonly string $note) {}

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
        // What people typed is quoted as plain words: the mail is written in Markdown.
        $plain = fn (string $text): string => trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[\[\]()<>*_`!#\\\\|]+/', ' ', $text)));

        return (new MailMessage)
            ->subject(__('Please deliver again: :title', ['title' => $plain($this->title)]))
            ->line(__('The label asks you to deliver this again:'))
            ->line($plain($this->title))
            ->line(__('Their note: :note', ['note' => $plain($this->note)]))
            ->action(__('Open the agreement'), route('my-agreements.show', $this->agreement->id));
    }
}

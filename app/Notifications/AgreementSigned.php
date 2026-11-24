<?php

namespace App\Notifications;

use App\Models\Agreement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sends every party its copy of an agreement all have signed: the text
 * and the record of the signatures, as a file to keep.
 */
class AgreementSigned extends Notification implements ShouldQueue
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
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Signed by everyone: your agreement with :label', ['label' => $this->labelName]))
            ->line(__('Every party has signed the agreement. Your copy is attached as a PDF: the text and the record of who signed it and when. Keep it. The text file holds the same, in the form its checksum is taken from.'))
            ->action(__('Open the agreement'), route('my-agreements.show', $this->agreement->id))
            ->attachData($this->agreement->pdf(), "agreement-{$this->agreement->id}.pdf", ['mime' => 'application/pdf'])
            // The plain text is what the hash is taken from, so anyone can check it.
            ->attachData($this->agreement->record(), "agreement-{$this->agreement->id}.txt", ['mime' => 'text/plain; charset=utf-8']);
    }
}

<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Generic event-driven notification email.
 *
 * The body is rendered from a template stored in `notification_templates`
 * with {{variable}} placeholders already interpolated by NotificationService.
 * Subject defaults to the event label and can be overridden per send.
 */
class EventNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $eventLabel,
        public string $renderedBody,
        public string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[IBE Reality] {$this->eventLabel}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.event-notification',
            with: [
                'eventLabel' => $this->eventLabel,
                'body' => $this->renderedBody,
                'recipientName' => $this->recipientName,
            ],
        );
    }
}

<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Carries an approved notification template to a patient or staff member by
 * email. The body is always the pre-rendered template text: no clinical or
 * financial content is generated here, and the driver only supplies a subject
 * line the caller already resolved.
 *
 * The message is rendered with `dir="auto"` so an Urdu body lays out
 * right-to-left and an English body left-to-right without a second template
 * (DESIGN §19).
 */
class ClinicNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $recipient  Destination email address.
     * @param  string  $subjectLine  Already-rendered subject line.
     * @param  string  $body  Pre-rendered, placeholder-substituted template body.
     */
    public function __construct(
        private readonly string $recipient,
        private readonly string $subjectLine,
        private readonly string $body,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {
        // Set on the mailable itself rather than only in the envelope so the
        // recipient is inspectable before the message is built (which is what
        // Mail::assertSent's callback sees under Mail::fake()).
        $this->to($recipient);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            subject: $this->subjectLine,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.clinic-notification',
            with: [
                'body' => $this->body,
                'subject' => $this->subjectLine,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}

<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $subjectLine,
        public string $status,
        public string $messageBody,
        public string $supportUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'پاسخ جدید به تیکت: ' . $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket-reply',
            with: ['subject' => $this->subjectLine],
        );
    }
}

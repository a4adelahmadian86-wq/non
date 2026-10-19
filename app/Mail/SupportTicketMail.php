<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportTicketMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $headline,
        public string $name,
        public string $subjectLine,
        public string $status,
        public string $messageBody,
        public string $supportUrl,
        public string $ticketCode = '',
        public string $mailSubject = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailSubject !== '' ? $this->mailSubject : $this->headline
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.support-ticket',
            with: [
                'subject' => $this->subjectLine,
                'headline' => $this->headline,
                'name' => $this->name,
                'status' => $this->status,
                'messageBody' => $this->messageBody,
                'supportUrl' => $this->supportUrl,
                'ticketCode' => $this->ticketCode,
            ],
        );
    }
}

<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $mobile,
        public ?string $email,
        public string $editorUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'خوش آمدید به فراست');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.welcome');
    }
}

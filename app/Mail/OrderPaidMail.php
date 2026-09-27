<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderPaidMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public int $orderId,
        public int $amount,
        public string $paidAt,
        public string $editorUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تأیید پرداخت سفارش #' . $this->orderId);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.order-paid');
    }
}

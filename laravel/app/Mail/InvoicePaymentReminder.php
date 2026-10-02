<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class InvoicePaymentReminder extends Mailable
{
    public function __construct(
        public object $invoice,
        public string $companyName,
        public int $daysLeft,
        public string $dueOn,
        public ?string $agentName,
        public ?string $agentEmail,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Payment reminder: invoice INV-%s due %s', str_pad((string) $this->invoice->number, 4, '0', STR_PAD_LEFT), $this->dueOn),
            replyTo: $this->agentEmail ? [new Address($this->agentEmail, $this->agentName ?: '')] : [],
            bcc: $this->agentEmail ? [new Address($this->agentEmail, $this->agentName ?: '')] : [],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invoice-reminder');
    }
}

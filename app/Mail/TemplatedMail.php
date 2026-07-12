<?php

namespace TriggerEngage\Server\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TemplatedMail extends Mailable
{
    public function __construct(
        public string $renderedSubject,
        public string $renderedBody,
        public ?string $fromAddress = null,
        public ?string $fromName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->renderedSubject,
            from: $this->fromAddress
                ? new Address($this->fromAddress, $this->fromName)
                : null,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->renderedBody);
    }
}

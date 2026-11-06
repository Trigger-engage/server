<?php

namespace TriggerEngage\Server\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

class TemplatedMail extends Mailable
{
    public function __construct(
        public string $renderedSubject,
        public string $renderedBody,
        public ?string $fromAddress = null,
        public ?string $fromName = null,
        public ?string $replyToAddress = null,
        public ?string $replyToName = null,
        public ?string $oneClickUnsubscribeUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->renderedSubject,
            from: $this->fromAddress
                ? new Address($this->fromAddress, $this->fromName)
                : null,
            replyTo: $this->replyToAddress
                ? [new Address($this->replyToAddress, $this->replyToName)]
                : [],
        );
    }

    /**
     * RFC 8058 one-click unsubscribe: mail clients show their own Unsubscribe
     * button and POST to this URL. Gmail and Yahoo require it of bulk senders.
     */
    public function headers(): Headers
    {
        return new Headers(text: $this->oneClickUnsubscribeUrl ? [
            'List-Unsubscribe' => '<'.$this->oneClickUnsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ] : []);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->renderedBody);
    }
}

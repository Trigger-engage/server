<?php

namespace App\Engine\Channels;

use App\Engine\TemplateRenderer;
use App\Mail\TemplatedMail;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Person;
use App\Models\Template;
use Illuminate\Support\Facades\Mail;

class EmailChannel
{
    public function __construct(protected TemplateRenderer $renderer)
    {
    }

    /**
     * Render and send. Returns the persisted Message row (status sent/failed),
     * or null when the person has no email address to send to.
     */
    public function send(Channel $channel, Template $template, Person $person, array $context): ?Message
    {
        if (blank($person->email)) {
            return null;
        }

        $subject = $this->renderer->render($template->subject ?? '', $context);
        $body = $this->renderer->render($template->body, $context);

        $message = Message::create([
            'workspace_id' => $person->workspace_id,
            'person_id' => $person->id,
            'template_id' => $template->id,
            'channel' => 'email',
            'to_address' => $person->email,
            'subject' => $subject,
            'body' => $body,
            'status' => 'queued',
        ]);

        try {
            $this->mailer($channel)
                ->to($person->email)
                ->send(new TemplatedMail(
                    renderedSubject: $subject,
                    renderedBody: $body,
                    fromAddress: $template->from_address,
                    fromName: $template->from_name,
                ));

            $message->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (\Throwable $exception) {
            $message->update(['status' => 'failed', 'error' => $exception->getMessage()]);
        }

        return $message->refresh();
    }

    protected function mailer(Channel $channel)
    {
        // "smtp" builds an on-the-fly mailer from the workspace's encrypted
        // credentials (ZeptoMail et al. are SMTP-compatible). Anything else
        // falls through to the app's named mailers — log/array in dev+tests.
        if ($channel->driver === 'smtp') {
            $credentials = $channel->credentials ?? [];

            return Mail::build([
                'transport' => 'smtp',
                'host' => $credentials['host'] ?? null,
                'port' => (int) ($credentials['port'] ?? 587),
                'username' => $credentials['username'] ?? null,
                'password' => $credentials['password'] ?? null,
                'encryption' => $credentials['encryption'] ?? 'tls',
            ]);
        }

        return Mail::mailer($channel->driver);
    }
}

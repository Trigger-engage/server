<?php

namespace TriggerEngage\Server\Engine\Channels;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use TriggerEngage\Server\Engine\EmailLayoutRenderer;
use TriggerEngage\Server\Engine\TemplateRenderer;
use TriggerEngage\Server\Mail\TemplatedMail;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\RunStep;
use TriggerEngage\Server\Models\Template;

class EmailChannel
{
    public function __construct(
        protected TemplateRenderer $renderer,
        protected EmailLayoutRenderer $layouts,
    ) {}

    /**
     * Render and send. The message row is keyed to the already-reserved run
     * step, so a retry updates the ledger rather than creating another entry.
     *
     * @return array{message: Message, warnings: array<int, string>}|null
     */
    public function send(
        Channel $channel,
        Template $template,
        Person $person,
        array $context,
        ?RunStep $step = null,
        ?Message $message = null,
    ): ?array {
        if (blank($person->email)) {
            return null;
        }

        $this->renderer->reset();
        $subject = $this->renderer->render($template->subject ?? '', $context);
        $content = $this->renderer->render($template->body, $context);
        $preheader = $this->renderer->render($template->preheader ?? '', $context);
        $warnings = $this->renderer->missingVariables();

        $message ??= Message::query()->firstOrCreate(
            ['run_step_id' => $step?->id],
            [
                'workspace_id' => $person->workspace_id,
                'person_id' => $person->id,
                'template_id' => $template->id,
                'channel' => 'email',
                'to_address' => $person->email,
                'subject' => $subject,
                'body' => $content,
                'status' => 'queued',
            ]
        );

        $unsubscribeUrl = URL::temporarySignedRoute('unsubscribe.show', now()->addYear(), ['message' => $message->id]);
        $body = $this->layouts->render($template, $content, $preheader, $unsubscribeUrl);

        if ($message->status === 'sent') {
            return ['message' => $message, 'warnings' => $warnings];
        }

        $message->update([
            'to_address' => $person->email,
            'subject' => $subject,
            'body' => $body,
            'status' => 'sending',
            'error' => null,
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

        return ['message' => $message->refresh(), 'warnings' => $warnings];
    }

    protected function mailer(Channel $channel)
    {
        // "smtp" builds an on-the-fly mailer from the workspace's encrypted
        // credentials (ZeptoMail et al. are SMTP-compatible). Anything else
        // falls through to the app's named mailers — log/array in dev+tests.
        if ($channel->driver !== 'smtp') {
            return Mail::mailer($channel->driver);
        }

        $credentials = $channel->credentials ?? [];

        $config = [
            'transport' => 'smtp',
            'host' => $credentials['host'] ?? null,
            'port' => (int) ($credentials['port'] ?? 587),
            'username' => $credentials['username'] ?? null,
            'password' => $credentials['password'] ?? null,
            'encryption' => $credentials['encryption'] ?? 'tls',
        ];

        // Registering the config and resolving it by name, rather than Mail::build(),
        // which does not exist before Laravel 11 — this package supports ^10.48, and
        // on a Laravel 10 host every smtp-channel send died with "Method
        // Illuminate\Mail\Mailer::build does not exist". The credentials hash is part
        // of the name so MailManager's instance cache cannot keep serving a
        // long-running queue worker the credentials a channel had at boot.
        $name = 'trigger-engage-'.$channel->getKey().'-'.substr(md5(serialize($config)), 0, 8);

        config()->set("mail.mailers.{$name}", $config);

        return Mail::mailer($name);
    }
}

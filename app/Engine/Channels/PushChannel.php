<?php

namespace TriggerEngage\Server\Engine\Channels;

use Illuminate\Support\Facades\Http;
use TriggerEngage\Server\Engine\TemplateRenderer;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\RunStep;
use TriggerEngage\Server\Models\Template;

class PushChannel
{
    public function __construct(protected TemplateRenderer $renderer) {}

    /** @return array{message: Message, warnings: array<int, string>}|null */
    public function send(Channel $channel, Template $template, Person $person, array $context, ?RunStep $step = null, ?Message $message = null): ?array
    {
        $externalId = ($person->getAttribute('attributes') ?? [])['onesignal_external_id'] ?? $person->external_id;

        if (blank($externalId)) {
            return null;
        }

        $this->renderer->reset();
        $subject = $this->renderer->render($template->subject ?? '', $context);
        $body = $this->renderer->render($template->body, $context);
        $credentials = $channel->credentials ?? [];
        $message ??= Message::query()->firstOrCreate(
            ['run_step_id' => $step?->id],
            [
                'workspace_id' => $person->workspace_id,
                'person_id' => $person->id,
                'template_id' => $template->id,
                'channel' => 'push',
                'to_address' => $externalId,
                'subject' => $subject,
                'body' => $body,
                'status' => 'queued',
            ]
        );

        if ($message->status === 'sent') {
            return ['message' => $message, 'warnings' => $this->renderer->missingVariables()];
        }

        $message->update(['subject' => $subject, 'body' => $body, 'status' => 'sending', 'error' => null]);

        try {
            $response = Http::baseUrl('https://api.onesignal.com')
                ->withHeaders(['Authorization' => 'Key '.($credentials['api_key'] ?? '')])
                ->timeout((int) ($credentials['timeout'] ?? 10))
                ->retry(2, 250, throw: false)
                ->acceptJson()
                ->asJson()
                ->post('/notifications', [
                    'app_id' => $credentials['app_id'] ?? null,
                    'include_aliases' => ['external_id' => [$externalId]],
                    'target_channel' => 'push',
                    'headings' => ['en' => $subject],
                    'contents' => ['en' => $body],
                    'data' => ['trigger_engage_message_id' => $message->id],
                ]);

            $providerId = $response->json('id');

            if ($response->failed() || blank($providerId)) {
                throw new \RuntimeException('OneSignal rejected the message: '.$response->body());
            }

            $message->update([
                'status' => 'sent',
                'provider_message_id' => (string) $providerId,
                'sent_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            $message->update(['status' => 'failed', 'error' => $exception->getMessage()]);
        }

        return ['message' => $message->refresh(), 'warnings' => $this->renderer->missingVariables()];
    }
}

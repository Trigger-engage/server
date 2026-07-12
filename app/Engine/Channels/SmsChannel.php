<?php

namespace TriggerEngage\Server\Engine\Channels;

use Illuminate\Support\Facades\Http;
use TriggerEngage\Server\Engine\TemplateRenderer;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\RunStep;
use TriggerEngage\Server\Models\Template;

class SmsChannel
{
    public function __construct(protected TemplateRenderer $renderer) {}

    /** @return array{message: Message, warnings: array<int, string>}|null */
    public function send(Channel $channel, Template $template, Person $person, array $context, ?RunStep $step = null, ?Message $message = null): ?array
    {
        if (blank($person->phone)) {
            return null;
        }

        $this->renderer->reset();
        $body = $this->renderer->render($template->body, $context);
        $credentials = $channel->credentials ?? [];
        $message ??= Message::query()->firstOrCreate(
            ['run_step_id' => $step?->id],
            [
                'workspace_id' => $person->workspace_id,
                'person_id' => $person->id,
                'template_id' => $template->id,
                'channel' => 'sms',
                'to_address' => $person->phone,
                'body' => $body,
                'status' => 'queued',
            ]
        );

        if ($message->status === 'sent') {
            return ['message' => $message, 'warnings' => $this->renderer->missingVariables()];
        }

        $message->update(['body' => $body, 'status' => 'sending', 'error' => null]);

        try {
            $baseUrl = rtrim((string) ($credentials['base_url'] ?? ''), '/');
            $response = Http::timeout((int) ($credentials['timeout'] ?? 10))
                ->retry(2, 250, throw: false)
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/api/sms/send', [
                    'api_key' => $credentials['api_key'] ?? null,
                    'to' => ltrim($person->phone, '+'),
                    'from' => $credentials['sender_id'] ?? $template->from_name,
                    'sms' => $body,
                    'type' => 'plain',
                    'channel' => $credentials['route'] ?? 'dnd',
                ]);

            $providerId = $response->json('message_id_str') ?? $response->json('message_id');

            if ($response->failed() || blank($providerId)) {
                throw new \RuntimeException('Termii rejected the message: '.$response->body());
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

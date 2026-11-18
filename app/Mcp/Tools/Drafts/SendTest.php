<?php

namespace TriggerEngage\Server\Mcp\Tools\Drafts;

use TriggerEngage\Server\Engine\Channels\EmailChannel;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\Audit;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Template;

class SendTest extends Tool
{
    public function __construct(protected EmailChannel $email) {}

    public function name(): string
    {
        return 'send_test';
    }

    public function title(): string
    {
        return 'Send a test email to yourself';
    }

    public function description(): string
    {
        return 'Email a template or a broadcast draft to your own address, through the real provider, with "[Test]" in '
            .'the subject. You can\'t choose the address: it is the one your credentials belong to, so a test never '
            .'reaches anyone else. Fill it in for a real person (their profile as {{ person.* }}) or the sample preview '
            .'context. Email only; render_template shows SMS and push text.';
    }

    public function writes(): bool
    {
        return true;
    }

    public function properties(): array
    {
        return [
            'template_id' => ['type' => 'integer', 'description' => 'Give this or broadcast_id.'],
            'broadcast_id' => ['type' => 'integer', 'description' => 'A draft broadcast to test.'],
            ...$this->personProperties('Fill it in with this person\'s profile'),
            'event' => ['type' => 'object', 'description' => 'Event payload for {{ event.* }}.'],
        ];
    }

    public function rules(): array
    {
        return [
            'template_id' => ['nullable', 'integer', 'required_without:broadcast_id', 'prohibits:broadcast_id'],
            'broadcast_id' => ['nullable', 'integer', 'required_without:template_id'],
            ...$this->personRules(required: false),
            'event' => ['nullable', 'array'],
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        if (blank($context->testRecipient)) {
            throw new ToolError('No test address is set up for your credentials, so send_test can\'t send. Use render_template to see the message instead.');
        }

        if (filled($arguments['broadcast_id'] ?? null)) {
            /** @var Broadcast $broadcast */
            $broadcast = $this->findOwned(Broadcast::class, $arguments['broadcast_id'], $context, 'broadcast');
            $template = $broadcast->messageTemplate();
            $channel = $broadcast->channelConfiguration;
            $subject = $broadcast;
        } else {
            /** @var Template $template */
            $template = $this->findOwned(Template::class, $arguments['template_id'], $context, 'template');
            $channel = Channel::query()->where('workspace_id', $context->workspace->id)->where('type', 'email')->orderByDesc('is_default')->first();
            $subject = $template;
        }

        if ($template->channel !== 'email') {
            throw new ToolError("send_test sends email only, and this is a {$template->channel} message. Use render_template to read it.");
        }

        if (! $channel) {
            throw new ToolError('There is no email channel set up to send the test through.');
        }

        $template->setRelation('workspace', $context->workspace);
        $person = $this->hasPerson($arguments) ? $this->findPerson($arguments, $context) : null;
        $sample = config('email_templates.preview_context', []);
        $renderContext = [
            'person' => $person ? $person->toContext() : ($sample['person'] ?? []),
            'event' => $arguments['event'] ?? ($person ? [] : ($sample['event'] ?? [])),
        ];

        try {
            $sent = $this->email->sendTest($channel, $template, $renderContext, $context->testRecipient);
        } catch (\Throwable $exception) {
            throw new ToolError('The provider refused the test: '.$context->redactor->text($exception->getMessage()));
        }

        Audit::record($context, 'test.sent', $subject, 'Sent a test of "'.($template->name ?? 'the broadcast').'" to the caller\'s own address.', ['to' => $context->testRecipient]);

        return [
            'sent_to' => $context->testRecipient,
            'subject' => $context->redactor->text($sent['subject']),
            'rendered_for' => $person ? $context->person($person) : 'the sample preview context',
            'via' => $channel->only('id', 'name', 'driver'),
            'missing_variables' => array_values(array_unique($sent['warnings'])),
        ];
    }
}

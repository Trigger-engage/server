<?php

namespace TriggerEngage\Server\Mcp\Tools\Messages;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\HtmlText;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\Tools\Messages\Concerns\PresentsMessages;
use TriggerEngage\Server\Models\Message;

class GetMessage extends Tool
{
    use PresentsMessages;

    public function name(): string
    {
        return 'get_message';
    }

    public function title(): string
    {
        return 'Get a message';
    }

    public function description(): string
    {
        return 'One message as it was sent: subject and body (email bodies as readable text with links, or the full HTML '
            .'on request), who it went to, where it came from, the provider\'s message id, each delivery timestamp '
            .'(sent, delivered, opened, clicked, bounced), the error if it failed, and whether a push receipt is still '
            .'pending. Signed links in the body (unsubscribe, preferences) have their signatures removed.';
    }

    public function properties(): array
    {
        return [
            'message_id' => ['type' => 'integer'],
            'format' => ['type' => 'string', 'enum' => ['text', 'html'], 'description' => 'How to return an email body (default text).'],
        ];
    }

    public function required(): array
    {
        return ['message_id'];
    }

    public function rules(): array
    {
        return ['message_id' => ['required', 'integer'], 'format' => ['nullable', 'in:text,html']];
    }

    public function handle(array $arguments, Context $context): array
    {
        /** @var Message $message */
        $message = $this->findOwned(Message::class, $arguments['message_id'], $context, 'message');
        $message->load($this->sourceRelations());

        $html = ($arguments['format'] ?? 'text') === 'html';
        $body = $message->channel === 'email' && ! $html ? HtmlText::from($message->body) : (string) $message->body;
        [$body, $truncated] = HtmlText::cap($body, $html ? 100000 : HtmlText::MAX_LENGTH);

        return [
            ...$this->summary($message, $context),
            'body' => $context->redactor->text($body),
            'body_truncated' => $truncated,
            'provider_message_id' => $message->provider_message_id,
            'awaiting_receipt' => ! empty($message->pending_receipts),
            'updated_at' => $context->time($message->updated_at),
            'url' => $context->url('messages.show', $message),
        ];
    }
}

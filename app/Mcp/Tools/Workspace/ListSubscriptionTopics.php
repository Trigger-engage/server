<?php

namespace TriggerEngage\Server\Mcp\Tools\Workspace;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\SubscriptionTopic;

class ListSubscriptionTopics extends Tool
{
    public function name(): string
    {
        return 'list_subscription_topics';
    }

    public function title(): string
    {
        return 'List subscription topics';
    }

    public function description(): string
    {
        return 'The topics people can opt out of (e.g. "Product updates") without unsubscribing from everything. A '
            .'template or broadcast tagged with a topic skips people who opted out of it. For each topic: whether people '
            .'start subscribed, whether it follows a true/false profile attribute instead of stored choices, how many '
            .'stored opt-ins and opt-outs it has, and how many templates use it.';
    }

    public function handle(array $arguments, Context $context): array
    {
        return [
            'topics' => SubscriptionTopic::query()
                ->where('workspace_id', $context->workspace->id)
                ->withCount([
                    'preferences as opted_out' => fn ($query) => $query->where('subscribed', false),
                    'preferences as opted_in' => fn ($query) => $query->where('subscribed', true),
                ])
                ->orderBy('name')
                ->get()
                ->map(fn (SubscriptionTopic $topic) => [
                    ...$topic->only('id', 'key', 'name', 'description', 'default_subscribed', 'property'),
                    'stored_opt_outs' => (int) $topic->opted_out,
                    'stored_opt_ins' => (int) $topic->opted_in,
                    'templates' => $context->workspace->templates()->where('subscription_topic_id', $topic->id)->count(),
                ])->values()->all(),
            'url' => $context->url('subscriptions.index'),
        ];
    }
}

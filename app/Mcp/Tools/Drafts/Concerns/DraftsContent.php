<?php

namespace TriggerEngage\Server\Mcp\Tools\Drafts\Concerns;

use Illuminate\Support\Facades\Validator;
use TriggerEngage\Server\Engine\EmailLayoutRenderer;
use TriggerEngage\Server\Engine\TemplateRenderer;
use TriggerEngage\Server\Http\Controllers\Concerns\EditsMessageContent;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Models\SubscriptionTopic;
use TriggerEngage\Server\Models\Template;

/**
 * Message content for the drafting tools, validated and normalized by the
 * same code as the template editor and the broadcast composer.
 */
trait DraftsContent
{
    use EditsMessageContent;

    protected EmailLayoutRenderer $layouts;

    protected TemplateRenderer $renderer;

    public function writes(): bool
    {
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    protected function contentProperties(bool $creating): array
    {
        return [
            ...($creating ? ['channel' => ['type' => 'string', 'enum' => ['email', 'sms', 'push']]] : []),
            'name' => ['type' => 'string', 'description' => 'What the team calls it in the dashboard.'],
            'subject' => ['type' => 'string', 'description' => 'Email subject (Liquid allowed). Push: the notification title.'],
            'body' => ['type' => 'string', 'description' => 'The message in Liquid, e.g. "Hi {{ person.first_name }}". Email bodies are HTML inside the workspace layout; SMS and push are plain text.'],
            'preheader' => ['type' => 'string', 'description' => 'Email only: the preview line inboxes show after the subject.'],
            'layout' => ['type' => 'string', 'enum' => ['mytherapist', 'plain'], 'description' => 'Email only: the branded layout (default) or plain.'],
            'from_name' => ['type' => 'string'],
            'from_address' => ['type' => 'string', 'description' => 'Email only. Leave out to use the channel\'s sender.'],
            'settings' => ['type' => 'object', 'description' => 'Delivery settings: email reply_to / reply_to_name; push url (deep link, Liquid allowed) and data.'],
            'subscription_topic' => ['type' => 'string', 'description' => 'Key of the subscription topic people can opt out of (see list_subscription_topics). Empty string removes it.'],
        ];
    }

    /** @return array<string, mixed> */
    protected function contentRulesFor(bool $creating): array
    {
        $rules = $this->contentRules();

        if (! $creating) {
            // Updates name only what changes; everything else keeps its value.
            unset($rules['channel']);
            foreach (['name', 'body'] as $key) {
                $rules[$key] = array_values(array_diff($rules[$key], ['required']));
                array_unshift($rules[$key], 'sometimes');
            }
        }

        return [...$rules, 'subscription_topic' => ['nullable', 'string', 'max:100']];
    }

    /**
     * Resolve a topic key to its id. Null keeps the current topic; an empty
     * string clears it.
     */
    protected function topicId(array $arguments, Context $context, ?int $current = null): ?int
    {
        if (! array_key_exists('subscription_topic', $arguments)) {
            return $current;
        }

        if (blank($arguments['subscription_topic'])) {
            return null;
        }

        $topic = SubscriptionTopic::query()
            ->where('workspace_id', $context->workspace->id)
            ->where('key', $arguments['subscription_topic'])
            ->first();

        if (! $topic) {
            throw new ToolError("No subscription topic with key \"{$arguments['subscription_topic']}\". See list_subscription_topics.");
        }

        return $topic->id;
    }

    /**
     * Check the merged content the way the editor does before saving.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed> the normalized fields to store
     */
    protected function checkedContent(array $content, Context $context): array
    {
        Validator::make($content, $this->contentRules())->validate();
        $this->validateLiquid($content);

        return $this->normalizedContent($content, $context->workspace);
    }

    /**
     * Variables that render empty in the sample preview context: a hint the
     * copy expects data the preview doesn't have, or a typo.
     *
     * @return array<int, string>
     */
    protected function previewWarnings(array $content, Context $context): array
    {
        $template = new Template($this->normalizedContent($content, $context->workspace));
        $template->setRelation('workspace', $context->workspace);

        return array_values(array_unique($this->renderPreview($template)['warnings']));
    }
}

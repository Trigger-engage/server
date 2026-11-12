<?php

namespace TriggerEngage\Server\Mcp\Tools\Messages;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\Tools\Messages\Concerns\PresentsMessages;
use TriggerEngage\Server\Models\Message;

class SearchMessages extends Tool
{
    use PresentsMessages;

    /** Status filters; "problem" is failed or bounced, "pending" is queued or sending. */
    public const STATUSES = [
        'sent' => ['sent'],
        'delivered' => ['delivered'],
        'problem' => ['failed', 'bounced'],
        'failed' => ['failed'],
        'bounced' => ['bounced'],
        'pending' => ['queued', 'sending'],
    ];

    public function name(): string
    {
        return 'search_messages';
    }

    public function title(): string
    {
        return 'Search the messages log';
    }

    public function description(): string
    {
        return 'Every email, SMS and push Trigger Engage has sent or tried to send, newest first, filtered by person, '
            .'channel, status (sent, delivered, problem = failed or bounced, pending), the automation or broadcast that '
            .'sent it, template, age, or text in the address or subject. Each row says where it came from and what the '
            .'provider reported; counts per status cover the whole filtered set. get_message shows the content.';
    }

    public function properties(): array
    {
        return [
            ...$this->personProperties('Only messages to this person'),
            'channel' => ['type' => 'string', 'enum' => ['email', 'sms', 'push']],
            'status' => ['type' => 'string', 'enum' => array_keys(self::STATUSES)],
            'automation_id' => ['type' => 'integer'],
            'broadcast_id' => ['type' => 'integer'],
            'template_id' => ['type' => 'integer'],
            'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'description' => 'Only messages from the last N days.'],
            'query' => ['type' => 'string', 'description' => 'Text in the destination address, subject or person\'s external id.'],
            ...$this->paginationProperties(),
        ];
    }

    public function rules(): array
    {
        return [
            ...$this->personRules(required: false),
            'channel' => ['nullable', 'in:email,sms,push'],
            'status' => ['nullable', 'in:'.implode(',', array_keys(self::STATUSES))],
            'automation_id' => ['nullable', 'integer'],
            'broadcast_id' => ['nullable', 'integer'],
            'template_id' => ['nullable', 'integer'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'query' => ['nullable', 'string', 'max:191'],
            ...$this->paginationRules(),
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        $person = $this->hasPerson($arguments) ? $this->findPerson($arguments, $context) : null;

        $base = fn () => Message::query()
            ->where('workspace_id', $context->workspace->id)
            ->when($person, fn ($query) => $query->where('person_id', $person->id))
            ->when($arguments['channel'] ?? null, fn ($query, $channel) => $query->where('channel', $channel))
            ->when($arguments['template_id'] ?? null, fn ($query, $template) => $query->where('template_id', $template))
            ->when($arguments['automation_id'] ?? null, fn ($query, $automation) => $query->whereHas('runStep.run', fn ($run) => $run->where('automation_id', $automation)))
            ->when($arguments['broadcast_id'] ?? null, fn ($query, $broadcast) => $query->whereHas('broadcastRecipient', fn ($recipient) => $recipient->where('broadcast_id', $broadcast)))
            ->when($arguments['days'] ?? null, fn ($query, $days) => $query->where('created_at', '>=', now()->subDays($days)))
            ->when(filled($arguments['query'] ?? null), function ($query) use ($arguments) {
                $term = '%'.addcslashes((string) $arguments['query'], '%_\\').'%';
                $query->where(fn ($nested) => $nested
                    ->where('to_address', 'like', $term)
                    ->orWhere('subject', 'like', $term)
                    ->orWhereHas('person', fn ($people) => $people->where('external_id', 'like', $term)));
            });

        $counts = $base()->toBase()->reorder()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')
            ->map(fn ($total) => (int) $total);

        $query = $base()
            ->when($arguments['status'] ?? null, fn ($query, $status) => $query->whereIn('status', self::STATUSES[$status]))
            ->select(['id', 'person_id', 'run_step_id', 'template_id', 'channel', 'to_address', 'subject', 'status', 'error',
                'sent_at', 'delivered_at', 'opened_at', 'clicked_at', 'bounced_at', 'created_at'])
            ->with($this->sourceRelations())
            ->orderByDesc('id');

        return [
            'counts_by_status' => $counts->all(),
            ...$this->page($query, $arguments, fn (Message $message) => $this->summary($message, $context)),
            'url' => $context->url('messages.index'),
        ];
    }
}

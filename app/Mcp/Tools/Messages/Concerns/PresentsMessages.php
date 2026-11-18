<?php

namespace TriggerEngage\Server\Mcp\Tools\Messages\Concerns;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Models\Message;

trait PresentsMessages
{
    /** @return array<int|string, mixed> */
    protected function sourceRelations(): array
    {
        return [
            'person:id,external_id,anonymous_id,email,phone',
            'template:id,name',
            'runStep:id,automation_run_id,node_id,attempts',
            'runStep.run:id,automation_id',
            'runStep.run.automation:id,name',
            'broadcastRecipient:id,broadcast_id,message_id',
            'broadcastRecipient.broadcast:id,name',
        ];
    }

    /** @return array<string, mixed> */
    protected function summary(Message $message, Context $context): array
    {
        $run = $message->runStep?->run;
        $broadcast = $message->broadcastRecipient?->broadcast;

        return [
            'id' => $message->id,
            'channel' => $message->channel,
            'to' => $context->redactor->address($message->channel, $message->to_address),
            'subject' => $context->redactor->text($message->subject),
            'status' => $message->status,
            'error' => $context->redactor->text($message->error),
            'person' => $context->person($message->person),
            'template' => $message->template?->only('id', 'name'),
            'source' => match (true) {
                $run !== null => [
                    'type' => 'automation',
                    'automation' => $run->automation?->only('id', 'name'),
                    'run_id' => $run->id,
                    'node_id' => $message->runStep->node_id,
                    'attempts' => $message->runStep->attempts,
                ],
                $broadcast !== null => ['type' => 'broadcast', 'broadcast' => $broadcast->only('id', 'name')],
                default => null,
            },
            'created_at' => $context->time($message->created_at),
            'sent_at' => $context->time($message->sent_at),
            'delivered_at' => $context->time($message->delivered_at),
            'opened_at' => $context->time($message->opened_at),
            'clicked_at' => $context->time($message->clicked_at),
            'bounced_at' => $context->time($message->bounced_at),
        ];
    }
}

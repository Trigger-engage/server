<?php

namespace TriggerEngage\Server\Mcp\Support;

use Illuminate\Database\Eloquent\Model;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Models\AuditEvent;

/**
 * Writes the audit trail for changes made through MCP: which caller, what
 * they did, to which record, in a sentence.
 */
class Audit
{
    public static function record(Context $context, string $action, Model $subject, string $summary, array $details = []): AuditEvent
    {
        return AuditEvent::query()->create([
            'workspace_id' => $context->workspace->id,
            'actor' => $context->actor,
            'action' => $action,
            'subject_type' => strtolower(class_basename($subject)),
            'subject_id' => $subject->getKey(),
            'summary' => mb_strimwidth($summary, 0, 500, '…'),
            'details' => $details ?: null,
            'created_at' => now(),
        ]);
    }
}

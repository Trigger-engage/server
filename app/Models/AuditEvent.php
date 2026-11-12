<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change an MCP client made: who (`actor`, e.g. "admin:12" or
 * "api-key:agents"), what (`action`, e.g. "template.created"), to which
 * record, in a sentence a person can read.
 */
class AuditEvent extends Model
{
    protected $table = 'engage_audit_events';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected $casts = [
        'details' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}

<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunEventWait extends Model
{
    public const STATUS_WAITING = 'waiting';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_TIMED_OUT = 'timed_out';

    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = [];

    protected $casts = [
        'match_rules' => 'array',
        'expires_at' => 'datetime',
        'matched_at' => 'datetime',
        'timed_out_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function matchedOccurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'matched_occurrence_id');
    }
}

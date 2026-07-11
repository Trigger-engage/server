<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunGoalSubscription extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REACHED = 'reached';

    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'match_rules' => 'array',
            'reached_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

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

    public function reachedOccurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'reached_occurrence_id');
    }
}

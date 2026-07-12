<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_WAITING_EVENT = 'waiting_event';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    /** @return array<int, string> */
    public static function activeStatuses(): array
    {
        return [self::STATUS_RUNNING, self::STATUS_WAITING, self::STATUS_WAITING_EVENT];
    }

    protected static function booted(): void
    {
        static::updated(function (AutomationRun $run): void {
            if ($run->wasChanged('status') && ! in_array($run->status, self::activeStatuses(), true)) {
                $run->goalSubscriptions()
                    ->where('status', RunGoalSubscription::STATUS_ACTIVE)
                    ->update([
                        'status' => RunGoalSubscription::STATUS_CANCELLED,
                        'cancelled_at' => now(),
                    ]);
            }
        });
    }

    protected $casts = [
        'wake_at' => 'datetime',
        'context' => 'array',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationVersion::class, 'automation_version_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'event_occurrence_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(RunStep::class);
    }

    public function eventWaits(): HasMany
    {
        return $this->hasMany(RunEventWait::class);
    }

    public function goalSubscriptions(): HasMany
    {
        return $this->hasMany(RunGoalSubscription::class);
    }
}

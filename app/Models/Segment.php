<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Segment extends Model
{
    public const TYPE_MANUAL = 'manual';

    public const TYPE_EVENT = 'event';

    public const TYPE_RULE = 'rule';

    protected $guarded = [];

    protected $casts = [
        'rules' => 'array',
        'recomputed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (Segment $segment) => $segment->public_id ??= 'seg_'.strtolower((string) Str::ulid()));
    }

    public function isRuleBased(): bool
    {
        return $this->type === self::TYPE_RULE;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'segment_person')->withPivot(['source', 'event_occurrence_id', 'added_at']);
    }

    public function broadcasts(): HasMany
    {
        return $this->hasMany(Broadcast::class);
    }
}

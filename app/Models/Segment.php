<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Segment extends Model
{
    public const TYPE_ALL = 'all';

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
        static::created(function (Segment $segment): void {
            if ($segment->isAllPeople()) {
                $segment->syncAllPeople();
            }
        });
    }

    public static function ensureAllPeople(Workspace $workspace): self
    {
        $existing = $workspace->segments()->where('type', self::TYPE_ALL)->first();
        if ($existing) {
            $existing->syncAllPeople();

            return $existing;
        }

        $name = $workspace->segments()->where('name', 'All people')->exists()
            ? 'All people (default)'
            : 'All people';

        return $workspace->segments()->create([
            'name' => $name,
            'type' => self::TYPE_ALL,
            'description' => 'Every profile in this workspace. Membership updates automatically.',
        ]);
    }

    public static function includePerson(Person $person): void
    {
        $segmentId = self::query()
            ->where('workspace_id', $person->workspace_id)
            ->where('type', self::TYPE_ALL)
            ->value('id');

        if ($segmentId) {
            DB::table('segment_person')->insertOrIgnore([
                'segment_id' => $segmentId,
                'person_id' => $person->id,
                'source' => 'system',
                'added_at' => now(),
            ]);
        }
    }

    public function syncAllPeople(): int
    {
        if (! $this->isAllPeople()) {
            return 0;
        }

        $inserted = 0;
        Person::query()
            ->where('workspace_id', $this->workspace_id)
            ->select('id')
            ->chunkById(500, function ($people) use (&$inserted): void {
                $now = now();
                $inserted += DB::table('segment_person')->insertOrIgnore(
                    $people->map(fn (Person $person) => [
                        'segment_id' => $this->id,
                        'person_id' => $person->id,
                        'source' => 'system',
                        'added_at' => $now,
                    ])->all()
                );
            });

        return $inserted;
    }

    public function isRuleBased(): bool
    {
        return $this->type === self::TYPE_RULE;
    }

    public function isAllPeople(): bool
    {
        return $this->type === self::TYPE_ALL;
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

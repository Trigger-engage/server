<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    protected $table = 'people';

    protected $guarded = [];

    protected $casts = [
        'attributes' => 'array',
        'unsubscribed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(fn (Person $person) => Segment::includePerson($person));
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function suppressions(): HasMany
    {
        return $this->hasMany(Suppression::class);
    }

    public function segments(): BelongsToMany
    {
        return $this->belongsToMany(Segment::class, 'segment_person')->withPivot(['source', 'event_occurrence_id', 'added_at']);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(EventOccurrence::class);
    }

    public function automationRuns(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function properties(): array
    {
        return $this->getAttribute('attributes') ?? [];
    }

    public function isSuppressed(string $channel): bool
    {
        if ($this->unsubscribed_at) {
            return true;
        }

        return $this->suppressions()
            ->whereIn('channel', [$channel, 'all'])
            ->exists();
    }

    /**
     * Template/condition context. Free-form attributes are flattened to the
     * top level ({{ person.first_name }}) and also nested under "attributes";
     * identity columns win on name collisions.
     *
     * @return array<string, mixed>
     */
    public function toContext(): array
    {
        // The column is named "attributes", which collides with Eloquent's
        // internal property — it must be read via getAttribute().
        $attributes = $this->getAttribute('attributes') ?? [];

        return array_merge($attributes, [
            'external_id' => $this->external_id,
            'email' => $this->email,
            'phone' => $this->phone,
            'attributes' => $attributes,
            'properties' => $attributes,
        ]);
    }
}

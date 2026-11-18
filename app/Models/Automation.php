<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Automation extends Model
{
    public const REENTRY_EVERY_TIME = 'every_time';

    public const REENTRY_ONE_ACTIVE = 'one_active_run_per_person';

    public const REENTRY_ONCE_EVER = 'once_ever_per_person';

    protected $guarded = [];

    protected $casts = [
        'draft_graph' => 'array',
        'draft_saved_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function triggerEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'trigger_event_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AutomationVersion::class);
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(AutomationVersion::class, 'active_version_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /**
     * Publish a new immutable version of the graph and activate it.
     * In-flight runs keep executing the version they started on.
     */
    public function hasDraft(): bool
    {
        return $this->draft_graph !== null;
    }

    /** The graph the editor should open: the unpublished draft if there is one. */
    public function editorGraph(): array
    {
        return $this->draft_graph ?? $this->activeVersion?->graph ?? [];
    }

    public function publish(array $graph): AutomationVersion
    {
        $version = $this->versions()->create([
            'graph' => $graph,
            'published_at' => now(),
        ]);

        // Publishing makes whatever was edited the live journey, so an
        // unpublished draft beside it no longer means anything.
        $this->update([
            'active_version_id' => $version->id,
            'status' => 'active',
            'draft_graph' => null,
            'draft_saved_at' => null,
            'draft_saved_by' => null,
        ]);

        return $version;
    }
}

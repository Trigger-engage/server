<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Broadcast extends Model
{
    protected $guarded = [];

    protected $casts = ['settings' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];

    /**
     * A broadcast can only be composed while it is still a draft; once it starts
     * sending, its content is frozen.
     */
    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * The effective message content for this broadcast: its own snapshot fields,
     * falling back to the linked template for anything not yet composed. Channels
     * render from this, so per-broadcast edits ship without mutating the template.
     */
    public function messageTemplate(): Template
    {
        $base = $this->template;

        $template = new Template;
        $template->id = $this->template_id;
        $template->workspace_id = $this->workspace_id;
        $template->channel = $this->channel;
        $template->name = $base?->name ?? $this->name;
        $template->subject = $this->subject ?? $base?->subject;
        $template->body = $this->body ?? $base?->body;
        $template->layout = $this->layout ?? $base?->layout ?? 'mytherapist';
        $template->preheader = $this->preheader ?? $base?->preheader;
        $template->settings = $this->settings ?? $base?->settings;
        $template->from_name = $this->from_name ?: $base?->from_name;
        $template->from_address = $this->from_address ?: $base?->from_address;

        return $template;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function channelConfiguration(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'channel_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }
}

<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Message extends Model
{
    protected $guarded = [];

    protected $casts = [
        'pending_receipts' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'bounced_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** Set when an automation step sent this message. */
    public function runStep(): BelongsTo
    {
        return $this->belongsTo(RunStep::class);
    }

    /** Set when a broadcast sent this message. */
    public function broadcastRecipient(): HasOne
    {
        return $this->hasOne(BroadcastRecipient::class);
    }

    public function subscriptionTopic(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTopic::class);
    }
}

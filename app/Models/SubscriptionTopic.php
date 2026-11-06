<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of message people can opt out of on its own ("Care reminders",
 * "Product news") without unsubscribing from a whole channel. Templates and
 * broadcasts name the topic they belong to; a message with no topic is only
 * gated by channel-level unsubscribes.
 */
class SubscriptionTopic extends Model
{
    protected $table = 'engage_subscription_topics';

    protected $guarded = [];

    protected $casts = [
        'default_subscribed' => 'boolean',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(SubscriptionPreference::class);
    }
}

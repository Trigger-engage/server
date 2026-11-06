<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPreference extends Model
{
    protected $table = 'engage_subscription_preferences';

    protected $guarded = [];

    protected $casts = [
        'subscribed' => 'boolean',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(SubscriptionTopic::class, 'subscription_topic_id');
    }
}

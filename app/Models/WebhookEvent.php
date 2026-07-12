<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'processed_at' => 'datetime'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}

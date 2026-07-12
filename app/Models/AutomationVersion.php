<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationVersion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'graph' => 'array',
        'published_at' => 'datetime',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }
}

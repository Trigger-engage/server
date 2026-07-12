<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RunStep extends Model
{
    protected $guarded = [];

    protected $casts = [
        'output' => 'array',
        'executed_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    public function message(): HasOne
    {
        return $this->hasOne(Message::class);
    }
}

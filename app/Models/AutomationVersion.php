<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'graph' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }
}

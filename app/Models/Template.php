<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends Model
{
    protected $guarded = [];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}

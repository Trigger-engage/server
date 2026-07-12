<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Workspace extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            $workspace->public_id ??= 'ws_'.strtolower((string) Str::ulid());
        });
        static::created(fn (Workspace $workspace) => Segment::ensureAllPeople($workspace));
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function automations(): HasMany
    {
        return $this->hasMany(Automation::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class);
    }

    public function broadcasts(): HasMany
    {
        return $this->hasMany(Broadcast::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}

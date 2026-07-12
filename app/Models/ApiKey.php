<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    protected $guarded = [];

    protected $casts = ['last_used_at' => 'datetime'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Create a key for a workspace and return [model, plaintext]. The
     * plaintext is shown once and only its sha256 hash is stored.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Workspace $workspace, string $name = 'default'): array
    {
        $plaintext = 'te_'.Str::random(40);

        $key = $workspace->apiKeys()->create([
            'name' => $name,
            'key_hash' => hash('sha256', $plaintext),
            'prefix' => substr($plaintext, 0, 8),
        ]);

        return [$key, $plaintext];
    }
}

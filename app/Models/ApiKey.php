<?php

namespace TriggerEngage\Server\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    /**
     * Explicit: hosts that embed this package often already own an `api_keys`
     * table (Laravel's convention would silently alias theirs).
     */
    protected $table = 'engage_api_keys';

    protected $guarded = [];

    public const KIND_SERVER = 'server';

    public const KIND_CLIENT = 'client';

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'identity_secret' => 'encrypted',
        'allowed_origins' => 'array',
        'allowed_events' => 'array',
    ];

    protected $hidden = ['key_hash', 'identity_secret'];

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

    /**
     * Create a publishable client key and return [model, identity secret]. The
     * key itself (pk_…) is safe to ship in an app; the identity secret stays on
     * the host's server, which uses it to sign person ids for the SDK.
     *
     * @param  array<int, string>|null  $allowedOrigins
     * @param  array<int, string>|null  $allowedEvents
     * @return array{0: self, 1: string}
     */
    public static function issueClient(Workspace $workspace, string $name, ?array $allowedOrigins = null, ?array $allowedEvents = null): array
    {
        $publicKey = 'pk_'.Str::random(40);
        $secret = 'tes_'.Str::random(48);

        $key = $workspace->apiKeys()->create([
            'name' => $name,
            'kind' => self::KIND_CLIENT,
            'key_hash' => hash('sha256', $publicKey),
            'prefix' => substr($publicKey, 0, 8),
            'public_key' => $publicKey,
            'identity_secret' => $secret,
            'allowed_origins' => $allowedOrigins ?: null,
            'allowed_events' => $allowedEvents ?: null,
        ]);

        return [$key, $secret];
    }

    /** The token an app sends with a person id to prove its server vouched for it. */
    public function identityTokenFor(string $externalId): string
    {
        return hash_hmac('sha256', $externalId, (string) $this->identity_secret);
    }

    public function verifiesIdentity(string $externalId, string $token): bool
    {
        return $this->kind === self::KIND_CLIENT
            && filled($this->identity_secret)
            && hash_equals($this->identityTokenFor($externalId), $token);
    }
}

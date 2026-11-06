<?php

namespace TriggerEngage\Server\Support;

use TriggerEngage\Server\Models\ApiKey;
use TriggerEngage\Server\Models\Workspace;

/**
 * For a host app running the package embedded: the identity token to hand an
 * app alongside the person id, e.g. in the logged-in user's API resource.
 *
 *   'trigger_engage_identity' => ClientIdentity::token('user-'.$user->id),
 *
 * A host that runs the server separately computes the same thing itself:
 * hash_hmac('sha256', $personId, $identitySecret).
 */
class ClientIdentity
{
    /**
     * Signed with the newest active client key of the embedded workspace, or
     * of the key whose public key is given. Null when there is no such key.
     */
    public static function token(string $externalId, ?string $publicKey = null): ?string
    {
        $query = ApiKey::query()->where('kind', ApiKey::KIND_CLIENT)->whereNull('revoked_at');

        if ($publicKey) {
            $query->where('key_hash', hash('sha256', $publicKey));
        } else {
            $workspace = Workspace::query()->where('public_id', config('trigger-engage-server.workspace_id'))->first();
            $query->where('workspace_id', $workspace?->id)->latest('id');
        }

        return $query->first()?->identityTokenFor($externalId);
    }
}

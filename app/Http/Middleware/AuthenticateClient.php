<?php

namespace TriggerEngage\Server\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TriggerEngage\Server\Models\ApiKey;
use TriggerEngage\Server\Models\Person;

/**
 * Authenticates the client endpoints that apps call directly (browser and
 * React Native), and answers their CORS preflights.
 *
 *   X-TE-Key       the publishable client key (pk_…)
 *   X-TE-Person    the person's external id, when the app knows it
 *   X-TE-Identity  HMAC-SHA256(person id, identity secret), computed by the
 *                  host's server; without it a client can only act as an
 *                  anonymous device
 *   X-TE-Platform  ios | android | web, recorded with last-seen
 */
class AuthenticateClient
{
    public const HEADERS = 'Content-Type, X-TE-Key, X-TE-Person, X-TE-Identity, X-TE-Platform';

    /** How stale last_seen_at may get before a request writes it again. */
    public const LAST_SEEN_EVERY_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->cors($request, response()->noContent());
        }

        $key = ApiKey::query()
            ->where('kind', ApiKey::KIND_CLIENT)
            ->whereNull('revoked_at')
            ->where('key_hash', hash('sha256', (string) $request->header('X-TE-Key')))
            ->with('workspace')
            ->first();

        if (! $key) {
            return $this->deny($request, 401, 'Unknown client key. Send the publishable key (pk_…) in the X-TE-Key header.');
        }

        $origin = $request->header('Origin');
        if ($origin && $key->allowed_origins && ! in_array(rtrim($origin, '/'), array_map(fn ($allowed) => rtrim($allowed, '/'), $key->allowed_origins), true)) {
            return $this->deny($request, 403, 'This origin is not allowed to use this client key.');
        }

        $externalId = $request->header('X-TE-Person');
        if (filled($externalId) && ! $key->verifiesIdentity($externalId, (string) $request->header('X-TE-Identity'))) {
            // Never fall back to anonymous: an app that sends a person id
            // expects the event on that person, and a bad token is a bug.
            return $this->deny($request, 401, 'The identity token does not match this person. Compute it on your server with the client key\'s identity secret.');
        }

        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('workspace', $key->workspace);
        $request->attributes->set('client_key', $key);
        $request->attributes->set('client_person', filled($externalId) ? $externalId : null);

        $response = $next($request);

        if (filled($externalId) && $response->isSuccessful()) {
            $this->touchLastSeen($key, $externalId, $request->header('X-TE-Platform'));
        }

        return $this->cors($request, $response);
    }

    /**
     * Stamp last_seen_at (and the platform) on the person, at most every few
     * minutes, so "inactive for 14 days" segments have something to read.
     */
    protected function touchLastSeen(ApiKey $key, string $externalId, ?string $platform): void
    {
        $person = Person::query()->where('workspace_id', $key->workspace_id)->where('external_id', $externalId)->first();
        if (! $person) {
            return;
        }

        $properties = $person->properties();
        $lastSeen = isset($properties['last_seen_at']) ? strtotime((string) $properties['last_seen_at']) : false;
        $platform = in_array($platform, ['ios', 'android', 'web'], true) ? $platform : null;

        if ($lastSeen && $lastSeen > now()->subMinutes(self::LAST_SEEN_EVERY_MINUTES)->getTimestamp()
            && (! $platform || ($properties['last_seen_platform'] ?? null) === $platform)) {
            return;
        }

        $person->forceFill(['attributes' => array_filter([
            ...$properties,
            'last_seen_at' => now()->toIso8601ZuluString(),
            'last_seen_platform' => $platform ?? ($properties['last_seen_platform'] ?? null),
        ], fn ($value) => $value !== null)])->saveQuietly();
    }

    protected function deny(Request $request, int $status, string $message): Response
    {
        return $this->cors($request, response()->json(['message' => $message], $status));
    }

    protected function cors(Request $request, Response $response): Response
    {
        if ($origin = $request->header('Origin')) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin', false);
            $response->headers->set('Access-Control-Allow-Methods', 'POST, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', self::HEADERS);
            $response->headers->set('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}

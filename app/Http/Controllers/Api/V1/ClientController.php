<?php

namespace TriggerEngage\Server\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use TriggerEngage\Server\Engine\SegmentManager;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\ApiKey;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Services\Ingest;

/**
 * What an app may do with a publishable client key: record events, set its
 * own person's properties, and register that person's push token. A verified
 * person (see AuthenticateClient) acts on their own profile only; without
 * one, events land on an anonymous device profile that is merged in when the
 * app identifies.
 */
class ClientController extends Controller
{
    /** Push tokens kept per person; the oldest go first. */
    public const MAX_PUSH_TOKENS = 10;

    /** Profile fields an app may not set on itself. */
    public const PROTECTED_PROPERTIES = ['email', 'phone', 'expo_push_tokens', 'expo_push_token', 'last_seen_at', 'last_seen_platform'];

    public function __construct(protected Ingest $ingest) {}

    public function events(Request $request): JsonResponse
    {
        $person = $request->attributes->get('client_person');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'data' => ['nullable', 'array'],
            'anonymous_id' => [$person ? 'nullable' : 'required', 'string', 'max:150'],
            'occurred_at' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ], [
            'anonymous_id.required' => 'Send an anonymous_id, or identify the person with X-TE-Person and X-TE-Identity.',
        ]);

        /** @var ApiKey $key */
        $key = $request->attributes->get('client_key');
        if ($key->allowed_events && ! in_array($data['name'], $key->allowed_events, true)) {
            throw ValidationException::withMessages(['name' => "This client key may not send “{$data['name']}”."]);
        }

        $occurrence = $this->ingest->track($request->attributes->get('workspace'), array_filter([
            'name' => $data['name'],
            'person_id' => $person,
            // An identified request only carries an anonymous id from identify();
            // here it would merge whichever device profile the app names.
            'anonymous_id' => $person ? null : $data['anonymous_id'],
            'data' => $data['data'] ?? [],
            'occurred_at' => $data['occurred_at'] ?? null,
            // Namespaced so a client can never claim a key a server call uses.
            'idempotency_key' => isset($data['idempotency_key']) ? 'client:'.$key->id.':'.$data['idempotency_key'] : null,
        ], fn ($value) => $value !== null));

        return response()->json(['accepted' => true, 'duplicate' => $occurrence === null], 202);
    }

    /**
     * Identify the device as the verified person: merges the anonymous profile
     * the app has been sending as, and sets the app's own properties.
     */
    public function identify(Request $request): JsonResponse
    {
        $externalId = $this->requirePerson($request);
        $data = $request->validate([
            'anonymous_id' => ['nullable', 'string', 'max:150'],
            'properties' => ['nullable', 'array', 'max:100'],
        ]);

        $properties = array_diff_key($data['properties'] ?? [], array_flip(self::PROTECTED_PROPERTIES));
        $person = $this->ingest->identify($request->attributes->get('workspace'), $externalId, array_filter([
            'properties' => $properties,
            'anonymous_id' => $data['anonymous_id'] ?? null,
        ], fn ($value) => $value !== null && $value !== []));
        app(SegmentManager::class)->syncPersonRuleSegments($person);

        return response()->json(['identified' => true]);
    }

    public function registerPushToken(Request $request): JsonResponse
    {
        $person = $this->person($request);
        $token = $this->validatedToken($request);
        $tokens = array_values(array_filter((array) ($person->properties()['expo_push_tokens'] ?? []), fn ($existing) => $existing !== $token));
        $tokens = array_slice([...$tokens, $token], -self::MAX_PUSH_TOKENS);

        $this->saveTokens($person, $tokens);

        return response()->json(['registered' => true, 'tokens' => count($tokens)]);
    }

    /** On log-out, so this device stops getting the person's pushes. */
    public function removePushToken(Request $request): JsonResponse
    {
        $person = $this->person($request);
        $token = $this->validatedToken($request);
        $tokens = array_values(array_filter((array) ($person->properties()['expo_push_tokens'] ?? []), fn ($existing) => $existing !== $token));

        $this->saveTokens($person, $tokens);

        return response()->json(['removed' => true, 'tokens' => count($tokens)]);
    }

    protected function validatedToken(Request $request): string
    {
        return $request->validate([
            'token' => ['required', 'string', 'max:255', 'regex:/^Expo(nent)?PushToken\[[^\]]+\]$/'],
        ], [
            'token.regex' => 'Send an Expo push token, like ExponentPushToken[…].',
        ])['token'];
    }

    /** @param  array<int, string>  $tokens */
    protected function saveTokens(Person $person, array $tokens): void
    {
        $properties = $person->properties();
        $properties['expo_push_tokens'] = $tokens;
        $person->update(['attributes' => $properties]);
        app(SegmentManager::class)->syncPersonRuleSegments($person);
    }

    protected function requirePerson(Request $request): string
    {
        $externalId = $request->attributes->get('client_person');
        abort_unless(filled($externalId), 401, 'Identify the person with X-TE-Person and X-TE-Identity first.');

        return $externalId;
    }

    protected function person(Request $request): Person
    {
        $externalId = $this->requirePerson($request);

        return $this->ingest->identify($request->attributes->get('workspace'), $externalId, []);
    }
}

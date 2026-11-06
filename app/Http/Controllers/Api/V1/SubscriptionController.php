<?php

namespace TriggerEngage\Server\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\SubscriptionTopic;
use TriggerEngage\Server\Services\Subscriptions;

/**
 * A person's subscriptions over the API, so an app's own settings screen can
 * read and write the same preferences the unsubscribe page and preference
 * centre use. Topics are addressed by key.
 *
 *   PUT /people/{id}/subscriptions
 *   {"topics": {"care_reminders": false}, "channels": {"sms": false}}
 */
class SubscriptionController extends Controller
{
    public function __construct(protected Subscriptions $subscriptions) {}

    public function show(Request $request, string $externalId): JsonResponse
    {
        return response()->json($this->resource($this->resolve($request, $externalId)));
    }

    public function update(Request $request, string $externalId): JsonResponse
    {
        $person = $this->resolve($request, $externalId);
        $data = $request->validate([
            'topics' => ['nullable', 'array'],
            'topics.*' => ['boolean'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['boolean'],
        ]);

        $topics = SubscriptionTopic::query()->where('workspace_id', $person->workspace_id)
            ->whereIn('key', array_keys($data['topics'] ?? []))->get()->keyBy('key');
        $unknownTopics = array_diff(array_keys($data['topics'] ?? []), $topics->keys()->all());
        $unknownChannels = array_diff(array_keys($data['channels'] ?? []), Subscriptions::CHANNELS);
        if ($unknownTopics || $unknownChannels) {
            throw ValidationException::withMessages(array_filter([
                'topics' => $unknownTopics ? 'Unknown topic: '.implode(', ', $unknownTopics).'.' : null,
                'channels' => $unknownChannels ? 'Unknown channel: '.implode(', ', $unknownChannels).'. Use email, sms or push.' : null,
            ]));
        }

        foreach ($data['topics'] ?? [] as $key => $subscribed) {
            $this->subscriptions->setTopic($person, $topics[$key], (bool) $subscribed, 'api');
        }
        foreach ($data['channels'] ?? [] as $channel => $subscribed) {
            $this->subscriptions->setChannel($person, $channel, (bool) $subscribed, 'api');
        }

        return response()->json($this->resource($person->refresh()));
    }

    /** @return array<string, mixed> */
    protected function resource(Person $person): array
    {
        $state = $this->subscriptions->state($person);

        return [
            'external_id' => $person->external_id,
            'channels' => collect($state['channels'])->map(fn (array $channel) => [
                'subscribed' => $channel['subscribed'],
                'reason' => $channel['reason'],
            ])->all(),
            'topics' => collect($state['topics'])->mapWithKeys(fn (array $topic) => [
                $topic['key'] => ['subscribed' => $topic['subscribed'], 'name' => $topic['name']],
            ])->all(),
        ];
    }

    protected function resolve(Request $request, string $externalId): Person
    {
        return Person::query()
            ->where('workspace_id', $request->attributes->get('workspace')->id)
            ->where('external_id', $externalId)
            ->firstOrFail();
    }
}

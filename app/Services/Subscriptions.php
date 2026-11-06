<?php

namespace TriggerEngage\Server\Services;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Facades\DB;
use TriggerEngage\Server\Engine\SegmentManager;
use TriggerEngage\Server\Events\SubscriptionChanged;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\SubscriptionPreference;
use TriggerEngage\Server\Models\SubscriptionTopic;

/**
 * The one place that decides whether a person wants a message, and the one
 * place that changes it. Two layers: a channel-level unsubscribe (a
 * `suppressions` row for the channel or 'all', or the legacy whole-person
 * `people.unsubscribed_at`) stops every message on that channel; a topic
 * opt-out stops only messages whose template or broadcast names the topic.
 */
class Subscriptions
{
    public const CHANNELS = ['email', 'sms', 'push'];

    /**
     * Suppressions a recipient cannot lift from a public page: the address
     * bounced or its owner reported spam. Only someone in the dashboard can.
     */
    public const PROTECTED_REASONS = ['bounce', 'complaint'];

    public function __construct(protected SegmentManager $segments) {}

    public function isSubscribed(Person $person, SubscriptionTopic $topic): bool
    {
        return $this->topicState($person, $topic)['subscribed'];
    }

    /** True when the message may go out as far as its topic is concerned. */
    public function allowsTopic(Person $person, ?int $topicId): bool
    {
        if (! $topicId) {
            return true;
        }

        $topic = SubscriptionTopic::query()->where('workspace_id', $person->workspace_id)->find($topicId);

        return ! $topic || $this->isSubscribed($person, $topic);
    }

    public function setTopic(Person $person, SubscriptionTopic $topic, bool $subscribed, string $source): void
    {
        abort_unless($topic->workspace_id === $person->workspace_id, 404);
        $was = $this->isSubscribed($person, $topic);

        if ($topic->property) {
            $person->update(['attributes' => [...$person->properties(), $topic->property => $subscribed]]);
            $this->segments->syncPersonRuleSegments($person);
        } else {
            SubscriptionPreference::query()->updateOrCreate(
                ['person_id' => $person->id, 'subscription_topic_id' => $topic->id],
                ['subscribed' => $subscribed, 'source' => $source],
            );
        }

        if ($was !== $subscribed) {
            SubscriptionChanged::dispatch($person, 'topic', $topic->key, $subscribed, $source, $topic->property);
        }
    }

    public function setChannel(Person $person, string $channel, bool $subscribed, string $source): void
    {
        abort_unless(in_array($channel, self::CHANNELS, true), 422);
        $was = ! $person->isSuppressed($channel);

        DB::transaction(function () use ($person, $channel, $subscribed, $source): void {
            if (! $subscribed) {
                $person->suppressions()->updateOrCreate(
                    ['workspace_id' => $person->workspace_id, 'channel' => $channel],
                    ['reason' => $source === 'dashboard' ? 'manual' : 'unsubscribe'],
                );

                return;
            }

            $liftable = fn (?string $reason): bool => $source === 'dashboard' || ! in_array($reason, self::PROTECTED_REASONS, true);

            // An "everything" unsubscribe becomes one row per OTHER channel, so
            // coming back to email leaves SMS and push exactly as they were.
            $all = $person->suppressions()->where('channel', 'all')->first();
            if ($person->unsubscribed_at || ($all && $liftable($all->reason))) {
                foreach (array_diff(self::CHANNELS, [$channel]) as $other) {
                    $person->suppressions()->firstOrCreate(
                        ['workspace_id' => $person->workspace_id, 'channel' => $other],
                        ['reason' => $all?->reason ?? 'unsubscribe'],
                    );
                }
                $all?->delete();
                $person->update(['unsubscribed_at' => null]);
            }

            $person->suppressions()->where('channel', $channel)->get()
                ->filter(fn ($row) => $liftable($row->reason))
                ->each->delete();
        });

        $now = ! $person->refresh()->isSuppressed($channel);
        if ($was !== $now) {
            SubscriptionChanged::dispatch($person, 'channel', $channel, $now, $source);
        }
    }

    /**
     * Everything a preference screen needs: each channel (subscribed, why
     * not, since when, whether the person can be reached on it at all) and
     * each topic in the workspace with what decided it.
     *
     * @return array{channels: array<string, array<string, mixed>>, topics: array<int, array<string, mixed>>}
     */
    public function state(Person $person): array
    {
        $suppressions = $person->suppressions()->get();
        $properties = $person->properties();
        $reachable = [
            'email' => filled($person->email),
            'sms' => filled($person->phone),
            'push' => filled($person->external_id) || ! empty($properties['expo_push_tokens'] ?? null) || filled($properties['expo_push_token'] ?? null),
        ];

        $channels = [];
        foreach (self::CHANNELS as $channel) {
            $row = $suppressions->firstWhere('channel', $channel) ?? $suppressions->firstWhere('channel', 'all');
            $channels[$channel] = [
                'subscribed' => ! $row && ! $person->unsubscribed_at,
                'reason' => $row?->reason ?? ($person->unsubscribed_at ? 'unsubscribe' : null),
                'since' => $row?->updated_at ?? $person->unsubscribed_at,
                'reachable' => $reachable[$channel],
            ];
        }

        $preferences = SubscriptionPreference::query()->where('person_id', $person->id)->get()->keyBy('subscription_topic_id');
        $topics = SubscriptionTopic::query()->where('workspace_id', $person->workspace_id)->orderBy('name')->get()
            ->map(fn (SubscriptionTopic $topic) => [
                ...$topic->only('id', 'key', 'name', 'description', 'property', 'default_subscribed'),
                ...$this->topicState($person, $topic, $preferences->get($topic->id)),
            ])->values()->all();

        return ['channels' => $channels, 'topics' => $topics];
    }

    /**
     * Narrow a people query to those who opted out of the topic. Mirrors
     * topicState(): a property topic reads the true/false property, anything
     * else the stored preference, and both fall back to the topic default.
     */
    public function whereUnsubscribedFrom(BuilderContract $people, SubscriptionTopic $topic): BuilderContract
    {
        if ($topic->property) {
            $column = 'attributes->'.$topic->property;

            return $topic->default_subscribed
                ? $people->where($column, false)
                : $people->where(fn (BuilderContract $query) => $query->whereNull($column)->orWhere($column, '!=', true));
        }

        $preference = fn (bool $subscribed) => fn (BuilderContract $query) => $query->selectRaw('1')
            ->from('engage_subscription_preferences')
            ->whereColumn('engage_subscription_preferences.person_id', 'people.id')
            ->where('engage_subscription_preferences.subscription_topic_id', $topic->id)
            ->where('engage_subscription_preferences.subscribed', $subscribed);

        return $topic->default_subscribed
            ? $people->whereExists($preference(false))
            : $people->whereNotExists($preference(true));
    }

    /** @return array{subscribed: bool, decided_by: 'property'|'preference'|'default', source: ?string, since: mixed} */
    protected function topicState(Person $person, SubscriptionTopic $topic, ?SubscriptionPreference $preference = null): array
    {
        if ($topic->property) {
            $value = $person->properties()[$topic->property] ?? null;

            return is_bool($value)
                ? ['subscribed' => $value, 'decided_by' => 'property', 'source' => null, 'since' => null]
                : ['subscribed' => $topic->default_subscribed, 'decided_by' => 'default', 'source' => null, 'since' => null];
        }

        $preference ??= SubscriptionPreference::query()
            ->where('person_id', $person->id)
            ->where('subscription_topic_id', $topic->id)
            ->first();

        return $preference
            ? ['subscribed' => $preference->subscribed, 'decided_by' => 'preference', 'source' => $preference->source, 'since' => $preference->updated_at]
            : ['subscribed' => $topic->default_subscribed, 'decided_by' => 'default', 'source' => null, 'since' => null];
    }
}

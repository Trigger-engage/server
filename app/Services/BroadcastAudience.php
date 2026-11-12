<?php

namespace TriggerEngage\Server\Services;

use TriggerEngage\Server\Models\Broadcast;

/**
 * Who a draft broadcast would actually reach, counted before it is sent.
 * The composer and the MCP server both ask here.
 */
class BroadcastAudience
{
    /**
     * Pre-send audience estimate: of the segment's current members, how many
     * will actually receive this channel and how many will be skipped (missing
     * destination, unsubscribed/suppressed). Counts overlap-safe: `sendable`
     * is the distinct complement, not total minus the sum.
     *
     * @return array{total: int, sendable: int, no_destination: int, suppressed: int, unsubscribed_from_topic: int}
     */
    public function estimate(Broadcast $broadcast): array
    {
        // Destination predicates mirror SendBroadcastRecipient exactly — it
        // skips on blank($address), so '' must count as missing, and push
        // resolves attributes.onesignal_external_id ?? external_id (anonymous
        // profiles have a NULL external_id since the anonymous-identity
        // migration, so push CAN lack a destination).
        $hasDestination = match (true) {
            $broadcast->channel === 'email' => "(people.email is not null and people.email <> '')",
            $broadcast->channel === 'sms' => "(people.phone is not null and people.phone <> '')",
            // Expo pushes need tokens on the profile; the [0] probe works on
            // both MySQL and SQLite without dialect-specific length functions.
            $broadcast->channelConfiguration?->driver === 'expo' => "(json_extract(people.attributes, '$.expo_push_tokens[0]') is not null"
                ." or json_extract(people.attributes, '$.expo_push_token') is not null)",
            default => "((people.external_id is not null and people.external_id <> '')"
                ." or json_extract(people.attributes, '$.onesignal_external_id') is not null)",
        };

        $suppressed = '(people.unsubscribed_at is not null or exists ('
            .'select 1 from suppressions where suppressions.person_id = people.id'
            ." and suppressions.channel in (?, 'all')))";

        // One conditional-aggregation pass instead of four COUNTs — the index
        // page calls this for every draft. no_destination and suppressed may
        // overlap on the same person; only `sendable` is overlap-safe.
        $row = $broadcast->segment->people()->selectRaw(
            'count(*) as total'
            .", coalesce(sum(case when {$hasDestination} and not {$suppressed} then 1 else 0 end), 0) as sendable"
            .", coalesce(sum(case when not {$hasDestination} then 1 else 0 end), 0) as no_destination"
            .", coalesce(sum(case when {$suppressed} then 1 else 0 end), 0) as suppressed",
            [$broadcast->channel, $broadcast->channel]
        )->first();

        $preview = [
            'total' => (int) ($row->total ?? 0),
            'sendable' => (int) ($row->sendable ?? 0),
            'no_destination' => (int) ($row->no_destination ?? 0),
            'suppressed' => (int) ($row->suppressed ?? 0),
            'unsubscribed_from_topic' => 0,
        ];

        // A topic opt-out is checked by SendBroadcastRecipient after the channel
        // suppression; two more counts, only for a broadcast that has a topic.
        $topic = $broadcast->subscription_topic_id ? $broadcast->subscriptionTopic : null;
        if ($topic) {
            $subscriptions = app(Subscriptions::class);
            $preview['unsubscribed_from_topic'] = $subscriptions
                ->whereUnsubscribedFrom($broadcast->segment->people(), $topic)->count();
            $preview['sendable'] = $broadcast->segment->people()
                ->whereRaw($hasDestination)
                ->whereRaw("not {$suppressed}", [$broadcast->channel])
                ->whereNot(fn ($query) => $subscriptions->whereUnsubscribedFrom($query, $topic))
                ->count();
        }

        return $preview;
    }
}

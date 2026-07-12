# Anonymous → identified

Track people before you know who they are, then fold that pre-signup history into
the real person the moment they sign up. Nothing you captured while they were anonymous
is lost.

**Why this matters:** top-of-funnel attribution. The events a visitor fires before signup
(pricing views, feature clicks, a started-but-abandoned flow) stay attached to the person
once they identify, so your journeys and analytics see the whole story.

## The anonymous person

Send events and profile data against a device/session `anonymous_id` — a value you mint
client-side — instead of a `person_id`. This creates an **anonymous person**: a
[profile](../CONCEPTS.md#person) with a null `external_id` and the `anonymous_id` set.

Anonymous people are ordinary people in every way that matters. They accumulate attributes
and contact info, they can be added to segments, and they can even enter journeys. If an
anonymous person has no email yet, email sends in a journey are simply skipped gracefully —
the run continues.

## Tracking before signup

`POST /api/v1/events` accepts an `anonymous_id` **instead of** a `person_id`. Exactly one of
the two is required.

```bash
curl --user "$WS:$KEY" -H 'Content-Type: application/json' \
  -d '{"name":"page_view","anonymous_id":"device-abc","data":{"path":"/pricing"}}' \
  https://engage.example.com/api/v1/events

curl --user "$WS:$KEY" -H 'Content-Type: application/json' \
  -d '{"name":"pricing_view","anonymous_id":"device-abc","data":{"plan":"premium"}}' \
  https://engage.example.com/api/v1/events
```

From the [Laravel SDK](../../../laravel-sdk/README.md), pass the device id as `anonymousId` and
leave `person` null. An anonymous-only event is now allowed (previously an event with no
person id was skipped):

```php
use TriggerEngage\Laravel\Facades\TriggerEngage;

TriggerEngage::event('page_view', ['path' => '/pricing'], anonymousId: 'device-abc');
TriggerEngage::event('pricing_view', ['plan' => 'premium'], anonymousId: 'device-abc');
```

## Identifying: the merge

When the user signs up, identify them and pass the same `anonymous_id`. Over HTTP that is
`PUT /api/v1/people/{external_id}` with an `anonymous_id` field; passing it triggers the merge.

```bash
curl --user "$WS:$KEY" -X PUT -H 'Content-Type: application/json' \
  -d '{"email":"ada@example.com","attributes":{"first_name":"Ada","plan":"premium"},"anonymous_id":"device-abc"}' \
  https://engage.example.com/api/v1/people/user-42
```

From the SDK, pass the device id as the third argument to `identify`:

```php
TriggerEngage::identify('user-42', [
    'email' => $user->email,
    'first_name' => $user->first_name,
    'plan' => 'premium',
], anonymousId: 'device-abc');
```

Both signatures are backward compatible — the new argument is optional and existing calls are
unaffected:

```php
TriggerEngage::identify(string $personId, array $attributes = [], ?string $anonymousId = null)
TriggerEngage::event(string $name, array $data = [], ?string $person = null, ?string $anonymousId = null)
```

### What the merge does

The anonymous profile is folded into the now-known person in a single database transaction:

| Data | What happens |
|---|---|
| Events, messages, automation runs, goal subscriptions, suppressions, segment memberships | **Reassigned** to the identified person. Unique constraints are respected — if both already belong to a segment, the duplicate is dropped, not duplicated. |
| Attributes | **Merged with anonymous values underneath the known ones** — the identified person's values win on conflict. |
| `email`, `phone`, unsubscribe status | Filled in from the anonymous profile only where the known person is **missing** them. |
| The anonymous shell | **Deleted**, and its `anonymous_id` is **claimed** on the identified person. |

Because the device id stays claimed, **later events fired with only that `anonymous_id` still
attribute to the same identified person** — you don't have to switch your client over to the
`person_id` immediately.

## End-to-end

1. Two pre-signup events arrive against `anonymous_id: "device-abc"` — a `page_view` and a
   `pricing_view`. These land on an anonymous person with a null `external_id`.
2. The user signs up. You identify `user-42` with their email and `anonymous_id: "device-abc"`.

Result: the two pre-signup events now belong to `user-42`, attributes are merged (known values
win), and the anonymous profile is gone. A follow-up `page_view` still tagged `device-abc` also
lands on `user-42`.

## Batch

`POST /api/v1/batch` supports `anonymous_id` on event items too. Note that `identify`-type batch
items still require a `person_id` — an identify item carrying only an `anonymous_id` is skipped.
See [Batch](../API.md#batch).

## Gotchas

- **No email → email sends are skipped.** An anonymous person can enter a journey, but email
  steps skip gracefully until an email is known (via a later track or the identify merge).
- **Known values win on attribute conflicts.** Anonymous attributes only fill gaps; they never
  overwrite what the identified person already has.
- **Identify is idempotent.** Calling it repeatedly with the same `anonymous_id` is safe — once
  the shell is folded in and the id claimed, re-running changes nothing.

## Next

- [HTTP API reference](../API.md) — `POST /events`, `PUT /people/{external_id}`, and `POST /batch`.
- [Laravel SDK](../../../laravel-sdk/README.md) — `identify` and `event` with the `anonymousId` argument.

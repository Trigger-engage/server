# HTTP API reference (v1)

The ingestion API is how external applications send people and events into Trigger Engage.
Most Laravel apps use the [SDK](../../laravel-sdk/README.md) instead of calling these endpoints
directly, but the SDK speaks exactly this API, and other languages or services can use it too.

- **Base path:** `/api/v1` (self-hosted) or `/trigger-engage/api/v1` (embedded)
- **Content type:** `application/json`
- **Rate limit:** 600 requests/minute per workspace (webhooks: 120/minute)

## Authentication

Every request uses **HTTP Basic auth**, where the username is the **workspace id** and the
password is an **API key**:

```
Authorization: Basic base64(workspace_id + ":" + api_key)
```

```bash
curl --user "ws_01hxyz...:te_abc..." https://engage.example.com/api/v1/people
```

A key is only valid inside the workspace it was issued for — neither half is useful alone.
Keys are stored hashed (SHA-256) and shown once at creation. Create one with
`php artisan engage:workspace "My Product"` or in the dashboard. A missing or invalid credential
returns `401 Unauthorized`.

## Events

### `POST /events`

Track an event, creating or updating its person in the same call. This is what triggers
automations and updates segment membership.

| Field | Type | Notes |
|---|---|---|
| `name` | string | **Required.** Event name, e.g. `customer_sign_up`. |
| `person_id` | string | Required unless `anonymous_id` is given. The person's `external_id`. |
| `anonymous_id` | string | Required unless `person_id` is given. A device/session id for [pre-signup events](guides/anonymous-identity.md). |
| `data` | object | Event payload, available to templates and conditions as `{{ event.* }}`. |
| `email`, `phone` | string | Optional; promoted onto the person's profile. |
| `attributes` / `properties` | object | Optional profile properties merged onto the person. |
| `idempotency_key` | string | Optional. A repeat with the same key is acknowledged but recorded once. |
| `occurred_at` | date | Optional ISO-8601 timestamp; defaults to now. |

```bash
curl --user "$WS:$KEY" -H 'Content-Type: application/json' \
  -d '{"name":"customer_sign_up","person_id":"user-42","data":{"plan":"free"},"idempotency_key":"signup-42"}' \
  https://engage.example.com/api/v1/events
```

**Responses**

- `202 Accepted` — `{"accepted": true, "occurrence_id": 1234}`
- `200 OK` — `{"accepted": true, "duplicate": true}` when the `idempotency_key` was already seen
- `422 Unprocessable` — validation failed (e.g. neither `person_id` nor `anonymous_id` given)

## People

A person is identified by an `external_id` you control. Anonymous people (see below) have a
null `external_id` and an `anonymous_id` instead.

### `GET /people`

Paginated list, newest first.

| Query | Notes |
|---|---|
| `search` | Matches `external_id`, `email`, or `phone` (substring). |
| `per_page` | 1–100, default 25. |

Returns a standard Laravel paginator whose `data` items use the [person resource](#person-resource).

### `GET /people/{external_id}`

```json
{ "person": { "external_id": "user-42", "email": "ada@example.com", "properties": { "plan": "free" }, ... } }
```

Returns `404` if the person does not exist.

### `PUT /people/{external_id}`

Upsert a person and merge properties (identify).

| Field | Type | Notes |
|---|---|---|
| `email`, `phone` | string | Promoted onto profile columns. |
| `attributes` / `properties` | object | Merged into the person's typed properties. |
| `anonymous_id` | string | If set, folds the matching [anonymous profile](guides/anonymous-identity.md) into this person. |

```bash
curl --user "$WS:$KEY" -X PUT -H 'Content-Type: application/json' \
  -d '{"email":"ada@example.com","attributes":{"first_name":"Ada","plan":"premium"}}' \
  https://engage.example.com/api/v1/people/user-42
```

Returns `{ "person": { ... } }`.

### `PATCH /people/{external_id}/properties`

Merge typed properties into an existing person. Same body as `PUT`.

### `DELETE /people/{external_id}/properties/{key}`

Remove a single property without deleting the person. Returns `{ "deleted": true|false, "person": { ... } }`.

### `DELETE /people/{external_id}`

Erase a person and their data (GDPR/NDPR). Returns `{ "deleted": true|false }`.

### Person resource

```json
{
  "external_id": "user-42",
  "anonymous_id": "device-abc",
  "email": "ada@example.com",
  "phone": "+2348012345678",
  "properties": { "first_name": "Ada", "plan": "premium" },
  "attributes": { "first_name": "Ada", "plan": "premium" },
  "created_at": "2026-07-11T09:00:00Z",
  "updated_at": "2026-07-11T09:05:00Z"
}
```

`attributes` mirrors `properties` for backward compatibility.

## Subscriptions

A person's channel-level subscriptions and their choice for each
[subscription topic](guides/subscriptions.md), the same state the unsubscribe page and
preference centre change. Topics are addressed by their key.

### `GET /people/{external_id}/subscriptions`

```json
{
  "external_id": "user-42",
  "channels": { "email": { "subscribed": true, "reason": null }, "sms": { "subscribed": false, "reason": "unsubscribe" }, "push": { "subscribed": true, "reason": null } },
  "topics": { "care_reminders": { "subscribed": false, "name": "Care reminders" } }
}
```

### `PUT /people/{external_id}/subscriptions`

```json
{ "topics": { "care_reminders": false }, "channels": { "sms": false } }
```

Only the topics and channels named change. An unknown topic key or channel is `422`. A topic that
follows a profile property writes that property. Returns the same body as `GET`.

## Segment membership

These endpoints manage **manual** segments only. Event-driven and
[rule-based](guides/segments.md) segments compute their own membership and reject manual edits
with `422`.

### `PUT /segments/{segment_id}/people/{external_id}`

Add an identified person to a manual segment. `segment_id` is the segment's public id (`seg_…`).

```json
{ "segment": "seg_01...", "person_id": "user-42", "member": true }
```

### `DELETE /segments/{segment_id}/people/{external_id}`

Remove a person from a manual segment. Returns `"member": false`.

## Batch

### `POST /batch`

Up to 500 mixed identify/event items — ideal for backfills. Send a top-level array, or
`{ "items": [...] }`.

```json
[
  { "type": "identify", "person_id": "user-1", "email": "a@example.com", "attributes": { "plan": "free" } },
  { "type": "event", "person_id": "user-1", "name": "customer_sign_up", "data": { "plan": "free" } }
]
```

Each item accepts the same fields as the single-item endpoints, including `anonymous_id`,
`idempotency_key`, and `occurred_at`. Identify items require a `person_id` (an item with only an
`anonymous_id` is skipped). Returns a summary:

```json
{ "identified": 1, "tracked": 1, "duplicates": 0, "skipped": 0 }
```

## Client endpoints (apps)

Browser and React Native apps call these directly with a **publishable client key**, usually
through [`@trigger-engage/js`](../../js-sdk/README.md). Create one on the dashboard's
**Connect your app** page. A client key never works with Basic auth on the endpoints above.

- **Base path:** `/api/v1/client` (self-hosted) or `/trigger-engage/api/v1/client` (embedded)
- **Rate limit:** 240 requests/minute per client
- **CORS:** answered for any origin, or only the key's allowed origins when it has some

| Header | |
|---|---|
| `X-TE-Key` | **Required.** The client key, `pk_…`. |
| `X-TE-Person` | The person's `external_id`, once the app knows who is signed in. |
| `X-TE-Identity` | Required with `X-TE-Person`: `HMAC-SHA256(person id, identity secret)`, computed on your server and handed to the app. A wrong token is `401`, never a silent fall back to anonymous. |
| `X-TE-Platform` | `ios`, `android` or `web`; stored as `last_seen_platform`. |

Every identified request also stamps `last_seen_at` on the person (at most every 5 minutes), so a
segment such as "not seen for 14 days" has something to read.

### `POST /client/events`

`name` (required), `data`, `occurred_at`, `idempotency_key`, and `anonymous_id` (required when
the request has no person). A key can be limited to a list of event names. Idempotency keys
are namespaced per client key, so they cannot collide with server calls.
`202` → `{"accepted": true, "duplicate": false}`.

### `POST /client/identify`

Needs a person. `anonymous_id` merges that device's anonymous history into the person (see
[anonymous identity](guides/anonymous-identity.md)); `properties` are merged onto the profile,
except `email`, `phone`, push tokens and the last-seen fields, which an app can't set on itself.

### `POST /client/push-tokens` · `DELETE /client/push-tokens`

Needs a person. `{"token": "ExponentPushToken[…]"}` adds the token to the person's
`expo_push_tokens` (the newest 10 are kept) or removes it, for example on log-out.

### Signing the person id

```php
// Embedded: the package signs with the workspace's newest client key.
use TriggerEngage\Server\Support\ClientIdentity;
$token = ClientIdentity::token('user-'.$user->id);

// Separate server: the identity secret was shown once when the client key was created.
$token = hash_hmac('sha256', 'user-'.$user->id, env('TRIGGER_ENGAGE_IDENTITY_SECRET'));
```

## Delivery webhooks

Providers post delivery status back to these public endpoints (no Basic auth; each is verified
by a provider signature or bearer token). Configure them in your provider dashboards — see
[provider configuration](../PRODUCTION.md#provider-configuration).

| Endpoint | Provider |
|---|---|
| `POST /api/v1/webhooks/termii/{channel_id}` | Termii delivery reports (HMAC-SHA512 signature) |
| `POST /api/v1/webhooks/onesignal/{channel_id}` | OneSignal Event Stream (`Authorization: Bearer <token>`) |

## Errors

Standard HTTP status codes with a JSON body:

| Status | Meaning |
|---|---|
| `401` | Missing/invalid workspace + key credentials, or (client endpoints) an unknown client key or identity token |
| `403` | Client endpoints: the request's origin isn't allowed for this client key |
| `404` | Person or resource not found in this workspace |
| `422` | Validation error (Laravel `{ "message", "errors": { ... } }`) |
| `429` | Rate limit exceeded |

## MCP server

AI agents read a workspace through the MCP server at `POST /mcp` (embedded: `/trigger-engage/mcp`,
off until enabled), authenticated with a server key as `Authorization: Bearer te_…`. It only
reads. See the [MCP guide](guides/mcp.md).

## Analytics is a dashboard, not an API

Metrics live on the **Analytics** page of the dashboard (`/app/analytics`), not in this
ingestion API. See the [Analytics guide](guides/analytics.md).

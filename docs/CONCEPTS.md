# Concepts

The whole system is small enough to hold in your head. This page is the mental model;
the [guides](README.md#build) go deeper on each capability.

## The one-sentence version

You send **people** and **events** into a **workspace**; **automations** react to events by
walking a graph that waits, branches, splits, and **sends messages** through **channels**;
**segments** group people for **broadcasts**; and **analytics** shows you what happened.

## Workspace

A tenant. It owns everything else — API keys, people, events, automations, templates,
channels, segments, and broadcasts. One server can host many workspaces, and data never
crosses between them. API requests authenticate as a workspace (see [API › Auth](API.md#authentication)).

## Person

A recipient. Identified by an `external_id` you choose (`user-42`, `therapist-9`), unique
within the workspace. A person has `email`, `phone`, and free-form **properties** stored as
typed JSON (strings, numbers, booleans, arrays, objects).

Templates read properties two ways: flattened as `{{ person.first_name }}`, or explicitly as
`{{ person.properties.first_name }}`. (`attributes` is the backward-compatible alias for
`properties`.)

A person can also exist **before you know who they are** — an
[anonymous profile](guides/anonymous-identity.md) keyed by a device/session `anonymous_id`,
with a null `external_id` until they identify. On identify, the anonymous history is folded
into the known person.

## Event

Something a person did — `customer_sign_up`, `appointment_booked`. An event has a `name` and
an optional JSON `data` payload, available to templates and conditions as `{{ event.* }}`.
Events are auto-registered on first receipt, and every occurrence is stored. **Events are what
start and advance automations.** Duplicate delivery is safe: an `idempotency_key` is recorded
and processed exactly once.

## Automation (journey)

A **graph** of nodes and edges with a single event trigger. An automation is **versioned** —
publishing freezes an immutable version, and in-flight runs finish on the version they started
on. Re-entry policy controls how often a person can enter: `every_time`,
`one_active_run_per_person`, or `once_ever_per_person`.

Node types: `trigger`, `delay`, `branch`, `wait_for_event`, `split` (A/B), `send_email` /
`send_sms` / `send_push`, and `exit`, plus an automation-wide `goal`. See
[Building journeys](guides/automations.md).

## Run

One person moving through one automation version. A run tracks its `current_node_id`, a
`wake_at` for durable delays, and a `context` blob. Every node execution is recorded as a
**run step** under a unique constraint — the mechanism that makes retries idempotent and
guarantees no double-sends.

## Template

A per-channel message body written in **Liquid**, with `{{ person.* }}` and `{{ event.* }}`
variables. Email templates also own a layout, preheader, sender override, and branded design
settings, edited in a visual composer with an exact live preview. Missing variables render
empty and are logged as warnings on the run step.

## Channel

A configured provider for a workspace, with encrypted credentials and one default per type:

- **Email** — SMTP, ZeptoMail, SES, Mailgun, Postmark (any SMTP-compatible provider)
- **SMS** — Termii
- **Push** — OneSignal

Delivery status (sent, delivered, bounced, failed) flows back in through provider
[webhooks](../PRODUCTION.md#provider-configuration).

## Segment

A reusable audience. Three kinds:

- **Manual** — membership managed explicitly through the API/SDK.
- **Event-driven** — a person joins the moment a chosen event fires.
- **Rule-based** — a boolean rule over attributes and behaviour (e.g. *booked but not attended
  in 30 days*) that **recomputes itself** as data changes and time passes.

See [Segments](guides/segments.md).

## Broadcast

A one-time email, SMS, or push campaign to a **point-in-time snapshot** of a segment. Snapshotting
before sending means later membership changes never alter an in-flight campaign, suppressed and
undeliverable people are skipped, and a draft can only be sent once.

## Suppression & unsubscribe

A person can be suppressed per channel (unsubscribe, bounce, complaint, manual). Suppressed
people are skipped on send, with the skip recorded on the run step. Every email carries a
signed unsubscribe link.

## Analytics

A time-series dashboard over the workspace: message volume (sent vs delivered), delivery funnel
(sent → delivered → opened → clicked), runs and events per day, per-channel breakdown, and
period-over-period deltas. See [Analytics](guides/analytics.md).

## The SDK contract

From your application you only ever do two things: **identify** a person and **track** an event.
Everything else — journeys, segments, sends — is configured in the dashboard and runs on the
server. The [Laravel SDK](../../laravel-sdk/README.md) makes those two calls fail-open: they are
queued, carry an idempotency key, and never throw into your app code.

# trigger-engage — Founding Spec (v0 draft, 2026-07-10)

Open-source, self-hostable messaging-automation platform: a drop-in alternative to the way
Mytherapist.ng uses Customer.io. Define events and drag-and-drop automations in a web UI;
fire events from any app via an SDK; the engine walks the automation graph and sends
email / SMS / push.

Two deliverables:

1. **Server** — the platform itself (API, automation engine, builder UI). Its own repo, MIT.
2. **Laravel SDK** — `composer require trigger-engage/laravel`. First of several SDKs.

---

## 1. What it must replace (ground truth from mytherapist.ng backend)

`backend/app/Services/CustomerIoService.php` is the entire integration surface today:

| Current call | Semantics |
|---|---|
| `syncUser($user)` / `syncTherapist($therapist)` | **Identify**: upsert a person (`user-{id}` / `therapist-{id}`) with attributes (email, name, phone, type, country, appointment counts, therapist status) |
| `trackFirstBooking/FirstCompletion/FirstRating` | **Track**: named event + payload against a person |
| `trackSessionRated`, `trackWalletFunded` | Same, with analytics-grade payloads (amounts, hour buckets) |
| `enabled()` + try/catch + `Integration::log` | **Fail-open**: messaging must never break signup/booking |
| `SyncCustomerIoCommand` | **Backfill**: bulk re-identify existing people |

Call sites: model observers, event listeners, payment webhooks. So the contract is:
**identify + track, per-person, fire-and-forget, never throws.** That is the SDK's whole job.

Channels already in use at Mytherapist.ng (first-class drivers for MVP):
- **Email:** ZeptoMail (via Laravel mail) — driver model must also cover SMTP/SES/Mailgun/Postmark for OSS users
- **SMS:** Termii (Nigeria-first; Twilio later for OSS reach)
- **Push:** OneSignal (Expo/FCM later)

## 2. Core concepts

- **Workspace** — tenant. Owns API keys, people, events, automations, channels. One server hosts many workspaces.
- **Person** — recipient. `external_id` (e.g. `user-42`) unique per workspace;
  `email`, `phone`, and typed free-form properties JSON (`attributes` remains the
  backward-compatible alias). Templates read flattened `{{ person.* }}` values and
  the explicit `{{ person.properties.* }}` namespace. A person may start **anonymous**
  (null `external_id`, keyed by a device/session `anonymous_id`) and be merged into a
  known person on identify.
- **Event** — named thing that happened (`customer_sign_up`). Auto-registered on first receipt, or pre-defined in the UI with an expected-payload schema (for template autocomplete + docs).
- **Automation** — a graph (nodes + edges) with an event trigger. Draft → Active → Paused. **Versioned**: activating saves an immutable version; in-flight runs finish on the version they started on.
- **Run** — one person moving through one automation version. Holds current node + `wake_at` for delays.
- **Template** — per-channel message body (Liquid syntax) with `{{ person.x }}` and
  `{{ event.x }}` variables. Email templates also own a layout, inbox preheader,
  sender override, and customizable brand settings; Mytherapist.ng is the default design.
- **Channel** — configured provider credentials per workspace (encrypted), one default per type.
- **Segment** — reusable audience. Manual membership is managed through the API/SDK;
  event-driven membership adds a person idempotently whenever its configured event fires;
  rule-based membership is a boolean rule over attributes and behaviour that recomputes itself.
- **Broadcast** — one-time email, SMS, or push campaign to a point-in-time snapshot of a segment.

## 3. Automation node types

MVP:
- **Trigger** (exactly one): event name, plus re-entry policy — `every_time` / `one_active_run_per_person` / `once_ever_per_person`
- **Action: send_email / send_sms / send_push** — references a template + channel
- **Delay** — fixed duration, or "until next HH:MM in workspace timezone" (quiet hours)
- **Wait for event** — resumes on a later event for the same person, optionally correlated to the
  trigger payload; durable `matched` / `timed_out` edges guarantee a terminal outcome
- **Goal / stop event** — automation-wide event listener that completes an active run from any node,
  optionally correlated to the trigger payload
- **Branch** — predicate on person attributes or event payload (`equals/not/gt/lt/contains/exists`), true/false edges
- **Exit**

Shipped since MVP:
- **A/B split** — routes each person to one of 2–4 weighted message variants (deterministic per
  person), converging back to the journey, with live per-variant conversion results.

Post-MVP: webhook action node, segment-membership condition node.

## 4. Data model (server)

```
workspaces          id, name, timezone
api_keys            workspace_id, name, key_hash, last_used_at
people              workspace_id, external_id?, anonymous_id?, email, phone, attributes json,
                    unsubscribed_at — UNIQUE(workspace_id, external_id),
                    UNIQUE(workspace_id, anonymous_id)
events              workspace_id, name, payload_schema json?, first_seen_at
                    — UNIQUE(workspace_id, name)
event_occurrences   workspace_id, event_id, person_id?, anonymous_id?, payload json,
                    idempotency_key?, occurred_at — UNIQUE(workspace_id, idempotency_key)
automations         workspace_id, name, status(draft|active|paused), trigger_event_id,
                    reentry_policy, active_version_id
automation_versions automation_id, graph json {nodes:[], edges:[]}, published_at
automation_runs     automation_version_id, person_id, occurrence_id,
                    status(running|waiting|waiting_event|completed|cancelled|failed),
                    current_node_id, wake_at, context json
run_steps           run_id, node_id, type, status, error?, executed_at
                    — UNIQUE(run_id, node_id, attempt-scope) → no double-sends
run_event_waits     run_id, person_id, event_id, node_id, status, match_rules json,
                    occurrence_cursor, expires_at, matched_occurrence_id?
run_goal_subscriptions run_id, person_id, event_id, goal_id, status, match_rules json,
                    occurrence_cursor, reached_occurrence_id?, reached_at?
templates           workspace_id, channel(email|sms|push), name, subject?, body,
                    layout, preheader?, settings json, from_name?, from_address?
channels            workspace_id, type, driver, credentials (encrypted), is_default
messages            workspace_id, person_id, run_step_id?, channel, template_id,
                    provider_message_id?, status(queued|sent|delivered|bounced|failed),
                    rendered snapshot, sent_at
suppressions        workspace_id, person_id, channel, reason(unsub|bounce|complaint|manual)
segments            workspace_id, public_id, name, type(manual|event|rule), event_id?,
                    rules json?, recomputed_at?
segment_person      segment_id, person_id, source(api|event|rule), event_occurrence_id?, added_at
broadcasts          workspace_id, segment_id, template_id, channel_id, channel, status
broadcast_recipients broadcast_id, person_id, message_id?, status, error?, sent_at?
```

## 5. Engine execution

1. `POST /events` → validate key → upsert person (if inline attributes) → store occurrence → 202.
2. Queued matcher finds active automations triggered by that event; re-entry policy checked; creates run(s).
3. Runs advance node-by-node via queued jobs (Horizon/Redis). **Delays persist `wake_at` on the run; a
   scheduler tick (every minute) re-enqueues due runs.** Not delayed jobs — survives queue flushes and
   supports multi-day waits on any queue driver.
   Event waits use the same durable tick plus an occurrence cursor and row lock. A qualifying event
   recorded before the deadline wins even if its worker runs after the deadline; otherwise timeout wins once.
   Automation goals use durable per-run subscriptions and the same correlation rules. Goal, wait,
   timeout, and send-reservation transitions lock the run first so only one state change can win.
4. Every send: check suppressions → render template (Liquid, strict-ish: missing vars render empty +
   warning in run log) → dispatch through channel driver → record `messages` row. `run_steps`
   uniqueness makes retries idempotent (an action either completed or it didn't; never twice).
5. Failures: per-step retries w/ backoff; after N failures the step fails, run continues or fails per
   node setting (default: skip-and-continue for sends).

## 6. HTTP API (ingestion, v1)

```
POST /api/v1/events            {name, person_id, data?, idempotency_key?, occurred_at?}
PUT  /api/v1/people/{ext_id}   {email?, phone?, attributes?}          (upsert/merge)
POST /api/v1/batch             [{type: event|identify, ...}, ...]     (≤500/req, backfills)
DELETE /api/v1/people/{ext_id}                                        (GDPR/NDPR erasure)
Auth: HTTP Basic — username = workspace_id, password = api_key. The server verifies the
      key exists AND belongs to that workspace; either half alone is useless.
```

## 7. Laravel SDK (`trigger-engage/laravel`)

```php
// config/trigger-engage.php: endpoint, workspace_id, api_key, enabled, queue, timeout
// Initialization requires BOTH workspace_id and api_key (Basic auth pair).
TriggerEngage::identify('user-42', ['email' => ..., 'first_name' => ..., 'type' => 'user']);
TriggerEngage::event('customer_sign_up', ['plan' => 'free'], person: 'user-42');
```

- Fire-and-forget: facade dispatches a queued job (configurable sync mode for local dev);
  HTTP failures log and swallow — **the SDK never throws into app code** (mirrors the
  CustomerIoService fail-open pattern).
- Auto idempotency keys (ULID per call) so job retries can't double-trigger automations.
- Test fake: `TriggerEngage::fake()` + `assertEventSent('customer_sign_up', fn($e) => ...)`,
  `assertIdentified('user-42')` — the DX feature that makes adoption easy.
- Migration at Mytherapist.ng: keep `CustomerIoService`'s public methods, swap its internals
  to the SDK. Call sites (observers, listeners, webhook drivers) don't change.

## 8. Server stack & builder UI

- **Laravel 12 + MySQL/Postgres + Redis (Horizon)** — matches team expertise, easy self-host
  (single container + db + redis; ship `docker-compose.yml`).
- **UI: Inertia + React + shadcn/ui + Tailwind** — the team already knows shadcn from the web
  apps, and an OSS product needs a polished, contributor-friendly UI. (Filament considered —
  faster for CRUD — but the canvas is the product; committing to React throughout is simpler.)
- **Canvas: React Flow (@xyflow/react)** — the industry standard for drag-and-drop node
  editors. Graph serializes to the `automation_versions.graph` JSON verbatim.

## 9. Roadmap

- **v0.1 — engine core.** Schema, ingestion API, Laravel SDK w/ fake, email channel
  (SMTP + ZeptoMail), linear automations (trigger → delay → email) built with a simple
  step-list UI. Prove the loop end-to-end.
- **v0.2 — the canvas.** React Flow builder, branch nodes, versioning/publish flow,
  SMS (Termii) + push (OneSignal) channels, per-run timeline view.
- **v0.3 — production hardening.** Suppressions + unsubscribe links, customizable template
  editor with exact preview (complete; test-send remains), responsive SaaS management
  shell with dedicated resource pages, batch/backfill API, delivery webhooks in (bounces),
  metrics dashboard.
- **v0.35 — audiences.** Manual and event-driven segments, SDK membership operations,
  snapshot-based segment broadcasts, queued per-recipient delivery and campaign reporting.
- **v0.36 — content authoring.** Visual email composer with a lossless HTML/Liquid
  escape hatch, reusable formatting controls, variable insertion, and exact rendered preview.
- **v0.37 — customer profiles.** Searchable People UI, typed property editor, API/SDK
  property mutation, profile activity and segment context, and legacy attribute compatibility.
- **v0.38 — dual-mode distribution.** The same server code runs standalone or as the
  `trigger-engage/server` Composer package, with isolated migrations, configurable routes,
  host authorization, published UI assets, and direct in-process SDK dispatch.
- **v0.4 — dogfood.** Point mytherapist.ng backend at it behind CustomerIoService,
  run in shadow mode alongside Customer.io, compare, cut over.
- **v0.5 — Customer.io parity.** Rule-based (behavioural) segments, A/B split node with
  per-variant results, anonymous → identified profile merge, and a time-series analytics
  dashboard. See the [changelog](../CHANGELOG.md).

## 10. Open decisions

1. **Repo home** — separate GitHub org (`trigger-engage/server`, `trigger-engage/laravel`) vs
   personal org. Also verify the name is free on Packagist/GitHub/npm before committing.
2. **License** — MIT (recommended for adoption) vs AGPL (protects against closed-source hosted clones).
3. **Payload naming** — SDK facade verb: `event()` (recommended) vs `sendEvent()` as in the pitch.
4. **Segments/audiences in scope?** Resolved: manual, event-driven, and rule-based
   (behavioural) membership plus one-time segment broadcasts are all implemented.

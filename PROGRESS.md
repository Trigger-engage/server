# trigger-engage — Progress

## v0.1: Engine core — server + Laravel SDK (v0.1.0)

Founding milestone, shipped 2026-07-10. See [SPEC.md](SPEC.md) for architecture.

### Server (`server/`, Laravel 13)
- Schema: workspaces, api_keys, people, events, event_occurrences, automations,
  automation_versions, automation_runs, run_steps, run_event_waits,
  run_goal_subscriptions, templates, channels, messages, suppressions, segments,
  segment membership, broadcasts, and broadcast recipients.
- Ingestion API v1: `POST /events`, `PUT /people/{id}`, `DELETE /people/{id}`, `POST /batch`
  (≤500 items). Auth = combined workspace_id + api_key as HTTP Basic; keys stored sha256-hashed.
- Automation engine: versioned graphs (in-flight runs pin to their version), node types
  trigger / delay (duration or until-time in workspace tz) / wait_for_event / branch /
  send_email / send_sms / send_push / exit,
  re-entry policies (every_time, one_active_run_per_person, once_ever_per_person),
  pre-dispatch per-(run,node) send reservations, matcher idempotency and
  per-automation locking, suppression checks, configurable send retry/backoff,
  and `engage:tick` for durable delays, event-wait timeouts, and retry wakeups.
- Email channel: on-the-fly SMTP mailer from encrypted workspace credentials (ZeptoMail-ready),
  log/array drivers for dev/tests; rendered snapshot + status stored per message.
- Templating: Liquid filters/control flow with `{{ person.* }} / {{ event.* }}`
  context; missing variables render empty and are written to the run step. Email templates
  have editable content, sender, branding, colors, links and footer copy with the current
  Mytherapist.ng shell as the default, exact live preview, CSS inlining, and plain-HTML fallback.
- React + Inertia + React Flow UI: responsive SaaS app shell with dedicated Overview,
  Automations, Events, Templates, Channels and Runs pages; workspace-scoped setup,
  draggable automation editing, event correlation and timeout paths, immutable publish,
  per-run timelines, metrics, and pause controls.
- `engage:workspace` command + DemoSeeder (working sign-up → delay → welcome-email automation).
- 92 tests green, including full-loop, branch routing, event correlation and timeout races,
  automation-wide goal stopping and cancellation,
  delayed-worker precedence, re-entry, matcher/job replay,
  send retry/backoff, template warnings, UI workspace isolation, suppression,
  no-email skip, and version-pinning coverage.

### Laravel SDK (`laravel-sdk/`, `trigger-engage/laravel`)
- `TriggerEngage::identify()` / `::setProperties()` / `::event()` facade; queued by default, sync mode available.
- Initialization requires the combination of `TRIGGER_ENGAGE_WORKSPACE_ID` + `TRIGGER_ENGAGE_API_KEY`
  (Basic auth pair) plus endpoint; disabled ⇒ silent no-op.
- Fail-open transport: HTTP errors log and swallow — never throws into app code.
- Idempotency keys minted at call time so queue retries can't double-trigger automations.
- `TriggerEngage::fake()` with assertEventSent / assertIdentified / assertEventSentTimes /
  assertEventNotSent / assertNothingSent / assertPropertiesSet. 9 tests green (Testbench).

### Next (v0.2)

Completed production-hardening pass:
- Draggable React Flow canvas view plus delay/email/SMS/push step editing.
- Termii SMS and OneSignal push drivers with encrypted workspace credentials.
- Authenticated, idempotent delivery webhooks and message delivery/open/click/bounce state.
- Per-run timeline, 30-day workspace metrics, signed unsubscribe links, and suppression updates.
- Redis/Horizon, scheduler, Postgres and Nginx Docker deployment.
- Mytherapist `off` / `shadow` / `primary` adapter, defaulting to `off`.
- Durable wait-for-event nodes with correlated matching, explicit timeout paths,
  atomic match-vs-timeout claiming, and timeline visibility.
- Automation-wide goal/stop events with correlated matching, durable per-run
  subscriptions, retry/wait cancellation, and goal-aware run timelines.
- Customizable email-template editor and server-rendered preview with the current
  Mytherapist.ng email design as the default for new and existing templates.
- Polished WYSIWYG email composer with visual/HTML modes, rich formatting, links,
  email buttons, Liquid variable insertion, undo/redo, and live exact preview.
- Searchable People profiles with typed Customer.io-style properties, API/SDK merge and
  deletion operations, property-aware template context, activity counts, and segment history.
- Responsive sidebar navigation and focused management sub-pages, with authenticated
  workspace scoping verified across every section.
- Manual and event-driven segments, idempotent membership API/SDK operations, and
  one-time email/SMS/push broadcasts with audience snapshots, suppression checks,
  per-recipient status, and duplicate-send protection.
- A protected All people segment is provisioned for every workspace, backfills
  existing profiles, follows new identified and anonymous profiles, and can be
  selected directly for workspace-wide broadcasts.
- Segment management pages provide searchable member lists, manual add/remove,
  rename and description editing, protected defaults, and history-safe deletion.
- Dual distribution from one codebase: the standalone server remains deployable
  under `/app`, while `trigger-engage/server` installs into Laravel 10-13 with
  isolated migrations, published dashboard assets, host-authenticated routes,
  an in-process SDK dispatcher, and `engage:install` provisioning.

### Channel connection testing

- "Test connection" on the Channels page probes provider credentials before saving,
  via `POST /channels/test` → `ChannelConnectionTester`. SMTP opens and authenticates a
  live connection using the same Symfony transport the send path builds (nothing is sent);
  Termii checks the balance endpoint and OneSignal validates the app ID + REST API key.
- Results surface as flash success/error (new shared `flash.error` prop and Layout banner);
  no channel row is persisted by the probe.

### Broadcast composer — edit and preview at send time

- Broadcasts now own their message content. New `subject`, `body`, `layout`,
  `preheader`, `settings`, `from_name`, and `from_address` columns snapshot the chosen
  template when a draft is created, so per-campaign edits never mutate the shared template.
- New `Broadcasts/Edit` compose page (Customer.io-style): editable subject, body (WYSIWYG
  for email), branding, and sender, alongside an exact live preview. Creating a draft
  redirects straight into it; the broadcast list links drafts to "Edit & preview" and sent
  broadcasts to a read-only "View content".
- `GET /broadcasts/{id}/edit` + `PUT /broadcasts/{id}` (draft-only guard); the composer reuses
  the existing `POST /templates/preview` renderer. `BroadcastController` and `TemplateController`
  share a new `EditsMessageContent` trait (rules, normalization, preview), and the React editor
  UI is a shared `MessageComposer` component reused by both the template and broadcast editors.
- Send path renders `Broadcast::messageTemplate()` (the broadcast's own content, falling back to
  the template) instead of the live template, so edits ship exactly as previewed. 4 new feature
  tests cover snapshot-on-create, scoped composer + preview, content override on send, and the
  sent-broadcast read-only guard; full suite 70 green.

Credential-dependent gates remain documented in `PRODUCTION.md`: real provider
verification, shadow comparison, published SDK packaging, deployment and cutover.

## v0.5: Customer.io parity — segments, A/B, identity, analytics (v0.5.0)

Shipped 2026-07-11. Four capabilities closing the biggest gaps against Customer.io's
platform. Full suite 87 green (server) + 9 (SDK).

### Dynamic rule-based / attribute segments
- New segment `type=rule` with a boolean rule group (`{match: all|any, conditions:[…]}`).
  Conditions are attribute predicates (`plan equals premium`, using the full operator set:
  equals/not_equals/gt/gte/lt/lte/contains/exists/not_exists) or behavioural
  (`performed | did-not-perform <event> within N days`, 0 = ever). Expresses audiences like
  "booked but not attended in 30 days", "premium plan", "inactive 14 days".
- `SegmentRuleQuery` translates a rule group into a People query and is the single source of
  truth for both full recompute and single-person membership, so the two never disagree.
- Membership is materialized into `segment_person` (source=`rule`) so broadcasts and everything
  else keep working unchanged. It stays current three ways: incrementally per-person on new
  events (`ProcessEventOccurrence`) and profile edits (person API), and via a periodic
  `engage:tick` sweep that recomputes stale segments to catch time-based drift.
- Rule builder UI on the Segments page (match all/any + attribute/behaviour condition rows),
  editable after creation via `PUT /segments/{id}` with immediate recompute. Manual/event
  segments and the membership API are unchanged; the API still rejects non-manual edits.

### A/B testing — split node with per-variant results
- New `split` step (2–4 weighted variants, each its own channel + template). At publish it
  compiles to a `split` node plus one generated send node per variant, wired with per-variant
  branch edges that converge to the next step — reusing all existing send + branch-routing
  logic. The linear editor round-trips it via `config.variants`.
- `RunEngine` assigns variants deterministically (hash of person + node id) so a person always
  lands on the same variant and retries never reshuffle the experiment; assignment is recorded
  on the run step.
- Live per-variant results on the automation editor: entered, converted, and rate, where
  conversion is the automation goal (or journey completion when there is no goal), with a
  "leading" badge for the front-runner. Flow canvas renders the split with labelled A/B
  branches and variant nodes.

### Anonymous → identified profile merge
- `people.anonymous_id` (+ nullable `external_id`) and `event_occurrences.anonymous_id`. Events
  and profiles can now be recorded against a device/session `anonymous_id` before signup;
  anonymous profiles accumulate attributes and contact info.
- On `identify` with an `anonymous_id`, the anonymous profile is folded into the known person:
  its events, messages, runs, goal subscriptions, suppressions and segment memberships are
  reassigned (respecting unique constraints), attributes merged underneath the known ones
  (identity wins), and the empty shell deleted — all in one transaction. Late anonymous events
  still attribute to the person via a claimed device id.
- API: events accept `person_id` **or** `anonymous_id` (`required_without`); `PUT /people/{id}`
  and `/batch` accept `anonymous_id`. SDK contract extended with optional `anonymousId` on
  `identify()` and `event()` across the HTTP, embedded, and fake dispatchers (backward compatible).

### Time-series analytics & charts
- New Analytics page + nav item with a 7/14/30/90-day range selector. Stat tiles (messages,
  delivered, failed, runs, events) each show a period-over-period delta.
- Zero-filled daily series (portable day-bucket expression per DB driver) drive dependency-free
  inline-SVG charts: a sent-vs-delivered area trend with hover crosshair, runs/day and
  events/day trends, a delivery funnel (sent → delivered → opened → clicked), and a per-channel
  delivered/failed breakdown. `AnalyticsController` is fully workspace-scoped.

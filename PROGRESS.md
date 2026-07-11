# trigger-engage — Progress

## v0.1: Engine core — server + Laravel SDK (v0.1.0)

Founding milestone, shipped 2026-07-10. See [SPEC.md](SPEC.md) for architecture.

### Server (`server/`, Laravel 13)
- Schema: workspaces, api_keys, people, events, event_occurrences, automations,
  automation_versions, automation_runs, run_steps, run_event_waits,
  run_goal_subscriptions, templates, channels, messages, suppressions.
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
  context; missing variables render empty and are written to the run step.
- React + Inertia + React Flow UI: workspace-scoped event/template/channel setup,
  draggable automation editing, event correlation and timeout paths, immutable publish,
  per-run timelines, metrics, and pause controls.
- `engage:workspace` command + DemoSeeder (working sign-up → delay → welcome-email automation).
- 43 tests green, including full-loop, branch routing, event correlation and timeout races,
  automation-wide goal stopping and cancellation,
  delayed-worker precedence, re-entry, matcher/job replay,
  send retry/backoff, template warnings, UI workspace isolation, suppression,
  no-email skip, and version-pinning coverage.

### Laravel SDK (`laravel-sdk/`, `trigger-engage/laravel`)
- `TriggerEngage::identify()` / `::event()` facade; queued by default, sync mode available.
- Initialization requires the combination of `TRIGGER_ENGAGE_WORKSPACE_ID` + `TRIGGER_ENGAGE_API_KEY`
  (Basic auth pair) plus endpoint; disabled ⇒ silent no-op.
- Fail-open transport: HTTP errors log and swallow — never throws into app code.
- Idempotency keys minted at call time so queue retries can't double-trigger automations.
- `TriggerEngage::fake()` with assertEventSent / assertIdentified / assertEventSentTimes /
  assertEventNotSent / assertNothingSent. 6 tests green (Testbench).

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

Credential-dependent gates remain documented in `PRODUCTION.md`: real provider
verification, shadow comparison, published SDK packaging, deployment and cutover.

# trigger-engage — Progress

## v0.1: Engine core — server + Laravel SDK (v0.1.0)

Founding milestone, shipped 2026-07-10. See [SPEC.md](SPEC.md) for architecture.

### Server (`server/`, Laravel 12)
- Schema: workspaces, api_keys, people, events, event_occurrences, automations,
  automation_versions, automation_runs, run_steps, templates, channels, messages, suppressions.
- Ingestion API v1: `POST /events`, `PUT /people/{id}`, `DELETE /people/{id}`, `POST /batch`
  (≤500 items). Auth = combined workspace_id + api_key as HTTP Basic; keys stored sha256-hashed.
- Automation engine: versioned graphs (in-flight runs pin to their version), node types
  trigger / delay (duration or until-time in workspace tz) / branch / send_email / exit,
  re-entry policies (every_time, one_active_run_per_person, once_ever_per_person),
  per-(run,node) unique step records as the double-send guard, suppression checks,
  `engage:tick` scheduler command for durable wake_at-based delays.
- Email channel: on-the-fly SMTP mailer from encrypted workspace credentials (ZeptoMail-ready),
  log/array drivers for dev/tests; rendered snapshot + status stored per message.
- Templating: dependency-free `{{ person.* }} / {{ event.* }}` dot-path renderer (Liquid later).
- `engage:workspace` command + DemoSeeder (working sign-up → delay → welcome-email automation).
- 15 tests (51 assertions) green, including full-loop, branch routing, re-entry,
  suppression, no-email skip, and version-pinning coverage. Live HTTP smoke test verified
  end-to-end: identify → event → delayed run → tick → rendered email → completed run.

### Laravel SDK (`laravel-sdk/`, `trigger-engage/laravel`)
- `TriggerEngage::identify()` / `::event()` facade; queued by default, sync mode available.
- Initialization requires the combination of `TRIGGER_ENGAGE_WORKSPACE_ID` + `TRIGGER_ENGAGE_API_KEY`
  (Basic auth pair) plus endpoint; disabled ⇒ silent no-op.
- Fail-open transport: HTTP errors log and swallow — never throws into app code.
- Idempotency keys minted at call time so queue retries can't double-trigger automations.
- `TriggerEngage::fake()` with assertEventSent / assertIdentified / assertEventSentTimes /
  assertEventNotSent / assertNothingSent. 6 tests green (Testbench).

### Next (v0.2)
- React Flow drag-and-drop builder (Inertia + React + shadcn/ui), automation CRUD UI.
- SMS (Termii) and push (OneSignal) channels.
- Per-run timeline view.

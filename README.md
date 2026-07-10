# trigger-engage server

Open-source, self-hostable messaging-automation platform. Define events and
automations; fire events from your app via an SDK; the engine walks the
automation graph and sends email (SMS and push coming in v0.2).

See [SPEC.md](SPEC.md) for the full architecture and roadmap. This is v0.1:
ingestion API + automation engine + email channel. The drag-and-drop builder
(React Flow) lands in v0.2 — for now automations are defined as graph JSON
(see `database/seeders/DemoSeeder.php` for a complete working example).

## Quick start

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed --seeder=DemoSeeder   # prints demo SDK credentials
php artisan serve
php artisan schedule:work    # wakes delayed runs (or cron: engage:tick every minute)
php artisan queue:work       # if QUEUE_CONNECTION != sync
```

Create a real workspace (prints the SDK credential pair once):

```bash
php artisan engage:workspace "My Product" --timezone=Africa/Lagos
```

## Authentication

Every API request authenticates with the **combination of workspace id and API
key** as HTTP Basic auth — workspace id is the username, API key the password.
A key is only valid inside the workspace it was issued for. Keys are stored
hashed (sha256) and shown once at creation.

## API (v1)

| Route | Purpose |
|---|---|
| `POST /api/v1/events` | Track an event: `{name, person_id, data?, idempotency_key?, occurred_at?}` |
| `PUT /api/v1/people/{external_id}` | Upsert a person: `{email?, phone?, attributes?}` |
| `DELETE /api/v1/people/{external_id}` | Erase a person (GDPR/NDPR) |
| `POST /api/v1/batch` | Up to 500 mixed identify/event items (backfills) |

## Automation graphs

```jsonc
{
  "nodes": [
    {"id": "trigger", "type": "trigger", "config": {}},
    {"id": "wait",    "type": "delay",   "config": {"minutes": 60}},        // or days/hours, or {"until_time": "09:00"} in workspace tz
    {"id": "fork",    "type": "branch",  "config": {"field": "event.plan", "operator": "equals", "value": "free"}},
    {"id": "send",    "type": "send_email", "config": {"template_id": 1, "channel_id": 1}},
    {"id": "done",    "type": "exit",    "config": {}}
  ],
  "edges": [
    {"from": "trigger", "to": "wait"},
    {"from": "wait", "to": "fork"},
    {"from": "fork", "to": "send", "branch": "true"},
    {"from": "fork", "to": "done", "branch": "false"},
    {"from": "send", "to": "done"}
  ]
}
```

Templates use `{{ person.* }}` and `{{ event.* }}` variables. Publishing an
automation freezes an immutable version; in-flight runs finish on the version
they started on. Re-entry policies: `every_time`, `one_active_run_per_person`,
`once_ever_per_person`.

## Engine guarantees

- **No double-sends:** each run/node execution is recorded under a unique
  constraint; retries of a crashed advance skip already-executed nodes.
- **Durable delays:** waits persist `wake_at` on the run and a scheduler tick
  wakes them — they survive queue restarts and support multi-day waits.
- **Idempotent ingestion:** replays with the same `idempotency_key` are
  acknowledged but recorded and processed only once.
- **Suppression-aware:** unsubscribed/suppressed people are skipped, with the
  skip recorded in the run's step log.

## Tests

```bash
./vendor/bin/phpunit
```

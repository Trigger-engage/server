# trigger-engage server

Open-source messaging automation for Laravel. Run it as a standalone,
self-hosted service or install the complete dashboard and engine into an
existing Laravel application with Composer.

The UI includes a draggable React Flow journey builder, behavioural segments,
A/B tests, provider configuration, a time-series analytics dashboard, immutable
publishing, and per-run timelines.

**📚 Full documentation is in [docs/](docs/README.md)** — concepts, guides
(journeys, segments, A/B testing, anonymous identity, analytics), the
[HTTP API reference](docs/API.md), and the [architecture spec](SPEC.md).
For deployment see [Deploying the backend](#deploying-the-backend) and
[PRODUCTION.md](PRODUCTION.md).

![The analytics dashboard: message, delivery, run, and event totals with period deltas above a message-volume trend chart](docs/images/analytics.png)

## Choose an installation

### Embed it in an existing Laravel application

```bash
composer require trigger-engage/server
php artisan engage:install --name="My Product" --timezone=Africa/Lagos
```

Open `/trigger-engage`. The package uses the host database, authentication,
queue, and scheduler. SDK facade calls are dispatched in-process, so there is
no separate Trigger Engage host or API credential to configure. The dashboard
is restricted to authenticated host users by default.

### Run it as a self-hosted service

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed --seeder=DemoSeeder   # prints demo SDK credentials
php artisan serve
php artisan schedule:work    # wakes delayed runs (or cron: engage:tick every minute)
php artisan queue:work       # if QUEUE_CONNECTION != sync
```

Open `http://localhost:8000/app`. The browser uses the same HTTP Basic
credential pair as the API: workspace id as username and API key as password.
The management UI uses a responsive SaaS-style sidebar with dedicated Overview,
Automations, Events, Templates, Channels, and Runs pages. It can create event
definitions, message templates, delivery channels, and versioned automations
with delay, event-wait, goal, and timeout paths.

See [docs/INSTALLATION.md](docs/INSTALLATION.md) for the full Composer-package
and self-hosted installation guide, including authorization, upgrades, worker
requirements, and guidance for choosing a deployment mode.
Maintainers can use [docs/PUBLISHING.md](docs/PUBLISHING.md) for the ordered
SDK/server tagging, archive, and clean-install release checks.

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
| `POST /api/v1/events` | Track an event and optionally update its person: `{name, person_id?, anonymous_id?, email?, phone?, attributes?, data?, idempotency_key?, occurred_at?}` — one of `person_id` / `anonymous_id` is required |
| `GET /api/v1/people` | Paginated people; optional `search` and `per_page` query parameters |
| `GET /api/v1/people/{external_id}` | Read a person and their typed custom properties |
| `PUT /api/v1/people/{external_id}` | Upsert a person: `{email?, phone?, properties?, anonymous_id?}`; `attributes` remains supported. Passing `anonymous_id` merges that [anonymous profile](docs/guides/anonymous-identity.md) in |
| `PATCH /api/v1/people/{external_id}/properties` | Merge typed properties into a person |
| `DELETE /api/v1/people/{external_id}/properties/{key}` | Remove one property without deleting the person |
| `DELETE /api/v1/people/{external_id}` | Erase a person (GDPR/NDPR) |
| `POST /api/v1/batch` | Up to 500 mixed identify/event items as a top-level array; `{items: [...]}` is also accepted |
| `PUT /api/v1/segments/{segment_id}/people/{external_id}` | Add an identified person to a manual segment |
| `DELETE /api/v1/segments/{segment_id}/people/{external_id}` | Remove a person from a manual segment |

## Segments and broadcasts

Segments are reusable audiences, in three flavours ([full guide](docs/guides/segments.md)):

- **Manual** — membership changed explicitly through the API or SDK.
- **Event-driven** — bound to an event; when that event is accepted, its person is added
  idempotently.
- **Rule-based** — a boolean rule over attributes and behaviour (e.g. *booked but not
  attended in 30 days*) that **recomputes itself** as data changes and time passes.

Every workspace also includes a protected **All people** segment. Existing profiles are
backfilled during migration and every new identified or anonymous profile joins it
automatically, so a workspace-wide broadcast never needs audience setup.

Event-driven and rule-based membership is computed by the engine and cannot be changed
through the manual-membership API.

Broadcasts send one template to a segment over email, SMS, or push. Sending snapshots
the current member list into recipient records before queueing delivery, so later
membership changes do not change an in-progress campaign. Suppressions and missing
destinations are skipped, and every recipient retains a delivery status and message
ledger entry. A broadcast draft can only be sent once.

## Person properties

Every person has Customer.io-style custom properties stored as typed JSON: strings,
numbers, booleans, nulls, arrays, and nested objects. Existing `attributes` payloads
remain supported and are treated as properties. Properties merge on SDK/API calls
and can also be managed in the People UI. Templates can read them directly as
`{{ person.appointments }}` or through `{{ person.properties.appointments }}`.

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

Email templates open in a dedicated editor with an exact server-rendered live
preview. New and migrated email templates default to the current
Mytherapist.ng design: warm paper background, white card and gold ribbon,
Plus Jakarta Sans typography, app badges, social links, navy footer, crisis
copy, and signed unsubscribe link. Subject, preheader, Liquid/HTML content,
sender override, logo, identity, colors, store links, social links, and footer
copy are customizable per template. A plain-HTML layout remains available for
special-purpose messages. The body composer includes a full visual editor with
headings, formatting, lists, alignment, links, buttons, colors, undo/redo and
one-click Liquid variables, plus a lossless HTML/Liquid source mode. Final email
CSS is inlined for broad client support.

`wait_for_event` nodes persist until a later event for the same person arrives
or their deadline passes. Their two edges use `branch: "matched"` and
`branch: "timed_out"`. Optional match rules correlate an incoming field to the
original trigger payload, for example `appointment_id` to `appointment_id`.
The editor can stop, continue, or send one fallback message on timeout before
rejoining the main path.

An automation version can also define a global goal event. Every run subscribes
when it starts; a matching occurrence for that person completes the run from
any node and cancels pending delays, event waits, and send retries. Optional
correlation prevents one entity's goal from stopping another entity's journey.
The triggering occurrence and payload are retained in the run timeline.

`split` nodes run an **A/B test**: each person is routed to one of 2–4 weighted
message variants (deterministically, so a person always gets the same variant),
then all paths rejoin the journey. The editor shows live per-variant entered,
converted, and conversion-rate results. See [A/B testing](docs/guides/ab-testing.md).

## Analytics

The **Analytics** page (`/app/analytics`) is a time-series dashboard over the
workspace: message volume (sent vs delivered), a delivery funnel (sent → delivered
→ opened → clicked), runs and events per day, a per-channel breakdown, and
period-over-period deltas, across a 7/14/30/90-day window. Delivered/opened/clicked
depend on provider [delivery webhooks](PRODUCTION.md#provider-configuration). See the
[analytics guide](docs/guides/analytics.md).

## Engine guarantees

- **No double-sends:** each run/node execution is recorded under a unique
  constraint before provider dispatch. Concurrent workers cannot claim the
  same send; ambiguous stale sends fail for reconciliation instead of risking
  another delivery.
- **Durable delays:** waits persist `wake_at` on the run and a scheduler tick
  wakes them — they survive queue restarts and support multi-day waits.
- **Race-safe event waits:** an occurrence cursor plus row-level claiming makes
  event-match and timeout mutually exclusive. Pre-deadline events still win
  when queue processing is delayed.
- **Goal-safe execution:** goal, wait, timeout, and send reservation transitions
  share a run-first lock order. A completed goal cannot be overwritten by a
  late scheduler or queue worker.
- **Idempotent ingestion:** replays with the same `idempotency_key` are
  acknowledged but recorded and processed only once.
- **Suppression-aware:** unsubscribed/suppressed people are skipped, with the
  skip recorded in the run's step log.
- **Retry-aware:** recorded send failures use durable per-step backoff and a
  configurable final action (`continue` by default, or fail the run).
- **Liquid templates:** filters and control flow are supported; missing output
  variables render empty and are recorded as warnings on the run step.
- **Preview fidelity:** the editor preview and delivery channel use the same
  layout renderer and sample Liquid context, so the saved preview matches the
  stored message snapshot.

## Deploying the backend

The recommended deployment is the checked-in Docker Compose stack. It runs the
following long-lived services:

| Service | Responsibility |
|---|---|
| `web` | Nginx, public HTTP entry point |
| `app` | Laravel PHP-FPM application and API |
| `horizon` | Redis queue workers |
| `scheduler` | Laravel scheduler; wakes delays, event timeouts, retries, and records Horizon metrics |
| `postgres` | Durable application data |
| `redis` | Queues, cache, overlap locks, and Horizon state |

Compose is a good production baseline for one host. For high availability, run
the same `app`, `horizon`, and `scheduler` images on an orchestrator with managed
PostgreSQL/Redis, multiple application replicas behind a load balancer, and
exactly one scheduler replica.

### 1. Prepare the host and DNS

Install Docker Engine with the Compose plugin on a supported Linux host. Point
the deployment hostname, for example `engage.example.com`, to its load balancer
or reverse proxy. Only HTTPS should be publicly accessible; PostgreSQL and Redis
must remain private.

Clone the repository and work from the server directory:

```bash
git clone <repository-url> trigger-engage
cd trigger-engage/server
cp .env.example .env
chmod 600 .env
```

### 2. Configure production environment variables

Edit `.env` before starting any containers. At minimum, set:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://engage.example.com
APP_KEY=base64:replace-with-a-generated-key
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=trigger_engage
DB_USERNAME=trigger_engage
DB_PASSWORD=replace-with-a-long-random-password

QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=redis

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
```

Generate `APP_KEY` once and paste the complete result into `.env`:

```bash
printf 'base64:%s\n' "$(openssl rand -base64 32)"
```

Store `.env` and `APP_KEY` in a secret manager or encrypted backup. Channel
credentials are encrypted with `APP_KEY`; losing or casually rotating it makes
those credentials unreadable. `APP_URL` must be the final HTTPS origin because
it is used when generating signed links such as unsubscribe URLs.

The Compose web service listens on host port `8080` by default. Set
`HTTP_PORT=127.0.0.1:8080` in `.env` when TLS terminates in a reverse proxy on
the same machine, or use an appropriate private interface and firewall rule
when TLS terminates elsewhere.

### 3. Build and start the stack

```bash
docker compose build --pull
docker compose up -d postgres redis
docker compose run --rm app php artisan migrate --force
docker compose up -d app web horizon scheduler
```

Do not run `DemoSeeder` in production. It creates development credentials and a
sample automation. The `scheduler` service must remain running: `engage:tick`
runs every minute and is responsible for durable delays, wait-for-event
timeouts, retry wakeups, and stale execution recovery.

Check the processes and application liveness:

```bash
docker compose ps
docker compose exec app php artisan horizon:status
docker compose exec app php artisan schedule:list
curl --fail --silent --show-error https://engage.example.com/up
```

`/up` is a liveness endpoint. The authenticated smoke test below additionally
exercises PostgreSQL, Redis, the ingestion API, and the queue worker.

### 4. Terminate TLS and protect management routes

The bundled Nginx container serves HTTP and adds security headers, but it does
not terminate TLS. Put it behind a cloud load balancer, ingress controller,
Caddy, or another HTTPS reverse proxy. A minimal same-host Caddy route is:

```caddyfile
engage.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

Keep `/app` behind an identity-aware proxy, VPN, or private network in addition
to its workspace Basic credentials. Horizon's production gate denies access
until explicitly configured in `app/Providers/HorizonServiceProvider.php`; if
you enable `/horizon`, protect it with the same private access layer. Public
provider webhook routes under `/api/v1/webhooks/*` must remain reachable over
HTTPS.

### 5. Create the production workspace

Create the first workspace after migrations complete:

```bash
docker compose exec app php artisan engage:workspace "My Product" --timezone=Africa/Lagos
```

The command prints `TRIGGER_ENGAGE_WORKSPACE_ID` and
`TRIGGER_ENGAGE_API_KEY` once. Save both immediately in the calling
application's secret manager. They are also the HTTP Basic username and
password used to open `/app`.

In `/app`, create templates and delivery channels. Start with internal test
recipients before enabling production traffic:

- SMTP/ZeptoMail: use a verified sender domain and a dedicated SMTP credential.
- Termii: configure its regional base URL, API key, sender ID, route, and
  webhook secret. Send delivery reports to
  `POST /api/v1/webhooks/termii/{channel_id}`.
- OneSignal: configure App ID, REST API key, and Event Stream bearer token.
  Send events to `POST /api/v1/webhooks/onesignal/{channel_id}` with
  `Authorization: Bearer <token>`.

### 6. Run an end-to-end smoke test

Set the credentials returned by `engage:workspace`, then submit a test event:

```bash
export TRIGGER_ENGAGE_URL=https://engage.example.com
export TRIGGER_ENGAGE_WORKSPACE_ID=ws_replace_me
export TRIGGER_ENGAGE_API_KEY=te_replace_me

curl --fail-with-body \
  --user "$TRIGGER_ENGAGE_WORKSPACE_ID:$TRIGGER_ENGAGE_API_KEY" \
  --header 'Content-Type: application/json' \
  --data "{\"name\":\"deployment_smoke_test\",\"person_id\":\"deploy-smoke\",\"idempotency_key\":\"deploy-$(date +%s)\"}" \
  "$TRIGGER_ENGAGE_URL/api/v1/events"
```

The response should be accepted, the event should appear in `/app`, and the
Horizon queue should return to zero pending jobs. Remove the synthetic person
when finished:

```bash
curl --fail-with-body --request DELETE \
  --user "$TRIGGER_ENGAGE_WORKSPACE_ID:$TRIGGER_ENGAGE_API_KEY" \
  "$TRIGGER_ENGAGE_URL/api/v1/people/deploy-smoke"
```

### Operations

Useful operational commands:

```bash
docker compose logs --follow web app horizon scheduler
docker compose exec app php artisan horizon:status
docker compose exec app php artisan queue:failed
docker compose exec app php artisan engage:tick
```

Keep only one scheduler replica unless the schedule is changed to use a shared
single-server lock. Horizon can be scaled independently when queue latency
rises. After every code deployment, ask Horizon to finish current jobs and
reload the new application code:

```bash
docker compose exec app php artisan horizon:terminate
```

Docker's restart policy will bring the `horizon` service back automatically.
Alert on failed jobs, queue latency, overdue `waiting_event` runs, stale
`processing` steps, message failure/bounce rate, webhook errors, disk capacity,
PostgreSQL health, and Redis persistence.

### Backups and restore testing

Back up PostgreSQL before every release and on a regular schedule:

```bash
mkdir -p backups
docker compose exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' \
  | gzip > "backups/trigger-engage-$(date +%F-%H%M%S).sql.gz"
```

Also retain the deployment `.env`/`APP_KEY` in a separate encrypted secret
store and back up the named `postgres-data` volume according to the host or
cloud provider's snapshot procedure. Redis uses append-only persistence, but
PostgreSQL remains the source of truth.

Test restores into an isolated environment. A typical restore target is:

```bash
gunzip --stdout backups/trigger-engage-YYYY-MM-DD-HHMMSS.sql.gz \
  | docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" "$POSTGRES_DB"'
```

Do not test restores against the live database.

### Updating an existing single-host deployment

Use a maintenance window unless every migration in the release is known to be
backward compatible:

```bash
# Back up PostgreSQL first.
git fetch --all --tags
git checkout <release-tag-or-commit>
docker compose build --pull
docker compose exec app php artisan down --retry=60
docker compose stop horizon scheduler
docker compose run --rm app php artisan migrate --force
docker compose up -d app web horizon scheduler
docker compose exec app php artisan up
curl --fail --silent --show-error https://engage.example.com/up
```

Then rerun the authenticated smoke test and inspect failed jobs. Application
rollback is safe only when the previous code understands the migrated schema.
Otherwise restore the matching database backup and application image together.
Never use `migrate:rollback` blindly on production automation data.

### Deploying without Docker Compose

For a managed platform, reproduce the same process topology with PHP 8.4 FPM,
Nginx, PostgreSQL, and Redis. Build the release artifact with:

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
```

Run `php artisan horizon` as a supervised, restartable worker and run exactly
one supervised `php artisan schedule:work` process. All web, Horizon, and
scheduler replicas must share the same `APP_KEY`, PostgreSQL database, Redis
instance, and release version. See [PRODUCTION.md](PRODUCTION.md) for provider
cutover, shadow-mode rollout, monitoring, and release gates.

## Tests

```bash
./vendor/bin/phpunit
```

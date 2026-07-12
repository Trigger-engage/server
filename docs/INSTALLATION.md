# Installing Trigger Engage

Trigger Engage supports two production layouts from the same codebase:

- **Embedded Composer package:** install it inside an existing Laravel application. People, events, automations, and messages use that application's database, queue, scheduler, authentication, and deployment. No second web service or HTTP call is required.
- **Self-hosted server:** deploy Trigger Engage as its own Laravel application and connect one or more applications through the SDK/API.

## Option A: Composer package

### Requirements

- PHP 8.2 or newer
- Laravel 10.48, 11, 12, or 13 (use a currently supported Laravel release in production)
- A supported Laravel database
- A queue worker for production delivery and automation processing
- Laravel's scheduler running every minute

Install the package into the host Laravel application:

```bash
composer require trigger-engage/server
php artisan engage:install --name="My Product" --timezone=Africa/Lagos
```

`engage:install` publishes the dashboard assets and configuration, runs only the namespaced Trigger Engage migrations, and creates the first workspace. It also prints an optional API key for clients that still need to send events over HTTP.

Open `/trigger-engage` in the host application. The package defaults to the host's `web` middleware and fails closed unless the visitor is authenticated. It returns HTTP 403 for guests instead of assuming the host has a named login route.

To restrict access further, define the `viewTriggerEngage` gate in the host application's `AppServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewTriggerEngage', fn ($user) => $user->is_admin);
}
```

You can change the gate name, middleware, routes, asset location, or selected workspace in `config/trigger-engage-server.php` or with these environment variables:

```dotenv
TRIGGER_ENGAGE_EMBEDDED_WORKSPACE_ID=ws_...
TRIGGER_ENGAGE_AUTHORIZATION_GATE=viewTriggerEngage
TRIGGER_ENGAGE_UI_PREFIX=trigger-engage
TRIGGER_ENGAGE_API_PREFIX=trigger-engage/api/v1
TRIGGER_ENGAGE_UI_MIDDLEWARE=web,trigger-engage.authorize
```

The workspace id is optional while the database contains one Trigger Engage workspace. Set it explicitly before creating additional workspaces.

The server package includes `trigger-engage/laravel`, so application code uses the same facade as a remote installation:

```php
use TriggerEngage\Laravel\Facades\TriggerEngage;

TriggerEngage::identify((string) $user->id, [
    'email' => $user->email,
    'first_name' => $user->first_name,
]);

TriggerEngage::setProperties((string) $user->id, [
    'appointments' => $user->appointments()->count(),
]);

TriggerEngage::event('appointment_booked', [
    'appointment_id' => $appointment->id,
], person: (string) $user->id);
```

In embedded mode, these calls write through the package's in-process dispatcher. They do not need `TRIGGER_ENGAGE_ENDPOINT`, `TRIGGER_ENGAGE_WORKSPACE_ID`, or `TRIGGER_ENGAGE_API_KEY`, and they preserve the SDK's fail-open behavior.

Production must run the host application's normal queue and scheduler processes:

```bash
php artisan queue:work

# Run through cron every minute:
php artisan schedule:run
```

After upgrading the Composer package, publish the new dashboard bundle and apply migrations:

```bash
php artisan engage:install --force
php artisan queue:restart
```

Commit `config/trigger-engage-server.php` if you customize it. Published files under `public/vendor/trigger-engage` are build artifacts and should be deployed with the host application.

## Option B: self-hosted server

Clone the repository and deploy from `server/`:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
cp .env.example .env
php artisan key:generate
php artisan migrate --force
npm ci
npm run build
php artisan engage:workspace "My Product" --timezone=Africa/Lagos
```

Self-hosted mode is selected by the standalone bootstrap and these defaults:

```dotenv
TRIGGER_ENGAGE_MODE=self-hosted
TRIGGER_ENGAGE_UI_PREFIX=app
TRIGGER_ENGAGE_API_PREFIX=api/v1
```

Run the web application, a queue worker (Horizon is included), and the Laravel scheduler. The dashboard is `/app`; both it and `/api/v1` use the workspace id and API key as HTTP Basic credentials.

For a complete Docker, TLS, database, Redis, backup, smoke-test, and rollback procedure, use the [backend deployment guide](../README.md#deploying-the-backend) and [production gates](../PRODUCTION.md).

In every calling Laravel application, install only the SDK and configure the remote server credentials:

```bash
composer require trigger-engage/laravel
```

```dotenv
TRIGGER_ENGAGE_ENDPOINT=https://engage.example.com
TRIGGER_ENGAGE_WORKSPACE_ID=ws_...
TRIGGER_ENGAGE_API_KEY=te_...
```

## Choosing a mode

Use the Composer package when one Laravel product owns the messaging data and you want one deployment. Use the self-hosted server when several applications share Trigger Engage, the messaging system needs independent scaling or releases, or you want its database and failure domain isolated from product traffic.

Maintainers preparing the first Packagist release should follow the [package publishing checklist](PUBLISHING.md). Until both package names are published, the same installation can be tested with Composer `path` repositories pointing at `laravel-sdk/` and `server/`.

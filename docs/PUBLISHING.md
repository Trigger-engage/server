# Publishing the Composer packages

The monorepo contains two independently versioned Composer packages:

1. `trigger-engage/laravel` — the lightweight SDK and facade
2. `trigger-engage/server` — the complete engine and dashboard; requires SDK `^0.2`

Publish the SDK first so Composer can resolve the server dependency. Both package names must be connected to their public VCS repositories on Packagist before the first public release.

## Release checklist

From `laravel-sdk/`:

```bash
composer validate --strict
composer test
git tag -s v0.2.0 -m "Trigger Engage Laravel SDK v0.2.0"
git push origin v0.2.0
```

Wait for Packagist to show `trigger-engage/laravel` version `0.2.0`. Then, from `server/`:

```bash
composer validate --strict
composer test
npm ci
npm run build
npm run build:package
composer archive --format=zip
```

Inspect the archive before tagging. It must contain `dist/build/manifest.json`, the package service provider, and the Trigger Engage migrations. It must not contain `vendor/`, `node_modules/`, or the standalone `public/build/` bundle.

Commit the generated `dist/build` bundle with the release because Composer consumers do not run Node/Vite during installation. Then tag and publish:

```bash
git tag -s v0.2.0 -m "Trigger Engage Server v0.2.0"
git push origin v0.2.0
```

After Packagist indexes `trigger-engage/server`, verify the public artifact in clean Laravel applications:

```bash
composer require trigger-engage/server:^0.2
php artisan engage:install --name="Package smoke test" --force
php artisan route:list --path=trigger-engage
php artisan schedule:list
```

Confirm that the dashboard returns 403 for a guest, renders for an authenticated host user, the SDK facade resolves to `TriggerEngage\Server\Services\EmbeddedDispatcher`, and an identify/event pair persists without an HTTP endpoint.

The `repositories` entry in the server's root `composer.json` is only for developing both sibling packages in this checkout. Composer ignores dependency-defined repositories when an application installs the published server package.

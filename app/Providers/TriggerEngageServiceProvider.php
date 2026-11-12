<?php

namespace TriggerEngage\Server\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use TriggerEngage\Laravel\Contracts\Dispatcher;
use TriggerEngage\Server\Console\Commands\CreateWorkspace;
use TriggerEngage\Server\Console\Commands\EngageTick;
use TriggerEngage\Server\Console\Commands\InstallTriggerEngage;
use TriggerEngage\Server\Contracts\WorkspaceResolver;
use TriggerEngage\Server\Http\Controllers\Web\PreferenceCenterController;
use TriggerEngage\Server\Http\Controllers\Web\UnsubscribeController;
use TriggerEngage\Server\Http\Middleware\AuthenticateClient;
use TriggerEngage\Server\Http\Middleware\AuthenticateMcp;
use TriggerEngage\Server\Http\Middleware\AuthenticateWorkspace;
use TriggerEngage\Server\Http\Middleware\AuthorizeDashboard;
use TriggerEngage\Server\Http\Middleware\HandleInertiaRequests;
use TriggerEngage\Server\Http\Middleware\ResolveEmbeddedWorkspace;
use TriggerEngage\Server\Services\ConfiguredWorkspaceResolver;
use TriggerEngage\Server\Services\EmbeddedDispatcher;

class TriggerEngageServiceProvider extends ServiceProvider
{
    protected string $root;

    public function __construct($app)
    {
        parent::__construct($app);
        $this->root = dirname(__DIR__, 2);
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->root.'/config/trigger-engage-server.php', 'trigger-engage-server');
        $this->app->singleton(WorkspaceResolver::class, ConfiguredWorkspaceResolver::class);

        if (config('trigger-engage-server.mode') === 'embedded') {
            $this->app->singleton(Dispatcher::class, EmbeddedDispatcher::class);
        }
    }

    public function boot(Router $router): void
    {
        $this->loadMigrationsFrom($this->root.'/database/migrations/trigger-engage');
        $this->loadViewsFrom($this->root.'/resources/views', 'trigger-engage');

        $router->aliasMiddleware('trigger-engage.auth', AuthenticateWorkspace::class);
        $router->aliasMiddleware('trigger-engage.authorize', AuthorizeDashboard::class);
        $router->aliasMiddleware('trigger-engage.workspace', ResolveEmbeddedWorkspace::class);
        $router->aliasMiddleware('trigger-engage.mcp', AuthenticateMcp::class);

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([InstallTriggerEngage::class, CreateWorkspace::class, EngageTick::class]);
            $this->publishes([$this->root.'/config/trigger-engage-server.php' => config_path('trigger-engage-server.php')], 'trigger-engage-config');

            $assetSource = is_dir($this->root.'/dist/build') ? $this->root.'/dist/build' : $this->root.'/public/build';
            $this->publishes([$assetSource => public_path('vendor/trigger-engage/build')], 'trigger-engage-assets');
        }

        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('engage:tick')->everyMinute()->withoutOverlapping();
        });
    }

    protected function registerRoutes(): void
    {
        $embedded = config('trigger-engage-server.mode') === 'embedded';
        $management = config('trigger-engage-server.routes.management_prefix');
        $managementMiddleware = config('trigger-engage-server.routes.management_middleware', ['web']);
        $managementMiddleware[] = HandleInertiaRequests::class;
        $managementMiddleware[] = $embedded ? 'trigger-engage.workspace' : 'trigger-engage.auth';

        Route::middleware($managementMiddleware)
            ->prefix($management)
            ->name('engage.')
            ->group($this->root.'/routes/web.php');

        Route::middleware(['api', SubstituteBindings::class, 'trigger-engage.auth', 'throttle:600,1'])
            ->prefix(config('trigger-engage-server.routes.api_prefix'))
            ->group($this->root.'/routes/api.php');

        Route::middleware(['api', SubstituteBindings::class, 'throttle:120,1'])
            ->prefix(config('trigger-engage-server.routes.api_prefix').'/webhooks')
            ->group($this->root.'/routes/webhooks.php');

        // Publishable-key endpoints for browser and React Native apps.
        Route::middleware(['api', 'throttle:240,1', AuthenticateClient::class])
            ->prefix(config('trigger-engage-server.routes.api_prefix').'/client')
            ->group($this->root.'/routes/client.php');

        if (config('trigger-engage-server.mcp.enabled')) {
            Route::middleware([
                ...config('trigger-engage-server.mcp.middleware', ['api', 'trigger-engage.mcp']),
                'throttle:'.max(1, (int) config('trigger-engage-server.mcp.rate_limit', 120)).',1',
            ])
                ->prefix(config('trigger-engage-server.mcp.path', 'mcp'))
                ->group($this->root.'/routes/mcp.php');
        }

        $publicPrefix = config('trigger-engage-server.routes.unsubscribe_prefix');
        Route::middleware('web')->prefix($publicPrefix)->group(function (): void {
            Route::get('/unsubscribe/{message}', [UnsubscribeController::class, 'show'])->middleware('signed')->name('unsubscribe.show');
            Route::post('/unsubscribe/{message}', [UnsubscribeController::class, 'destroy'])->middleware('signed')->name('unsubscribe.destroy');
            Route::post('/unsubscribe/{message}/undo', [UnsubscribeController::class, 'undo'])->middleware('signed')->name('unsubscribe.undo');
            Route::get('/preferences/{person}', [PreferenceCenterController::class, 'show'])->middleware('signed')->name('preferences.show');
            Route::post('/preferences/{person}', [PreferenceCenterController::class, 'update'])->middleware('signed')->name('preferences.update');
        });

        // Mailbox providers POST here with no session or CSRF token (RFC 8058),
        // so this one route stays outside the web group.
        Route::middleware([SubstituteBindings::class, 'signed', 'throttle:60,1'])->prefix($publicPrefix)
            ->post('/unsubscribe/{message}/one-click', [UnsubscribeController::class, 'oneClick'])
            ->name('unsubscribe.one-click');

        if (! $embedded) {
            Route::redirect('/', '/'.$management);
        }
    }
}

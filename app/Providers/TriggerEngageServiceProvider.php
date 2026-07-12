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
use TriggerEngage\Server\Http\Controllers\Web\UnsubscribeController;
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

        $publicPrefix = config('trigger-engage-server.routes.unsubscribe_prefix');
        Route::middleware('web')->prefix($publicPrefix)->group(function (): void {
            Route::get('/unsubscribe/{message}', [UnsubscribeController::class, 'show'])->middleware('signed')->name('unsubscribe.show');
            Route::post('/unsubscribe/{message}', [UnsubscribeController::class, 'destroy'])->middleware('signed')->name('unsubscribe.destroy');
        });

        if (! $embedded) {
            Route::redirect('/', '/'.$management);
        }
    }
}

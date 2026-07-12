<?php

namespace TriggerEngage\Server\Console\Commands;

use Illuminate\Console\Command;
use TriggerEngage\Server\Models\ApiKey;
use TriggerEngage\Server\Models\Workspace;
use TriggerEngage\Server\Providers\TriggerEngageServiceProvider;

class InstallTriggerEngage extends Command
{
    protected $signature = 'engage:install {--name=Trigger Engage} {--timezone=UTC} {--force}';

    protected $description = 'Install Trigger Engage migrations, assets, config, and its first embedded workspace';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->call('vendor:publish', [
            '--provider' => TriggerEngageServiceProvider::class,
            '--tag' => 'trigger-engage-config',
            '--force' => $force,
        ]);
        $this->call('vendor:publish', [
            '--provider' => TriggerEngageServiceProvider::class,
            '--tag' => 'trigger-engage-assets',
            '--force' => $force,
        ]);
        $this->call('migrate', ['--force' => true]);

        $workspace = Workspace::query()->first();
        $plaintext = null;

        if (! $workspace) {
            $workspace = Workspace::create([
                'name' => $this->option('name'),
                'timezone' => $this->option('timezone'),
            ]);
            [, $plaintext] = ApiKey::issue($workspace, 'install');
        }

        $this->newLine();
        $this->info('Trigger Engage is ready inside this Laravel application.');
        $this->line('Dashboard: '.url('/'.config('trigger-engage-server.routes.management_prefix')));
        $this->line('Workspace: '.$workspace->public_id);

        if ($plaintext) {
            $this->warn('Optional external API key (shown once): '.$plaintext);
        }

        if (Workspace::query()->count() > 1 && blank(config('trigger-engage-server.workspace_id'))) {
            $this->warn('Set TRIGGER_ENGAGE_EMBEDDED_WORKSPACE_ID='.$workspace->public_id.' before using embedded SDK calls.');
        }

        return self::SUCCESS;
    }
}

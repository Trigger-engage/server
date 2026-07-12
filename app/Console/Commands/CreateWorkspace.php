<?php

namespace TriggerEngage\Server\Console\Commands;

use Illuminate\Console\Command;
use TriggerEngage\Server\Models\ApiKey;
use TriggerEngage\Server\Models\Workspace;

class CreateWorkspace extends Command
{
    protected $signature = 'engage:workspace {name} {--timezone=UTC}';

    protected $description = 'Create a workspace and issue its first API key';

    public function handle(): int
    {
        $workspace = Workspace::create([
            'name' => $this->argument('name'),
            'timezone' => $this->option('timezone'),
        ]);

        [, $plaintext] = ApiKey::issue($workspace);

        $this->info('Workspace created. SDK credentials (the key is shown ONCE):');
        $this->table(['Setting', 'Value'], [
            ['TRIGGER_ENGAGE_WORKSPACE_ID', $workspace->public_id],
            ['TRIGGER_ENGAGE_API_KEY', $plaintext],
        ]);

        return self::SUCCESS;
    }
}

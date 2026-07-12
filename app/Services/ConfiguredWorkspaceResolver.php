<?php

namespace TriggerEngage\Server\Services;

use LogicException;
use TriggerEngage\Server\Contracts\WorkspaceResolver;
use TriggerEngage\Server\Models\Workspace;

class ConfiguredWorkspaceResolver implements WorkspaceResolver
{
    public function resolve(): Workspace
    {
        $configured = config('trigger-engage-server.workspace_id');

        if (filled($configured)) {
            return Workspace::query()->where('public_id', $configured)->firstOrFail();
        }

        $workspaces = Workspace::query()->limit(2)->get();

        if ($workspaces->count() !== 1) {
            throw new LogicException('Embedded Trigger Engage needs exactly one workspace or TRIGGER_ENGAGE_EMBEDDED_WORKSPACE_ID.');
        }

        return $workspaces->first();
    }
}

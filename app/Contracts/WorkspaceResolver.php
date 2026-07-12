<?php

namespace TriggerEngage\Server\Contracts;

use TriggerEngage\Server\Models\Workspace;

interface WorkspaceResolver
{
    public function resolve(): Workspace;
}

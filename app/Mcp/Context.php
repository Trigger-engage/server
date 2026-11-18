<?php

namespace TriggerEngage\Server\Mcp;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Workspace;

/**
 * Everything a tool call knows about who is asking: the workspace the
 * credentials belong to, what may be shown about people, and who to name
 * as the caller. Tools never look a workspace up themselves, so every query
 * they run is scoped to this one.
 */
class Context
{
    public function __construct(
        public readonly Workspace $workspace,
        public readonly Redactor $redactor,
        public readonly string $actor = 'mcp',
    ) {}

    /**
     * A timestamp in the workspace's own timezone, as ISO 8601 with its
     * offset. Raw strings (aggregates like max()) are read in the app's
     * timezone, which is how the database stores them.
     */
    public function time(DateTimeInterface|string|null $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        $time = is_string($time) ? Carbon::parse($time) : Carbon::instance($time);

        return $time->setTimezone($this->workspace->timezone ?: 'UTC')->toIso8601String();
    }

    /**
     * The fields that identify a person in a list, contact details masked.
     *
     * @return array<string, mixed>|null
     */
    public function person(?Person $person): ?array
    {
        if (! $person) {
            return null;
        }

        return [
            'id' => $person->id,
            'external_id' => $person->external_id,
            'anonymous_id' => $person->getAttribute('anonymous_id'),
            'email' => $this->redactor->email($person->email),
            'phone' => $this->redactor->phone($person->phone),
        ];
    }

    /** Where a human can open this record in the dashboard. */
    public function url(string $route, mixed $parameters = []): ?string
    {
        $name = 'engage.'.$route;

        return app('router')->has($name) ? route($name, $parameters) : null;
    }
}

<?php

namespace TriggerEngage\Server\Services;

use Illuminate\Support\Facades\DB;
use TriggerEngage\Server\Models\Template;
use TriggerEngage\Server\Models\Workspace;

/**
 * The template a new broadcast starts from when nobody picks one: one per
 * channel per workspace, marked is_default. A workspace without one gets a
 * plain starter, so a broadcast never has to wait for someone to write a
 * template first. Email starters keep the workspace brand (no overrides).
 */
class BroadcastTemplates
{
    public const CHANNELS = ['email', 'sms', 'push'];

    public function defaultFor(Workspace $workspace, string $channel): Template
    {
        return $workspace->templates()->where('channel', $channel)->where('is_default', true)->first()
            ?? DB::transaction(function () use ($workspace, $channel): Template {
                // Re-check under a lock on the workspace row so two first
                // visits don't each create a starter.
                Workspace::query()->whereKey($workspace->id)->lockForUpdate()->first();

                return $workspace->templates()->where('channel', $channel)->where('is_default', true)->first()
                    ?? $workspace->templates()->create([...$this->starter($workspace, $channel), 'is_default' => true]);
            });
    }

    /** @return array<string, int> channel => template id */
    public function defaultIds(Workspace $workspace): array
    {
        return collect(self::CHANNELS)
            ->mapWithKeys(fn (string $channel) => [$channel => $this->defaultFor($workspace, $channel)->id])
            ->all();
    }

    public function makeDefault(Template $template): void
    {
        DB::transaction(function () use ($template): void {
            Template::query()->where('workspace_id', $template->workspace_id)
                ->where('channel', $template->channel)->update(['is_default' => false]);
            $template->update(['is_default' => true]);
        });
    }

    /** @return array<string, mixed> */
    protected function starter(Workspace $workspace, string $channel): array
    {
        $greeting = 'Hi {{ person.first_name | default: "there" }},';

        return match ($channel) {
            'email' => [
                'channel' => 'email',
                'name' => 'Broadcast starter (email)',
                'subject' => "News from {$workspace->name}",
                'body' => "<p>{$greeting}</p>\n<p>Write your message here.</p>",
                'layout' => config('email_templates.default_layout', 'mytherapist'),
            ],
            'sms' => [
                'channel' => 'sms',
                'name' => 'Broadcast starter (SMS)',
                'body' => "{$greeting} write your message here.",
                'layout' => 'plain',
            ],
            default => [
                'channel' => 'push',
                'name' => 'Broadcast starter (push)',
                // A push's subject is its title.
                'subject' => $workspace->name,
                'body' => 'Write your message here.',
                'layout' => 'plain',
            ],
        };
    }
}

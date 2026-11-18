<?php

namespace TriggerEngage\Server\Mcp\Tools\Workspace;

use Illuminate\Support\Arr;
use TriggerEngage\Server\Http\Controllers\Web\ChannelController;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\AutomationIndex;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\Channel;

class ListChannels extends Tool
{
    public function name(): string
    {
        return 'list_channels';
    }

    public function title(): string
    {
        return 'List delivery channels';
    }

    public function description(): string
    {
        return 'The configured email, SMS and push providers (SMTP/ZeptoMail, Termii, OneSignal, Expo…). For each: its '
            .'driver, whether it is the default for its type (send steps without a channel use the default), its '
            .'non-secret settings, which secrets are stored (never their values), and which automations and how many '
            .'broadcasts send through it.';
    }

    public function properties(): array
    {
        return ['type' => ['type' => 'string', 'enum' => ['email', 'sms', 'push'], 'description' => 'Only this channel type.']];
    }

    public function rules(): array
    {
        return ['type' => ['nullable', 'in:email,sms,push']];
    }

    public function handle(array $arguments, Context $context): array
    {
        $usage = new AutomationIndex($context->workspace);

        $channels = Channel::query()
            ->where('workspace_id', $context->workspace->id)
            ->when($arguments['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->orderBy('type')->orderByDesc('is_default')->orderBy('name')
            ->get();

        return [
            'channels' => $channels->map(fn (Channel $channel) => [
                ...$channel->only('id', 'type', 'name', 'driver', 'is_default'),
                'settings' => $context->redactor->withoutSecrets(Arr::except($channel->credentials ?? [], ChannelController::SECRETS)),
                'secrets_stored' => collect(ChannelController::SECRETS)
                    ->filter(fn (string $key) => filled($channel->credentials[$key] ?? null))->values()->all(),
                'used_by_automations' => $usage->usingChannel($channel->id),
                'broadcasts' => $context->workspace->broadcasts()->where('channel_id', $channel->id)->count(),
                'updated_at' => $context->time($channel->updated_at),
            ])->values()->all(),
            'url' => $context->url('channels.index'),
        ];
    }
}

<?php

namespace TriggerEngage\Server\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;
use TriggerEngage\Server\Engine\Channels\EmailChannel;
use TriggerEngage\Server\Engine\Channels\PushChannel;
use TriggerEngage\Server\Engine\Channels\SmsChannel;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Models\BroadcastRecipient;
use TriggerEngage\Server\Models\Message;

class SendBroadcastRecipient implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 300];

    public function __construct(public int $recipientId) {}

    public function handle(EmailChannel $email, SmsChannel $sms, PushChannel $push): void
    {
        $recipient = BroadcastRecipient::query()->with(['person', 'broadcast.template', 'broadcast.channelConfiguration', 'broadcast.segment'])->find($this->recipientId);
        if (! $recipient || ! in_array($recipient->status, ['queued', 'sending'], true)) {
            return;
        }

        $broadcast = $recipient->broadcast;
        $person = $recipient->person;
        if ($person->isSuppressed($broadcast->channel)) {
            $recipient->update(['status' => 'skipped', 'error' => 'Person is suppressed for this channel.']);
            $this->completeIfFinished($broadcast);

            return;
        }

        $address = match ($broadcast->channel) {
            'email' => $person->email,
            'sms' => $person->phone,
            default => ($person->getAttribute('attributes') ?? [])['onesignal_external_id'] ?? $person->external_id,
        };
        if (blank($address)) {
            $recipient->update(['status' => 'skipped', 'error' => 'Person has no delivery destination.']);
            $this->completeIfFinished($broadcast);

            return;
        }

        $recipient->update(['status' => 'sending', 'error' => null]);
        $message = $recipient->message ?: Message::query()->create([
            'workspace_id' => $broadcast->workspace_id,
            'person_id' => $person->id,
            'template_id' => $broadcast->template_id,
            'channel' => $broadcast->channel,
            'to_address' => $address,
            'status' => 'queued',
        ]);
        $recipient->update(['message_id' => $message->id]);
        $context = ['person' => $person->toContext(), 'broadcast' => ['id' => $broadcast->id, 'name' => $broadcast->name], 'segment' => ['id' => $broadcast->segment->public_id, 'name' => $broadcast->segment->name]];
        $template = $broadcast->messageTemplate();
        $result = match ($broadcast->channel) {
            'sms' => $sms->send($broadcast->channelConfiguration, $template, $person, $context, null, $message),
            'push' => $push->send($broadcast->channelConfiguration, $template, $person, $context, null, $message),
            default => $email->send($broadcast->channelConfiguration, $template, $person, $context, null, $message),
        };

        $message->refresh();
        $recipient->update($result && $message->status === 'sent'
            ? ['status' => 'sent', 'sent_at' => now(), 'error' => null]
            : ['status' => 'failed', 'error' => $message->error ?? 'Delivery failed.']);
        $this->completeIfFinished($broadcast);
    }

    protected function completeIfFinished(Broadcast $broadcast): void
    {
        DB::transaction(function () use ($broadcast): void {
            $locked = Broadcast::query()->lockForUpdate()->find($broadcast->id);
            if ($locked && ! $locked->recipients()->whereIn('status', ['queued', 'sending'])->exists()) {
                $locked->update(['status' => 'completed', 'completed_at' => now()]);
            }
        });
    }

    public function failed(Throwable $exception): void
    {
        $recipient = BroadcastRecipient::query()->find($this->recipientId);
        if (! $recipient) {
            return;
        }

        $recipient->update(['status' => 'failed', 'error' => $exception->getMessage()]);
        $this->completeIfFinished($recipient->broadcast);
    }
}

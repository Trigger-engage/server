<?php

namespace TriggerEngage\Server\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use TriggerEngage\Server\Jobs\SendBroadcastRecipient;
use TriggerEngage\Server\Models\Broadcast;

class BroadcastSender
{
    /**
     * Queue a draft broadcast for every current member of its segment.
     *
     * $afterResponse holds the per-recipient dispatches until the response
     * has gone out. On a sync queue they otherwise run inside the request, and
     * a large audience keeps an API caller (an MCP client) waiting until it
     * times out mid-send.
     */
    public function send(Broadcast $broadcast, bool $afterResponse = false): int
    {
        $recipientIds = DB::transaction(function () use ($broadcast): array {
            $locked = Broadcast::query()->lockForUpdate()->findOrFail($broadcast->id);

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['broadcast' => 'This broadcast has already been sent.']);
            }

            $personIds = $locked->segment->people()->pluck('people.id');

            if ($personIds->isEmpty()) {
                throw ValidationException::withMessages(['broadcast' => 'The selected segment has no members.']);
            }

            $now = now();
            $locked->recipients()->insertOrIgnore($personIds->map(fn ($id) => ['broadcast_id' => $locked->id, 'person_id' => $id, 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now])->all());
            $locked->update(['status' => 'sending', 'started_at' => $now]);

            return $locked->recipients()->pluck('id')->all();
        });

        $this->dispatch($recipientIds, $afterResponse);

        return count($recipientIds);
    }

    /** Queue a finished broadcast's failed recipients again. */
    public function retryFailed(Broadcast $broadcast, bool $afterResponse = false): int
    {
        $recipientIds = DB::transaction(function () use ($broadcast): array {
            $locked = Broadcast::query()->lockForUpdate()->findOrFail($broadcast->id);
            if ($locked->status !== 'completed') {
                throw ValidationException::withMessages(['broadcast' => 'Wait for the current send to finish before retrying.']);
            }

            $ids = $locked->recipients()->where('status', 'failed')->pluck('id');
            if ($ids->isEmpty()) {
                throw ValidationException::withMessages(['broadcast' => 'There are no failed recipients to retry.']);
            }

            $locked->recipients()->whereKey($ids)->update(['status' => 'queued', 'error' => null]);
            $locked->update(['status' => 'sending', 'completed_at' => null]);

            return $ids->all();
        });

        $this->dispatch($recipientIds, $afterResponse);

        return count($recipientIds);
    }

    /** @param  array<int, int>  $recipientIds */
    protected function dispatch(array $recipientIds, bool $afterResponse): void
    {
        $send = function () use ($recipientIds): void {
            // On the sync queue a job that throws rethrows here after its
            // failed() handler already recorded the failure — swallow and keep
            // dispatching, or the first bad recipient strands every one after
            // it as 'queued' and the broadcast never leaves 'sending' (retry and
            // send both refuse that state, so there would be no way out).
            foreach ($recipientIds as $id) {
                try {
                    SendBroadcastRecipient::dispatch($id);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
        };

        $afterResponse ? app()->terminating($send) : $send();
    }
}

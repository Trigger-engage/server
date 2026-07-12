<?php

namespace TriggerEngage\Server\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use TriggerEngage\Server\Jobs\SendBroadcastRecipient;
use TriggerEngage\Server\Models\Broadcast;

class BroadcastSender
{
    public function send(Broadcast $broadcast): int
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

        foreach ($recipientIds as $id) {
            SendBroadcastRecipient::dispatch($id);
        }

        return count($recipientIds);
    }
}

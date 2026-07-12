<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\Message;

class UnsubscribeController extends Controller
{
    public function show(Request $request, Message $message): View
    {
        return view('trigger-engage::unsubscribe', compact('message'));
    }

    public function destroy(Request $request, Message $message): RedirectResponse
    {
        $message->person->suppressions()->updateOrCreate(
            ['workspace_id' => $message->workspace_id, 'channel' => $message->channel],
            ['reason' => 'unsubscribe']
        );

        return back()->with('unsubscribed', true);
    }
}

<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UnsubscribeController extends Controller
{
    public function show(Request $request, Message $message): View
    {
        return view('unsubscribe', compact('message'));
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

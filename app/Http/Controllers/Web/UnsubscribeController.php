<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use TriggerEngage\Server\Engine\EmailLayoutRenderer;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Services\Subscriptions;

/**
 * The page behind the unsubscribe link in every email: the only Trigger
 * Engage screen the people being messaged ever see. A message that belongs to
 * a topic unsubscribes from that topic by default; "stop everything" is the
 * second choice, and both can be undone from the confirmation.
 */
class UnsubscribeController extends Controller
{
    public function __construct(
        protected Subscriptions $subscriptions,
        protected EmailLayoutRenderer $layouts,
    ) {}

    public function show(Request $request, Message $message): View
    {
        return view('trigger-engage::preferences.unsubscribe', $this->viewData($message) + [
            'done' => session('unsubscribed'),
            'undone' => session('resubscribed'),
        ]);
    }

    public function destroy(Request $request, Message $message): RedirectResponse
    {
        $scope = $this->apply($message, $request->input('scope'), false, 'unsubscribe_link');

        return back()->with('unsubscribed', $scope);
    }

    public function undo(Request $request, Message $message): RedirectResponse
    {
        $scope = $this->apply($message, $request->input('scope'), true, 'unsubscribe_link');

        return redirect()->to(URL::temporarySignedRoute('unsubscribe.show', now()->addDay(), ['message' => $message->id]))
            ->with('resubscribed', $scope);
    }

    /**
     * RFC 8058 one-click unsubscribe, POSTed by the mailbox provider itself
     * (Gmail and Yahoo require it of bulk senders). No session, no CSRF, no
     * page: the signed URL is the authorization.
     */
    public function oneClick(Message $message): Response
    {
        $this->apply($message, null, false, 'one_click');

        return response('Unsubscribed.', 200, ['Content-Type' => 'text/plain']);
    }

    /** @return 'topic'|'channel' the scope that was applied */
    protected function apply(Message $message, ?string $scope, bool $subscribed, string $source): string
    {
        $topic = $message->subscription_topic_id ? $message->subscriptionTopic : null;
        $scope = $scope === 'channel' || ! $topic ? 'channel' : 'topic';

        if ($scope === 'topic') {
            $this->subscriptions->setTopic($message->person, $topic, $subscribed, $source);
        } else {
            $this->subscriptions->setChannel($message->person, $message->channel, $subscribed, $source);
        }

        return $scope;
    }

    /** @return array<string, mixed> */
    protected function viewData(Message $message): array
    {
        $message->loadMissing('person', 'template', 'subscriptionTopic', 'workspace');

        return [
            'message' => $message,
            'topic' => $message->subscriptionTopic,
            'workspace' => $message->workspace,
            'brand' => $this->layouts->normalizeSettings($message->template?->settings, $message->workspace),
            'channelNoun' => self::channelNoun($message->channel),
            'undoUrl' => URL::temporarySignedRoute('unsubscribe.undo', now()->addDay(), ['message' => $message->id]),
            'preferencesUrl' => URL::temporarySignedRoute('preferences.show', now()->addDays(30), ['person' => $message->person_id]),
        ];
    }

    public static function channelNoun(string $channel): string
    {
        return match ($channel) {
            'sms' => 'text messages',
            'push' => 'notifications',
            default => 'emails',
        };
    }
}

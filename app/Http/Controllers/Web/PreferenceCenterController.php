<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use TriggerEngage\Server\Engine\EmailLayoutRenderer;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\SubscriptionTopic;
use TriggerEngage\Server\Services\Subscriptions;

/**
 * A person's own preference page, reached from the unsubscribe page through
 * a signed link: every topic in the workspace, and every channel they can be
 * reached on.
 */
class PreferenceCenterController extends Controller
{
    public function __construct(
        protected Subscriptions $subscriptions,
        protected EmailLayoutRenderer $layouts,
    ) {}

    public function show(Request $request, Person $person): View
    {
        $person->loadMissing('workspace');

        return view('trigger-engage::preferences.center', [
            'person' => $person,
            'workspace' => $person->workspace,
            'state' => $this->subscriptions->state($person),
            'brand' => $this->layouts->brandFor($person->workspace),
            'saved' => session('preferences_saved'),
        ]);
    }

    public function update(Request $request, Person $person): RedirectResponse
    {
        $data = $request->validate([
            'topics' => ['nullable', 'array'],
            'topics.*' => ['string'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string'],
        ]);

        // Checkboxes: whatever is ticked is subscribed, whatever is shown and
        // not ticked is not. Only channels the page showed are touched.
        $ticked = collect($data['topics'] ?? []);
        SubscriptionTopic::query()->where('workspace_id', $person->workspace_id)->get()
            ->each(fn (SubscriptionTopic $topic) => $this->subscriptions->setTopic(
                $person, $topic, $ticked->contains($topic->key), 'preference_center'
            ));

        $state = $this->subscriptions->state($person);
        $tickedChannels = collect($data['channels'] ?? []);
        foreach ($state['channels'] as $channel => $current) {
            if (! $current['reachable'] || in_array($current['reason'], Subscriptions::PROTECTED_REASONS, true)) {
                continue;
            }
            $wanted = $tickedChannels->contains($channel);
            if ($wanted !== $current['subscribed']) {
                $this->subscriptions->setChannel($person, $channel, $wanted, 'preference_center');
            }
        }

        return back()->with('preferences_saved', true);
    }
}

<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\SubscriptionTopic;
use TriggerEngage\Server\Services\Subscriptions;

class SubscriptionTopicController extends Controller
{
    public function __construct(protected Subscriptions $subscriptions) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Subscriptions/Index', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'topics' => $workspace->subscriptionTopics()->orderBy('name')->get()
                ->map(fn (SubscriptionTopic $topic) => [
                    ...$topic->only('id', 'key', 'name', 'description', 'default_subscribed', 'property'),
                    // For an opt-in topic this counts everyone who has not opted in.
                    'unsubscribed_count' => $this->subscriptions
                        ->whereUnsubscribedFrom(Person::query()->where('workspace_id', $workspace->id), $topic)->count(),
                    'templates' => $workspace->templates()->where('subscription_topic_id', $topic->id)->orderBy('name')->get(['id', 'name', 'channel']),
                ]),
            'people_count' => $workspace->people()->count(),
            'unsubscribed_channels' => collect(Subscriptions::CHANNELS)->mapWithKeys(fn (string $channel) => [
                $channel => $workspace->people()->where(fn ($query) => $query->whereNotNull('unsubscribed_at')
                    ->orWhereHas('suppressions', fn ($rows) => $rows->whereIn('channel', [$channel, 'all'])))->count(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $data = $this->validated($request, $workspace->id);
        $workspace->subscriptionTopics()->create($data);

        return back()->with('success', "Topic “{$data['name']}” created. Pick it on the templates and broadcasts that belong to it.");
    }

    public function update(Request $request, SubscriptionTopic $topic): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($topic->workspace_id === $workspace->id, 404);
        $topic->update($this->validated($request, $workspace->id, $topic));

        return back()->with('success', 'Topic saved.');
    }

    public function destroy(Request $request, SubscriptionTopic $topic): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($topic->workspace_id === $workspace->id, 404);
        $topic->delete();

        return back()->with('success', "Topic “{$topic->name}” deleted. Its templates and broadcasts no longer belong to a topic.");
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, int $workspaceId, ?SubscriptionTopic $topic = null): array
    {
        $request->merge(['key' => Str::slug((string) ($request->input('key') ?: $request->input('name')), '_')]);

        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'key' => [
                'required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('engage_subscription_topics', 'key')->where('workspace_id', $workspaceId)->ignore($topic?->id),
            ],
            'description' => ['nullable', 'string', 'max:300'],
            'default_subscribed' => ['required', 'boolean'],
            'property' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_]+$/'],
        ], [
            'property.regex' => 'Use the property name exactly as it is stored, with letters, numbers and underscores only.',
        ]);
    }
}

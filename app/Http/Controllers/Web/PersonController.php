<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Services\Ingest;
use TriggerEngage\Server\Services\Subscriptions;

class PersonController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $search = trim($request->string('search')->toString());
        $people = Person::query()->where('workspace_id', $workspace->id)
            ->when($search, fn ($query) => $query->where(function ($nested) use ($search) {
                $nested->where('external_id', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            }))
            ->withCount(['segments', 'occurrences'])
            ->latest('updated_at')->paginate(25)->withQueryString()
            ->through(fn (Person $person) => [
                ...$person->only('id', 'external_id', 'email', 'phone', 'updated_at'),
                'properties' => $person->properties(),
                'segments_count' => $person->segments_count,
                'occurrences_count' => $person->occurrences_count,
            ]);

        return Inertia::render('People/Index', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'people' => $people,
            'filters' => ['search' => $search],
        ]);
    }

    public function store(Request $request, Ingest $ingest): RedirectResponse
    {
        $data = $request->validate([
            'external_id' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ]);
        $workspace = $request->attributes->get('workspace');
        $person = $ingest->identify($workspace, $data['external_id'], $data);

        return redirect()->route('engage.people.show', $person)->with('success', 'Person profile created. Add properties to complete it.');
    }

    public function show(Request $request, Person $person): Response
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureOwned($workspace->id, $person);
        $person->loadCount(['segments', 'occurrences', 'automationRuns', 'messages']);

        return Inertia::render('People/Show', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'person' => [
                ...$person->only('id', 'external_id', 'email', 'phone', 'created_at', 'updated_at', 'unsubscribed_at'),
                'properties' => $person->properties(),
                'counts' => [
                    'segments' => $person->segments_count,
                    'events' => $person->occurrences_count,
                    'runs' => $person->automation_runs_count,
                    'messages' => $person->messages_count,
                ],
            ],
            'segments' => $person->segments()->orderBy('name')->get(['segments.id', 'public_id', 'name', 'type']),
            'recentEvents' => $person->occurrences()->with('event:id,name')->latest('occurred_at')->limit(10)->get(['id', 'event_id', 'payload', 'occurred_at']),
            'subscriptions' => app(Subscriptions::class)->state($person),
        ]);
    }

    /** One switch on the person's Subscriptions panel: a topic or a whole channel. */
    public function updateSubscription(Request $request, Person $person, Subscriptions $subscriptions): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureOwned($workspace->id, $person);
        $data = $request->validate([
            'scope' => ['required', Rule::in(['topic', 'channel'])],
            'target' => ['required', 'string'],
            'subscribed' => ['required', 'boolean'],
        ]);

        if ($data['scope'] === 'channel') {
            abort_unless(in_array($data['target'], Subscriptions::CHANNELS, true), 422);
            $subscriptions->setChannel($person, $data['target'], (bool) $data['subscribed'], 'dashboard');
        } else {
            $topic = $workspace->subscriptionTopics()->where('key', $data['target'])->firstOrFail();
            $subscriptions->setTopic($person, $topic, (bool) $data['subscribed'], 'dashboard');
        }

        return back()->with('success', $data['subscribed'] ? 'Subscribed again.' : 'Unsubscribed.');
    }

    public function update(Request $request, Person $person, Ingest $ingest): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureOwned($workspace->id, $person);
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'properties' => ['present', 'array', 'max:200'],
        ]);
        $person->update(['email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null]);
        $ingest->replaceProperties($workspace, $person, $data['properties']);

        return back()->with('success', 'Person properties saved.');
    }

    protected function ensureOwned(int $workspaceId, Person $person): void
    {
        abort_unless($person->workspace_id === $workspaceId, 404);
    }
}

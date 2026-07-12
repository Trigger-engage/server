<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Engine\SegmentManager;
use TriggerEngage\Server\Engine\SegmentRuleQuery;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;

class SegmentController extends Controller
{
    public function __construct(protected SegmentManager $segments) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Segments/Index', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'events' => $workspace->events()->orderBy('name')->get(['id', 'name']),
            'operators' => SegmentRuleQuery::OPERATORS,
            'segments' => $workspace->segments()
                ->with('event:id,name')
                ->withCount('people')
                ->orderByRaw('case when type = ? then 0 else 1 end', [Segment::TYPE_ALL])
                ->latest('created_at')
                ->get(['id', 'public_id', 'name', 'type', 'description', 'event_id', 'rules', 'recomputed_at', 'created_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('segments')->where('workspace_id', $workspace->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', Rule::in([Segment::TYPE_MANUAL, Segment::TYPE_EVENT, Segment::TYPE_RULE])],
            'event_id' => ['required_if:type,event', 'nullable', Rule::exists('events', 'id')->where('workspace_id', $workspace->id)],
        ]);

        $data['event_id'] = $data['type'] === Segment::TYPE_EVENT ? $data['event_id'] : null;
        $data['rules'] = $data['type'] === Segment::TYPE_RULE ? $this->validatedRules($request, $workspace->id) : null;

        $segment = $workspace->segments()->create($data);

        if ($segment->isRuleBased()) {
            $this->segments->recompute($segment);
        }

        return back()->with('success', 'Segment created.');
    }

    public function show(Request $request, Segment $segment): Response
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureOwned($workspace->id, $segment);
        $search = trim($request->string('search')->toString());
        $addSearch = trim($request->string('add_search')->toString());

        $members = $segment->people()
            ->when($search, fn ($query) => $this->searchPeople($query, $search))
            ->orderByRaw('external_id is null')
            ->orderBy('external_id')
            ->paginate(25, ['people.id', 'external_id', 'anonymous_id', 'email', 'phone', 'attributes', 'segment_person.source', 'segment_person.added_at'])
            ->withQueryString();

        $available = collect();
        if ($segment->type === Segment::TYPE_MANUAL) {
            $available = Person::query()
                ->where('workspace_id', $workspace->id)
                ->whereDoesntHave('segments', fn ($query) => $query->where('segments.id', $segment->id))
                ->when($addSearch, fn ($query) => $this->searchPeople($query, $addSearch))
                ->orderByRaw('external_id is null')
                ->orderBy('external_id')
                ->limit(20)
                ->get(['id', 'external_id', 'anonymous_id', 'email', 'phone']);
        }

        return Inertia::render('Segments/Show', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'segment' => [
                ...$segment->only('id', 'public_id', 'name', 'type', 'description', 'rules', 'recomputed_at', 'created_at'),
                'event' => $segment->event?->only('id', 'name'),
                'people_count' => $segment->people()->count(),
                'broadcasts_count' => $segment->broadcasts()->count(),
                'editable_membership' => $segment->type === Segment::TYPE_MANUAL,
                'protected' => $segment->isAllPeople(),
            ],
            'members' => $members,
            'availablePeople' => $available,
            'filters' => ['search' => $search, 'add_search' => $addSearch],
        ]);
    }

    public function update(Request $request, Segment $segment): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureOwned($workspace->id, $segment);
        abort_if($segment->isAllPeople(), 422, 'The default All people segment cannot be changed.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('segments')->where('workspace_id', $workspace->id)->ignore($segment->id)],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $rulesChanged = $request->has('rules');
        if ($rulesChanged) {
            abort_unless($segment->isRuleBased(), 422, 'Only rule-based segments accept membership rules.');
            $data['rules'] = $this->validatedRules($request, $workspace->id);
        }

        $segment->update($data);
        if ($rulesChanged) {
            $this->segments->recompute($segment->refresh());
        }

        return back()->with('success', $rulesChanged
            ? 'Segment rules updated and membership recomputed.'
            : 'Segment details updated.');
    }

    public function destroy(Request $request, Segment $segment): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureOwned($workspace->id, $segment);
        abort_if($segment->isAllPeople(), 422, 'The default All people segment cannot be deleted.');

        if ($segment->broadcasts()->exists()) {
            throw ValidationException::withMessages([
                'segment' => 'This segment is used by broadcast history and cannot be deleted.',
            ]);
        }

        $segment->delete();

        return redirect()->route('engage.segments.index')->with('success', 'Segment deleted.');
    }

    public function addPerson(Request $request, Segment $segment, Person $person): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureOwned($workspace->id, $segment);
        $this->ensurePersonOwned($workspace->id, $person);
        abort_unless($segment->type === Segment::TYPE_MANUAL, 422, 'Membership is computed automatically for this segment.');

        $segment->people()->syncWithoutDetaching([
            $person->id => ['source' => 'api', 'added_at' => now()],
        ]);

        return back()->with('success', 'Person added to segment.');
    }

    public function removePerson(Request $request, Segment $segment, Person $person): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureOwned($workspace->id, $segment);
        $this->ensurePersonOwned($workspace->id, $person);
        abort_unless($segment->type === Segment::TYPE_MANUAL, 422, 'Membership is computed automatically for this segment.');

        $segment->people()->detach($person->id);

        return back()->with('success', 'Person removed from segment.');
    }

    protected function ensureOwned(int $workspaceId, Segment $segment): void
    {
        abort_unless($segment->workspace_id === $workspaceId, 404);
    }

    protected function ensurePersonOwned(int $workspaceId, Person $person): void
    {
        abort_unless($person->workspace_id === $workspaceId, 404);
    }

    protected function searchPeople($query, string $search)
    {
        return $query->where(function ($nested) use ($search): void {
            $nested->where('external_id', 'like', "%{$search}%")
                ->orWhere('anonymous_id', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%");
        });
    }

    /**
     * Validate the boolean rule group and normalize it to the stored shape.
     *
     * @return array{match: string, conditions: array<int, array<string, mixed>>}
     */
    protected function validatedRules(Request $request, int $workspaceId): array
    {
        $validated = $request->validate([
            'rules.match' => ['required', Rule::in(['all', 'any'])],
            'rules.conditions' => ['required', 'array', 'min:1', 'max:20'],
            'rules.conditions.*.kind' => ['required', Rule::in(['attribute', 'event'])],
            'rules.conditions.*.field' => ['required_if:rules.conditions.*.kind,attribute', 'nullable', 'string', 'max:150'],
            'rules.conditions.*.operator' => ['required_if:rules.conditions.*.kind,attribute', 'nullable', Rule::in(SegmentRuleQuery::OPERATORS)],
            'rules.conditions.*.value' => ['nullable'],
            'rules.conditions.*.event_id' => ['required_if:rules.conditions.*.kind,event', 'nullable', Rule::exists('events', 'id')->where('workspace_id', $workspaceId)],
            'rules.conditions.*.performed' => ['nullable', 'boolean'],
            'rules.conditions.*.within_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]);

        $conditions = collect($validated['rules']['conditions'])->map(function (array $condition): array {
            if (($condition['kind'] ?? 'attribute') === 'event') {
                return [
                    'kind' => 'event',
                    'event_id' => (int) $condition['event_id'],
                    'performed' => (bool) ($condition['performed'] ?? true),
                    'within_days' => (int) ($condition['within_days'] ?? 0),
                ];
            }

            $operator = $condition['operator'] ?? 'equals';
            $needsValue = ! in_array($operator, ['exists', 'not_exists'], true);

            if ($needsValue && ($condition['value'] ?? null) === null) {
                throw ValidationException::withMessages(['rules' => 'Attribute conditions need a value unless the operator is exists / not exists.']);
            }

            return [
                'kind' => 'attribute',
                'field' => $condition['field'],
                'operator' => $operator,
                'value' => $needsValue ? $condition['value'] : null,
            ];
        })->all();

        return ['match' => $validated['rules']['match'], 'conditions' => $conditions];
    }
}

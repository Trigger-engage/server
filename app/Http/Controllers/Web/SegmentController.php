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
                ->latest()
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

    public function update(Request $request, Segment $segment): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($segment->workspace_id === $workspace->id, 404);
        abort_unless($segment->isRuleBased(), 422, 'Only rule-based segments can be edited.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('segments')->where('workspace_id', $workspace->id)->ignore($segment->id)],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $data['rules'] = $this->validatedRules($request, $workspace->id);

        $segment->update($data);
        $this->segments->recompute($segment->refresh());

        return back()->with('success', 'Segment rules updated and membership recomputed.');
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

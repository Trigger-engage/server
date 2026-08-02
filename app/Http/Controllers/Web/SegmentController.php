<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TriggerEngage\Server\Engine\SegmentManager;
use TriggerEngage\Server\Engine\SegmentRuleQuery;
use TriggerEngage\Server\Engine\SegmentRuleValidator;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;

class SegmentController extends Controller
{
    public function __construct(
        protected SegmentManager $segments,
        protected SegmentRuleQuery $ruleQuery,
        protected SegmentRuleValidator $ruleValidator,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Segments/Index', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'events' => $workspace->events()->orderBy('name')->get(['id', 'name']),
            'operators' => SegmentRuleQuery::OPERATORS,
            'countOperators' => SegmentRuleQuery::COUNT_OPERATORS,
            'maxDepth' => SegmentRuleQuery::MAX_DEPTH,
            'segmentOptions' => $workspace->segments()->orderBy('name')->get(['id', 'name', 'type']),
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
        $data['rules'] = $data['type'] === Segment::TYPE_RULE
            ? $this->ruleValidator->validate((array) $request->input('rules', []), $workspace->id)
            : null;

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
            $data['rules'] = $this->ruleValidator->validate((array) $request->input('rules', []), $workspace->id, $segment->id);
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

        $this->segments->addMember($segment, $person);

        return back()->with('success', 'Person added to segment.');
    }

    public function removePerson(Request $request, Segment $segment, Person $person): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureOwned($workspace->id, $segment);
        $this->ensurePersonOwned($workspace->id, $person);
        abort_unless($segment->type === Segment::TYPE_MANUAL, 422, 'Membership is computed automatically for this segment.');

        $this->segments->removeMember($segment, $person);

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
     * Estimate the audience a rule group would match, without saving anything.
     * Powers the builder's live "~N people match" preview.
     */
    public function preview(Request $request)
    {
        $workspace = $request->attributes->get('workspace');

        $selfId = $request->integer('segment_id') ?: null;
        $rules = $this->ruleValidator->validate((array) $request->input('rules', []), $workspace->id, $selfId);

        $query = $this->ruleQuery->forWorkspace($workspace->id, $rules);

        return response()->json([
            'count' => (clone $query)->count(),
            'sample' => $query
                ->orderByRaw('external_id is null')
                ->orderBy('external_id')
                ->limit(5)
                ->get(['id', 'external_id', 'anonymous_id', 'email'])
                ->map(fn (Person $person) => [
                    'id' => $person->id,
                    'label' => $person->external_id ?? $person->email ?? $person->anonymous_id,
                ]),
        ]);
    }

    public function duplicate(Request $request, Segment $segment): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureOwned($workspace->id, $segment);
        abort_if($segment->isAllPeople(), 422, 'The default All people segment cannot be duplicated.');

        $name = $segment->name.' (copy)';
        $suffix = 2;
        while ($workspace->segments()->where('name', $name)->exists()) {
            $name = $segment->name.' (copy '.$suffix++.')';
        }

        $copy = $workspace->segments()->create([
            'name' => $name,
            'description' => $segment->description,
            'type' => $segment->type,
            'event_id' => $segment->event_id,
            'rules' => $segment->rules,
        ]);

        if ($copy->isRuleBased()) {
            $this->segments->recompute($copy);
        } elseif ($segment->type === Segment::TYPE_MANUAL) {
            // A manual duplicate starts from the same audience snapshot.
            $segment->people()
                ->pluck('people.id')
                ->chunk(500)
                ->each(function ($chunk) use ($copy): void {
                    DB::table('segment_person')->insertOrIgnore(
                        $chunk->map(fn ($id) => [
                            'segment_id' => $copy->id,
                            'person_id' => $id,
                            'source' => 'api',
                            'added_at' => now(),
                        ])->all()
                    );
                });
        }

        return redirect()
            ->route('engage.segments.show', $copy)
            ->with('success', 'Segment duplicated.');
    }

    /** Stream the current member list as CSV. */
    public function export(Request $request, Segment $segment): StreamedResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureOwned($workspace->id, $segment);

        $filename = str($segment->name)->slug()->append('-members.csv')->toString();

        return response()->streamDownload(function () use ($segment): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['external_id', 'anonymous_id', 'email', 'phone', 'source', 'added_at']);

            $segment->people()
                ->orderBy('people.id')
                ->chunk(500, function ($people) use ($out): void {
                    foreach ($people as $person) {
                        fputcsv($out, [
                            $person->external_id,
                            $person->anonymous_id,
                            $person->email,
                            $person->phone,
                            $person->pivot->source,
                            $person->pivot->added_at,
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Import people into a manual segment from a CSV with an external_id and/or
     * email column. Rows match existing workspace profiles; optionally, missing
     * people are created so a list upload can seed a brand-new audience.
     */
    public function import(Request $request, Segment $segment): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureOwned($workspace->id, $segment);
        abort_unless($segment->type === Segment::TYPE_MANUAL, 422, 'Only manual segments accept CSV imports.');

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'create_missing' => ['nullable', 'boolean'],
        ]);

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $header = array_map(fn ($col) => strtolower(trim((string) $col)), fgetcsv($handle) ?: []);

        if (! in_array('external_id', $header, true) && ! in_array('email', $header, true)) {
            fclose($handle);
            throw ValidationException::withMessages([
                'file' => 'The CSV needs a header row with an external_id or email column.',
            ]);
        }

        $createMissing = $request->boolean('create_missing');
        $added = $existing = $created = $skipped = 0;
        $rows = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (++$rows > 5000) {
                fclose($handle);
                throw ValidationException::withMessages([
                    'file' => 'Imports are limited to 5000 rows per file — split larger lists.',
                ]);
            }

            $data = array_combine($header, array_pad(array_map('trim', $row), count($header), ''));
            $externalId = $data['external_id'] ?? '';
            $email = $data['email'] ?? '';

            if ($externalId === '' && $email === '') {
                $skipped++;

                continue;
            }

            $person = Person::query()
                ->where('workspace_id', $workspace->id)
                ->when($externalId !== '', fn ($q) => $q->where('external_id', $externalId))
                ->when($externalId === '', fn ($q) => $q->where('email', $email))
                ->first();

            if (! $person && $createMissing && $externalId !== '') {
                $person = Person::query()->create([
                    'workspace_id' => $workspace->id,
                    'external_id' => $externalId,
                    'email' => $email !== '' ? $email : null,
                    'phone' => ($data['phone'] ?? '') !== '' ? $data['phone'] : null,
                    'attributes' => [],
                ]);
                $created++;
            }

            if (! $person) {
                $skipped++;

                continue;
            }

            $this->segments->addMember($segment, $person, 'import') ? $added++ : $existing++;
        }

        fclose($handle);

        return back()->with('success', sprintf(
            'Import finished: %d added, %d already members, %d created, %d skipped.',
            $added, $existing, $created, $skipped
        ));
    }
}

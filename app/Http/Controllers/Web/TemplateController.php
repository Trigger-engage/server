<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Engine\EmailLayoutRenderer;
use TriggerEngage\Server\Engine\TemplateRenderer;
use TriggerEngage\Server\Http\Controllers\Concerns\EditsMessageContent;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\Template;

class TemplateController extends Controller
{
    use EditsMessageContent;

    public function __construct(
        protected EmailLayoutRenderer $layouts,
        protected TemplateRenderer $renderer,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Templates/Index', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'templates' => $workspace->templates()
                ->orderBy('name')
                ->get(['id', 'channel', 'name', 'subject', 'layout', 'updated_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->validateLiquid($data);
        $template = $request->attributes->get('workspace')->templates()->create($this->normalizedContent($data));

        return redirect()->route('engage.templates.edit', $template)
            ->with('success', 'Template created. Customize the design and preview it before using it.');
    }

    public function edit(Request $request, Template $template): Response
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureWorkspaceOwns($workspace->id, $template);
        $template->settings = $this->layouts->normalizeSettings($template->settings);

        return Inertia::render('Templates/Edit', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'template' => $template->only([
                'id', 'channel', 'name', 'subject', 'body', 'layout', 'preheader',
                'settings', 'from_name', 'from_address', 'updated_at',
            ]),
            'preview' => $this->renderPreview($template),
            'defaultSettings' => $this->layouts->defaultSettings(),
        ]);
    }

    public function update(Request $request, Template $template): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureWorkspaceOwns($workspace->id, $template);
        $data = $this->validated($request);
        $data['channel'] = $template->channel;
        $this->validateLiquid($data);
        $template->update($this->normalizedContent($data));

        return back()->with('success', 'Template saved. Future sends will use this version.');
    }

    public function preview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->contentRules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The template preview could not be rendered.',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            $template = new Template($this->normalizedContent($data));
            $preview = $this->renderPreview($template);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'body' => 'The Liquid template could not be rendered: '.$exception->getMessage(),
            ]);
        }

        return response()->json($preview);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        return $request->validate($this->contentRules());
    }

    protected function ensureWorkspaceOwns(int $workspaceId, Template $template): void
    {
        abort_unless($template->workspace_id === $workspaceId, 404);
    }
}

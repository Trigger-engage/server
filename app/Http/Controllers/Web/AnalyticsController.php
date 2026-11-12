<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Services\Analytics;

class AnalyticsController extends Controller
{
    public function __invoke(Request $request, Analytics $analytics): Response
    {
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Analytics', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            ...$analytics->report($workspace, $request->integer('days')),
            'ranges' => Analytics::RANGES,
        ]);
    }
}

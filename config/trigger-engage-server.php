<?php

$mode = env('TRIGGER_ENGAGE_MODE', defined('TRIGGER_ENGAGE_STANDALONE') ? 'self-hosted' : 'embedded');
$embedded = $mode === 'embedded';

return [
    'mode' => $mode,

    'workspace_id' => env('TRIGGER_ENGAGE_EMBEDDED_WORKSPACE_ID'),

    // Define this Gate in your host application's AppServiceProvider to use
    // role- or team-based access. Authenticated users are allowed by default.
    'authorization_gate' => env('TRIGGER_ENGAGE_AUTHORIZATION_GATE', 'viewTriggerEngage'),

    'routes' => [
        'management_prefix' => trim((string) env('TRIGGER_ENGAGE_UI_PREFIX', $embedded ? 'trigger-engage' : 'app'), '/'),
        'api_prefix' => trim((string) env('TRIGGER_ENGAGE_API_PREFIX', $embedded ? 'trigger-engage/api/v1' : 'api/v1'), '/'),
        'unsubscribe_prefix' => trim((string) env('TRIGGER_ENGAGE_PUBLIC_PREFIX', $embedded ? 'trigger-engage' : ''), '/'),
        'management_middleware' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'TRIGGER_ENGAGE_UI_MIDDLEWARE',
            $embedded ? 'web,trigger-engage.authorize' : 'web',
        ))))),
    ],

    'assets_build_directory' => env('TRIGGER_ENGAGE_ASSET_DIRECTORY', $embedded ? 'vendor/trigger-engage/build' : 'build'),

    // A run is `running` only while a worker walks it. One still `running`
    // long after that lost its advance job, and engage:tick picks it up again:
    // after `after_minutes` idle it is re-dispatched (up to `per_tick` a
    // minute), and once idle for `give_up_after_hours` it is failed instead,
    // because its next message would arrive too late to make sense.
    'stalled_runs' => [
        'after_minutes' => (int) env('TRIGGER_ENGAGE_STALLED_RUN_AFTER_MINUTES', 15),
        'give_up_after_hours' => (int) env('TRIGGER_ENGAGE_STALLED_RUN_GIVE_UP_HOURS', 72),
        'per_tick' => (int) env('TRIGGER_ENGAGE_STALLED_RUNS_PER_TICK', 500),
    ],
];

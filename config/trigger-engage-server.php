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
];

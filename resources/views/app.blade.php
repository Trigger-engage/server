<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'Trigger Engage') }}</title>
    @if(config('trigger-engage-server.mode') === 'embedded')
        @vite(['resources/css/app.css', 'resources/js/app.jsx'], config('trigger-engage-server.assets_build_directory'))
    @else
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @endif
    <meta name="trigger-engage-base" content="/{{ config('trigger-engage-server.routes.management_prefix') }}">
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>

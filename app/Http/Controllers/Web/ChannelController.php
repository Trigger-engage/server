<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChannelController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:email,sms,push'],
            'name' => ['required', 'string', 'max:150'],
            'driver' => ['required', 'in:log,smtp,termii,onesignal'],
            'is_default' => ['boolean'],
            'host' => ['required_if:driver,smtp', 'nullable', 'string', 'max:255'],
            'port' => ['required_if:driver,smtp', 'nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['nullable', 'in:tls,ssl'],
            'base_url' => ['nullable', 'url'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'secret_key' => ['nullable', 'string', 'max:500'],
            'sender_id' => ['nullable', 'string', 'max:20'],
            'route' => ['nullable', 'in:dnd,generic'],
            'app_id' => ['nullable', 'string', 'max:150'],
            'webhook_token' => ['nullable', 'string', 'min:24', 'max:500'],
        ]);
        $workspace = $request->attributes->get('workspace');

        abort_if($validated['driver'] === 'smtp' && $validated['type'] !== 'email', 422);
        abort_if($validated['driver'] === 'termii' && $validated['type'] !== 'sms', 422);
        abort_if($validated['driver'] === 'onesignal' && $validated['type'] !== 'push', 422);

        DB::transaction(function () use ($workspace, $validated): void {
            if ($validated['is_default'] ?? false) {
                $workspace->channels()->where('type', $validated['type'])->update(['is_default' => false]);
            }

            $workspace->channels()->create([
                'type' => $validated['type'],
                'driver' => $validated['driver'],
                'name' => $validated['name'],
                'is_default' => $validated['is_default'] ?? false,
                'credentials' => match ($validated['driver']) {
                    'smtp' => [
                        'host' => $validated['host'],
                        'port' => $validated['port'],
                        'username' => $validated['username'] ?? null,
                        'password' => $validated['password'] ?? null,
                        'encryption' => $validated['encryption'] ?? 'tls',
                    ],
                    'termii' => [
                        'base_url' => $validated['base_url'] ?? null,
                        'api_key' => $validated['api_key'] ?? null,
                        'secret_key' => $validated['secret_key'] ?? null,
                        'sender_id' => $validated['sender_id'] ?? null,
                        'route' => $validated['route'] ?? 'dnd',
                    ],
                    'onesignal' => [
                        'app_id' => $validated['app_id'] ?? null,
                        'api_key' => $validated['api_key'] ?? null,
                        'webhook_token' => $validated['webhook_token'] ?? null,
                    ],
                    default => null,
                },
            ]);
        });

        return back()->with('success', ucfirst($validated['type']).' channel created.');
    }
}

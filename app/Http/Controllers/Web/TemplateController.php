<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'channel' => ['required', 'in:email,sms,push'],
            'name' => ['required', 'string', 'max:150'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'from_name' => ['nullable', 'string', 'max:150'],
            'from_address' => ['nullable', 'email'],
        ]);

        $request->attributes->get('workspace')->templates()->create($validated);

        return back()->with('success', 'Email template created.');
    }
}

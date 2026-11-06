@extends('trigger-engage::preferences.layout')

@php
    use TriggerEngage\Server\Http\Controllers\Web\UnsubscribeController;
    use TriggerEngage\Server\Services\Subscriptions;

    $channelLabels = ['email' => 'Emails', 'sms' => 'Text messages', 'push' => 'App notifications'];
    $addresses = ['email' => $person->email, 'sms' => $person->phone, 'push' => null];
    $reachable = collect($state['channels'])->filter(fn ($channel) => $channel['reachable']);
@endphp

@section('title', 'Your preferences')

@section('content')
    @if ($saved)
        <p class="status" role="status">Your preferences are saved.</p>
    @endif
    <h1>What would you like to hear about?</h1>
    <p>Choose what {{ $workspace->name }} sends you. You can come back and change this any time.</p>

    <form method="post" action="{{ request()->fullUrl() }}">
        @csrf
        @if (count($state['topics']))
            <h2 class="section-title">Topics</h2>
            @foreach ($state['topics'] as $topic)
                <label class="option">
                    <input type="checkbox" name="topics[]" value="{{ $topic['key'] }}" @checked($topic['subscribed'])>
                    <span>
                        <strong>{{ $topic['name'] }}</strong>
                        @if ($topic['description'])<span class="muted">{{ $topic['description'] }}</span>@endif
                    </span>
                </label>
            @endforeach
        @endif

        @if ($reachable->isNotEmpty())
            <h2 class="section-title">How we reach you</h2>
            @foreach ($reachable as $channel => $current)
                @php($protected = in_array($current['reason'], Subscriptions::PROTECTED_REASONS, true))
                <label class="option">
                    <input type="checkbox" name="channels[]" value="{{ $channel }}" @checked($current['subscribed']) @disabled($protected)>
                    <span>
                        <strong>{{ $channelLabels[$channel] }}</strong>
                        <span class="muted">
                            @if ($protected)
                                We can’t deliver to {{ $addresses[$channel] }}. Contact us to change it.
                            @elseif ($addresses[$channel])
                                Any {{ UnsubscribeController::channelNoun($channel) }} at {{ $addresses[$channel] }}. Untick to stop them all.
                            @else
                                Any {{ UnsubscribeController::channelNoun($channel) }} from our app. Untick to stop them all.
                            @endif
                        </span>
                    </span>
                </label>
            @endforeach
        @endif

        <div class="stack" style="margin-top: 24px">
            <button class="button" type="submit">Save my preferences</button>
        </div>
    </form>
@endsection

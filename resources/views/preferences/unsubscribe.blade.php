@extends('trigger-engage::preferences.layout')

@php
    $topicLabel = $topic ? $topic->name : null;
@endphp

@section('title', $done ? 'You’re unsubscribed' : 'Unsubscribe')

@section('content')
    @if ($done)
        <h1>You’re unsubscribed</h1>
        @if ($done === 'topic')
            <p>You won’t get “{{ $topicLabel }}” {{ $channelNoun }} from {{ $workspace->name }} any more. Other {{ $channelNoun }} from us aren’t affected.</p>
        @else
            <p>You won’t get {{ $channelNoun }} from {{ $workspace->name }} any more at {{ $message->to_address }}.</p>
        @endif
        <p class="muted">It can take a few minutes for messages that were already on their way to stop.</p>
        <div class="stack">
            <form method="post" action="{{ $undoUrl }}">
                @csrf
                <input type="hidden" name="scope" value="{{ $done }}">
                <button class="link" type="submit">I changed my mind, keep sending them</button>
            </form>
            <p><a class="link" href="{{ $preferencesUrl }}">Choose what you get from us</a></p>
        </div>
    @else
        @if ($undone)
            <p class="status" role="status">You’re subscribed again.</p>
        @endif
        @if ($topic)
            <h1>Stop “{{ $topicLabel }}” {{ $channelNoun }}?</h1>
            <p>We’ll stop sending “{{ $topicLabel }}” {{ $channelNoun }} to {{ $message->to_address }}. You’ll still get other {{ $channelNoun }} from {{ $workspace->name }}.</p>
        @else
            <h1>Stop {{ $channelNoun }} from {{ $workspace->name }}?</h1>
            <p>We’ll stop sending {{ $channelNoun }} to {{ $message->to_address }}.</p>
        @endif
        <div class="stack">
            <form method="post" action="{{ request()->fullUrl() }}">
                @csrf
                <input type="hidden" name="scope" value="{{ $topic ? 'topic' : 'channel' }}">
                <button class="button" type="submit">Unsubscribe</button>
            </form>
            @if ($topic)
                <form method="post" action="{{ request()->fullUrl() }}">
                    @csrf
                    <input type="hidden" name="scope" value="channel">
                    <button class="link" type="submit">Stop all {{ $channelNoun }} from {{ $workspace->name }} instead</button>
                </form>
            @endif
            <p><a class="link" href="{{ $preferencesUrl }}">Choose what you get from us</a></p>
        </div>
    @endif
@endsection

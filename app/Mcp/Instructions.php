<?php

namespace TriggerEngage\Server\Mcp;

/**
 * The server instructions a client hands its model on connect: the shape of
 * Trigger Engage, how the tools fit together, and the rules for reading them.
 */
class Instructions
{
    public static function text(): string
    {
        return implode(' ', [
            'Trigger Engage is a messaging automation platform: the host app sends events and profile attributes',
            'about people; segments group people; automations (journeys) start on an event and walk a graph of',
            'delays, branches, waits and sends; broadcasts send one message to a segment; every email, SMS and push',
            'lands in the messages log. Reading tools change nothing. If your credentials may write, drafting tools',
            'appear too: they create templates, segments, broadcast drafts and journey drafts, and send tests to your own',
            'address, but nothing they make reaches anyone until a person publishes or sends it from the dashboard.',
            'Templates a live journey sends, and segments a live journey uses, are refused: copy them instead. Every',
            'change is recorded with your credentials. Write copy a person would sign off: clear, kind, no invented facts.',
            'Start with get_workspace to see what is set up, then list_events and list_attributes before reasoning',
            'about segment rules or template variables. To answer "why did (or didn\'t) this person get that',
            'message?", call diagnose_delivery with the person and the automation or broadcast; get_run and',
            'get_message show the detail behind its answer. Templates use Liquid with {{ person.* }} and',
            '{{ event.* }}; render_template shows the result and any missing variables. Identify people by',
            'external_id (the host app\'s id, e.g. "user-123") where you can. Email addresses and phone numbers',
            'are masked unless the operator turned that off, push tokens and signed links are always redacted, and',
            'you may look a person up by a full email even when results mask it. Times are ISO 8601 in the',
            'workspace\'s timezone. Lists return a page at a time: pass next_page as page to continue. Each',
            'detail result carries a dashboard url a human can open.',
        ]);
    }
}

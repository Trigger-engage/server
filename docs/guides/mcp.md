# MCP server for AI agents

Trigger Engage ships a [Model Context Protocol](https://modelcontextprotocol.io) server. With it,
an AI agent (Claude Code, Claude Desktop, Cursor or any other MCP client) can read your workspace:
people, events, segments, templates, journeys, runs, messages and broadcasts. It can also answer
the support question that eats the most time: *why didn't this person get that message?*

By default it only reads. If the operator switches drafting on, an agent can also write templates,
segments, broadcast drafts and journey drafts, and send tests to its own inbox. Nothing an agent makes
reaches a person: someone publishes the journey or sends the broadcast from the dashboard. See
[Drafting](#drafting).

## Where it lives

| Mode | Endpoint | On by default? |
|---|---|---|
| Self-hosted | `POST /mcp` | Yes |
| Embedded in your Laravel app | `POST /trigger-engage/mcp` | No: set `TRIGGER_ENGAGE_MCP_ENABLED=true` |

It speaks Streamable HTTP in the stateless form: each request is one JSON-RPC message, and the
reply is plain JSON. There's no SSE stream and no session to keep, so `GET` and `DELETE` return `405`.

## Connecting a client

Authenticate with a **server API key** (`te_…`) from **Connect your app**, or one you made with
`php artisan engage:workspace`. The key alone picks the workspace:

```bash
claude mcp add --transport http trigger-engage https://engage.example.com/mcp \
  --header "Authorization: Bearer te_your_server_key"
```

For clients that take a JSON config:

```json
{
  "mcpServers": {
    "trigger-engage": {
      "type": "http",
      "url": "https://engage.example.com/mcp",
      "headers": { "Authorization": "Bearer ${TRIGGER_ENGAGE_KEY}" }
    }
  }
}
```

The API's own Basic scheme (workspace id + key) also works. The server refuses publishable client
keys (`pk_…`) and revoked keys. Give agents their own key so you can revoke it without touching
your app's key; the key list shows when each one was last used.

## Tools

| Tool | What it answers |
|---|---|
| `get_workspace` | What's set up: counts by status, channels and defaults, and engine health (overdue delays, stalled runs, last event and message) |
| `list_events` | Which events arrive, how often, their payload keys, and which journeys trigger on, wait for or aim at them |
| `list_attributes` | Which profile attributes exist, their types and fill rate, so rules and Liquid use real names |
| `list_channels` | Email, SMS and push providers, the default for each type, and what sends through them. Secrets never appear |
| `list_subscription_topics` | Opt-out topics, their defaults and stored choices |
| `search_people` | People by text, by segment, or by segment-style rules |
| `get_person` | One profile: attributes, segments, reachability per channel, topics, and recent events, runs and messages |
| `list_segments` / `get_segment` | Audiences, their rules in plain words, members, and who uses them |
| `preview_segment` | How many people a set of rules matches right now, with the same validation as the dashboard |
| `list_templates` / `get_template` | Templates, their Liquid, the variables they read, and where they're used |
| `render_template` | A template filled in for a real person (or the preview sample), with variables that came out empty |
| `list_automations` / `get_automation` | Journeys step by step in plain words, with how many runs completed, skipped or failed each step, and why |
| `list_runs` / `get_run` | One person's trip through a journey: each step's outcome, waits, goals, and what happens next |
| `search_messages` / `get_message` | The messages log, and one message as sent (readable text, or HTML on request) |
| `list_broadcasts` / `get_broadcast` | Outcome counts, grouped failure reasons, and the audience a draft would actually reach |
| `get_analytics` | The Analytics page's totals, deltas, funnel and channel split |
| `diagnose_delivery` | Why a person did or didn't get an automation's or a broadcast's message |

### How `diagnose_delivery` works

Give it a person and an automation (or a broadcast). For an automation, it checks the same points
the engine passes through, in the same order:

1. Is the automation published and active?
2. Did this person send the trigger event in the window? If not, has *anyone* sent it? That tells
   "the host app never tracks it" apart from "it's tracked, just not under this identity".
3. Did each of those events start a run? If one didn't, which trigger filter failed and on what
   value, or which re-entry rule kept them out.
4. What did the latest run do at each send step: sent, skipped (suppressed, opted out of the topic,
   no address) or failed? And what did the provider report afterwards?
5. Could each channel reach them today?

It returns a one-line verdict and every check, each marked `pass`, `fail`, `warn` or `info`. A
delay that should already have woken shows up as a warning that points at the scheduler.

## Drafting

Drafting tools appear only for callers allowed to write: `TRIGGER_ENGAGE_MCP_WRITES=true` for server
keys, or an embedded host's own rule (below). Clients see them marked as not read-only, so Claude Code
and Claude Desktop ask before each call.

| Tool | What it does | What stops it reaching people |
|---|---|---|
| `create_template` | A new email, SMS or push template, checked like the template editor checks it | A template sends nothing until a journey or broadcast uses it |
| `update_template` | Changes a template, naming only the fields that change | **Refused** if an active journey sends it, or people are still moving through a journey that will: templates have no draft state |
| `duplicate_template` | Copies a template under a new name | The way to change a live template: edit the copy, point a journey draft at it |
| `create_segment` | A rule or manual segment, filled straight away | Filled **without** `segment_entered` events, so no journey starts |
| `update_segment` | Renames a segment or replaces its rules (filled quietly again) | New rules are **refused** for a segment a live journey uses, or that other segments' rules include |
| `create_broadcast_draft` / `update_broadcast_draft` | A campaign to a segment, content copied from a template, with its audience estimate | Stays a draft; only drafts can be edited; a person presses Send |
| `create_automation_draft` / `update_automation_draft` / `discard_automation_draft` | A journey as the editor's own step list, built and checked by the editor's own builder | Saved beside the live version, which keeps running; a person publishes it from the editor |
| `send_test` | Emails a template or broadcast draft, filled in for a real person or the sample, with "[Test]" in the subject | Only to the caller's own address, which the host sets; no message is logged |

**Journey drafts in the editor.** A journey with a draft opens on the draft's steps under an
"Unpublished draft" banner that says who saved it. **Publish draft** makes it live as a new version
(people mid-journey stay on theirs); **Discard draft** goes back to the live steps. The name, trigger
event and re-entry policy of a published journey can't be changed through MCP, because those apply at
once.

**Audit trail.** Every write is recorded in `engage_audit_events` with the caller (`api-key:<name>` or
the host's `actor`), what changed and a sentence a person can read. `get_workspace` shows the latest
ten.

## Privacy

- **Contact details are masked.** Emails come back as `a***@e***.com` and phone numbers as
  `+234*******678`. That's true wherever they appear: profiles, attributes, event payloads,
  message bodies, provider errors. An agent can still look someone up by their full address.
  Turn masking off with `TRIGGER_ENGAGE_MCP_REVEAL_CONTACT_DETAILS=true`.
- **Credentials never come back, whatever that setting says.** Push tokens, API keys, passwords
  and other secret-named fields are replaced with `[redacted]`. Channel settings only list which
  secrets are stored.
- **Signed links lose their signature.** Rendered emails contain signed unsubscribe and preference
  links that act for the recipient, so their `signature=` value is stripped.
- **Each key sees one workspace.** Tools only ever query the workspace the credentials belong to.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `TRIGGER_ENGAGE_MCP_ENABLED` | `true` self-hosted, `false` embedded | Register the endpoint at all |
| `TRIGGER_ENGAGE_MCP_PATH` | `mcp` / `trigger-engage/mcp` | Where it is served |
| `TRIGGER_ENGAGE_MCP_MIDDLEWARE` | `api,trigger-engage.mcp` | The route's middleware (see below) |
| `TRIGGER_ENGAGE_MCP_RATE_LIMIT` | `120` | Requests per minute |
| `TRIGGER_ENGAGE_MCP_REVEAL_CONTACT_DETAILS` | `false` | Show emails and phone numbers unmasked |
| `TRIGGER_ENGAGE_MCP_DISABLED_TOOLS` | empty | Comma-separated tool names to switch off |
| `TRIGGER_ENGAGE_MCP_WRITES` | `false` | Offer the drafting tools to server-key callers |
| `TRIGGER_ENGAGE_MCP_TEST_RECIPIENT` | empty | The only address `send_test` delivers to for server-key callers; empty = no test sends |

### Embedded hosts with their own auth

`trigger-engage.mcp` is the server-key check. An embedded host that wants its admins to connect
with the host's own login can swap it out. Put the host's auth middleware first, then
`trigger-engage.workspace`, which settles the embedded workspace:

```
TRIGGER_ENGAGE_MCP_MIDDLEWARE=api,auth:api-admin,can:viewTriggerEngage,trigger-engage.workspace
```

Such a host decides who may draft by setting request attributes in its own middleware:
`trigger-engage.mcp.can-write` (true/false), `trigger-engage.mcp.test-recipient` (the signed-in
person's own address) and `trigger-engage.mcp.actor` (a name for the audit trail, e.g. `admin:12`). Hosts
that wrap the tools in their own MCP server pass the same three things to `Context`.

## Things to ask an agent

- "Why didn't user-123 get the welcome email yesterday?"
- "Which step of the activation journey loses the most people, and why?"
- "How many people would this broadcast actually reach, and who gets skipped?"
- "Write segment rules for students who booked at least twice in 30 days, and check how many match."
- "Show me what the payment recovery email looks like for user-77."
- With drafting on: "Draft a broadcast to everyone who booked twice but not in the last 60 days, test it
  on me, and tell me who would be skipped."
- "Add a two-hour wait before the payment recovery email, as a draft I can review."

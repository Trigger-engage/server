# Building journeys

An **automation** (journey) is a graph of nodes and edges with a single event trigger. You draw
it in the dashboard's visual editor, publish it, and the engine walks each person through it.
For where journeys sit in the wider model, see [Concepts › Automation](../CONCEPTS.md).

## The editor workflow

Journeys are **built on the canvas**, not hand-authored. The React Flow editor is drag-and-drop:

1. **Add a step** — pick a node type from the palette.
2. **Drag to reorder** the nodes on the canvas and connect them with edges.
3. **Configure it** in the side panel that opens when you select a node.
4. **Publish new version** when you're happy.

![The visual journey editor: a trigger flowing through nodes on the React Flow canvas, with a step configuration panel on the right](../images/ab-test-journey.png)

Publishing freezes an **immutable version**. Runs already in flight finish on the version they
started on — republishing never disrupts them. Only new entrants use the new version.

### Re-entry policy

Set on the automation, this controls how often one person can enter:

| Policy | Behaviour |
|---|---|
| `every_time` | A new run starts on every matching trigger event. |
| `one_active_run_per_person` | A person can have at most one active run at a time. |
| `once_ever_per_person` | A person enters once and never again. |

### Runs and steps

A **run** is one person moving through one version. It tracks the current node, a `wake_at`
timestamp for delays, and a `context` blob. Each node execution is recorded as a **run step**.

## Node types

### `trigger` (exactly one)

The event that starts the journey. Every automation has exactly one.

### `delay`

Pauses the run. Two forms:

- **Fixed duration** — `{days, hours, minutes}`.
- **Wait until a time of day** — `{until_time: "09:00"}` resolves to the next occurrence of that
  local time in the **workspace timezone**. Useful for quiet hours.

Delays are durable and survive restarts; multi-day delays are fine.

```jsonc
{"type": "delay", "config": {"days": 1, "hours": 12}}
{"type": "delay", "config": {"until_time": "09:00"}}  // next 9am, workspace tz
```

### `branch`

Evaluates a predicate `{field, operator, value}` against the run context and takes one of two
outgoing edges, selected by branch `"true"` / `"false"`.

`field` paths read from either source:

- `event.*` — the trigger payload.
- `person.*` — the profile.

| Operator | Meaning |
|---|---|
| `equals`, `not_equals` | Equality / inequality |
| `gt`, `gte`, `lt`, `lte` | Numeric comparison |
| `contains` | Substring / membership |
| `exists`, `not_exists` | Field presence |

```jsonc
{"type": "branch", "config": {"field": "person.plan", "operator": "equals", "value": "pro"}}
```

### `wait_for_event`

Pauses the run until a **later** event for the same person arrives, or a timeout elapses. Two
outgoing edges: branch `"matched"` and branch `"timed_out"`.

Optional **correlation** ties a field on the incoming event to the trigger payload (for example
`appointment_id` to `appointment_id`) with operator `equals` or `not_equals`, so an unrelated
event for the same person doesn't satisfy the wait.

On timeout you choose one of: **stop the run**, **continue** to the next step, or **send one
fallback message then continue**.

### `split` (A/B test)

Routes each person to one of 2–4 weighted message variants, then converges to the next step. See
the [A/B testing guide](./ab-testing.md) for weights, determinism, and reading results.

### `send_email` / `send_sms` / `send_push`

Sends a message through the channel's default provider.

| Field | Notes |
|---|---|
| `template_id` | The Liquid template to render. |
| `channel_id` | The channel to send through. |
| `retry_attempts` | 1–10, default `3`. |
| `on_failure` | `"continue"` (default) or `"fail"`. |

Suppressed people, and people missing a destination for that channel, are **skipped** — the skip
is recorded on the run step, not treated as a failure.

Templates use `{{ person.* }}` and `{{ event.* }}` Liquid variables. A missing variable renders
empty and is logged as a warning on the run step.

### `goal` (automation-wide, optional)

A global goal, not a node on a path. Every run subscribes to it when it starts. A matching event
occurrence for that person **completes the run from any node** and cancels pending delays, event
waits, and send retries. Optional correlation prevents one entity's goal from stopping another
entity's journey.

### `exit`

Completes the run.

## What it compiles to

The canvas serializes to JSON. You rarely edit this by hand, but reading it clarifies the model.
This journey waits an hour, branches on the trigger's `plan`, emails only free-plan people, then
exits:

```jsonc
{
  "nodes": [
    {"id": "trigger", "type": "trigger", "config": {}},
    {"id": "wait",    "type": "delay",   "config": {"minutes": 60}},
    {"id": "fork",    "type": "branch",  "config": {"field": "event.plan", "operator": "equals", "value": "free"}},
    {"id": "send",    "type": "send_email", "config": {"template_id": 1, "channel_id": 1}},
    {"id": "done",    "type": "exit",    "config": {}}
  ],
  "edges": [
    {"from": "trigger", "to": "wait"},
    {"from": "wait", "to": "fork"},
    {"from": "fork", "to": "send", "branch": "true"},
    {"from": "fork", "to": "done", "branch": "false"},
    {"from": "send", "to": "done"}
  ]
}
```

Trigger events arrive through the ingestion [API](../API.md).

## Engine guarantees

Execution is safe under retries, restarts, and duplicate delivery: no double-sends (a unique
per-run/node reservation is taken before dispatch), durable delays (`wake_at` plus a scheduler
tick every minute), race-safe event waits, goal-safe execution, idempotent ingestion, and
suppression- and retry-aware sends. See [Engine guarantees](../../README.md#engine-guarantees).

## Next

- [Segments](./segments.md) — build the audiences your triggers and branches read.
- [A/B testing](./ab-testing.md) — configure and read `split` nodes.
- [Analytics](./analytics.md) — see how published journeys perform.

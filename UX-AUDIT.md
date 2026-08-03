# Trigger Engage — UX Audit

**Date:** Aug 3, 2026 · **Commit:** `f975e64` · **Scope:** the dashboard UI (embedded at `/trigger-engage` and standalone), the automation editor, and the public unsubscribe flow.

**Method:** three evidence streams — (1) a seven-lens source audit (editor integrity, failure observability, first-run/setup, IA/navigation, copy, accessibility, power features), every substantive claim fact-checked against the code by independent verifiers (61 claims checked, 2 corrected — noted inline); (2) a visual pass through every page of a seeded workspace; (3) real field incidents from this week's usage: the broadcast that reported "8 skipped/failed" with no cause, and the editor save that silently deleted a journey's verification branch.

---

## Verdict

The bones are genuinely good. The engine records everything a great UX needs — per-recipient error strings, distinct skipped-vs-failed statuses, immutable pinned versions, per-step run timelines — and the writing voice is better than most internal tools. The product's problem is almost entirely **presentation debt on the failure and safety paths**: the data that would answer "why did this fail?" and "what will this change?" exists in the database and never reaches a screen. That is the best kind of problem to have, because nearly every high-severity fix below is a read route and a panel, not engine work.

The delight ceiling is high precisely because the danger floor is currently low: a user who has been burned twice (a send that failed inscrutably, an editor that ate their journey) stops trusting every green pill in the product. Fix trust first; delight lands only on top of it.

## What is already good (keep and build on)

- **Immutable version pinning** is the right architecture, already working: publish creates a version; in-flight runs stay pinned to theirs (`app/Models/Automation.php:48`, `ProcessEventOccurrence.php:97`).
- **The run detail timeline** (`Runs/Show.jsx`) is the best failure surface in the product — status dots, attempts, error text in rose boxes, event-wait deadlines. It proves the team can do this well; the ask is to apply the same standard everywhere else.
- **Segment tooling is power-grade**: live rule preview with count + sample, duplicate, streamed CSV export, 5000-row import with a precise result summary. Segments are also the best cross-linking hub in the app.
- **The broadcast snapshot model** and its explaining copy ("Content below started as a copy of the template. Edits here only affect this broadcast") — exactly the right sentence in exactly the right place.
- **Channel Test Connection is real engineering**: SMTP opens the actual transport, Termii validates via balance, Expo explains access-token nuance in human words.
- **Flash messages teach next steps** ("Broadcast draft created. Edit the message, preview it, then send.") and the segment rule builder reads as an English sentence.
- **The composer** is far richer than expected — brand controls, live preview with an "Up to date" indicator, and a crisis-support copy field (a genuinely thoughtful, category-specific touch).
- Shared design tokens (`inputClass`/`panelClass`/`FieldError` in one place) mean most visual fixes below are one-file changes.

---

## The three structural fixes (do these before inviting the team)

The completeness critic put it well: *friction is forgiven, betrayal isn't.* These three are the betrayal class.

### 1. Failure is currently a dead end → build the broadcast report page

The founding incident: "8 skipped/failed · 8 total," no causes, no retry — while `broadcast_recipients` held a precise per-person status and error string for every row (`SendBroadcastRecipient.php:38,50,77,98`). No route or page reads that table; skipped (expected — no email) and failed (a bug) are collapsed into one number (`BroadcastController.php:45`); a non-draft broadcast can never be re-sent.

**Build:** `GET broadcasts/{id}` — a segmented outcome bar (sent / skipped / failed as three numbers, three colors), reasons **grouped** server-side ("Skipped: 5 people have no email address", "Failed: 3 × ⟨error⟩"), an expandable per-recipient table linking to each person, and a **Retry failed** button (`POST broadcasts/{id}/retry-failed`: reset failed rows to queued inside the existing lock, re-dispatch — the job already guards idempotency). Exclude skipped rows from retry; they are not errors.
While in there: the pre-send confirm should say *"Sends to 3 of 8 people — 5 have no email and will be skipped"* (one count query per channel type) so the worst post-send surprise becomes a pre-send decision.

### 2. The editor can silently destroy work → guard the fidelity boundary

The editor's flat step list is narrower than the engine's graph model. `editableSteps()` discards all edges; the publish whitelist (`AutomationController.php:96`) has no `branch` type; a divergent timed-out path is flattened to "continue." Republishing any externally-authored journey (seeder, API, import) silently rewrites its behavior — this is exactly how the live "New user activation" journey lost its verification logic and started sending both emails to everyone. Compounding it: **a failed publish is invisible** (per-step errors keyed `steps.N.field` are never rendered — the button stops spinning and nothing happens), and **versions exist in the DB with no UI** while the publish panel literally promises "Publishing creates an immutable version."

**Build, in order of leverage:**
1. *Fidelity guard (hours):* in `edit()`, rebuild the graph from `editableSteps()` and structurally compare to the active version; when they differ, pass `lossy: true` → blocking banner ("This journey uses a Branch step this editor can't display yet. Publishing from here would remove it.") and a disabled Publish. `publish()` refuses a lossy rebuild server-side too.
2. *Visible errors (hours):* any error key matching `/^steps\.(\d+)\./` paints card N red with an inline message + a page-top summary; server messages name the step number.
3. *Version history (days):* `GET automations/{id}/versions` + restore-as-new-version, listed under the Publish panel with a read-only canvas preview. Once publishing can't destroy, restore becomes the rare path.
4. *Publish confirm (days):* step-level diff vs the active version, run counts, and — important — an explicit line when the automation is paused, because **publish silently reactivates a paused automation** (`Automation.php:55`), which is its own trap.

### 3. The UI lies about status → make status honest

The header wears a **hardcoded** "Production ready" pill (`components/Layout.jsx:50`) and a hardcoded "Active" workspace dot, on a workspace whose email channel is the log driver and whose push channel has empty credentials. Setup instructions are smuggled through seeded channel *names* ("Email (log — switch to SMTP/ZeptoMail in Channels before launch)") — which then appear inside every channel dropdown in the editor. "Delivered: 0" sits unexplained beside real send counts (delivered is webhook-fed; SMTP can never populate it). And channels are **write-once**: no edit, delete, rename, or re-test of stored credentials (`routes/web.php:28-30`), so the instruction in the seeded name is literally impossible to follow without SQL.

**Build:** delete the fake pill; replace with a computed setup-health chip feeding a small launch checklist on Overview (real email channel ≠ log · push credentials present · scheduler ticking — have `engage:tick` write a cache heartbeat · queue worker alive · first event received). Add `PUT/DELETE channels/{id}` with masked credential editing and a per-card Test that runs against **stored** credentials. Amber-badge any log-driver channel everywhere it renders ("In development — writes to log"), including the broadcast send confirm ("This channel writes to the application log — no one will receive it."). Where Delivered = 0 with sends > 0, render "Delivery tracking not connected" + each channel card should display its provider webhook URL with a copy button. Rename the seeded channels to plain names.

---

## Ranked backlog by theme

Severity/effort tags from the audit, fact-checked. `[H/s]` = high severity, small effort.

### Failure & observability
- `[H/m]` Broadcast report page + grouped reasons + per-recipient table (fix #1).
- `[H/s]` Retry-failed action (fix #1).
- `[H/s]` **No error ever reaches a log or Sentry**: every channel swallows every `Throwable` into a DB column; zero `report()`/`Log::` calls in the whole Engine/Channels/Jobs surface. Add `report($e)` in each catch — the host's existing Sentry then catches crashes for free. Then: a per-workspace Alerts setting (email/webhook) firing on "broadcast finished with failures" and "run send failed after retries."
- `[H/m]` Runs are unfilterable and failures hide behind green: default `on_failure=continue` means a run whose only send failed still ends **completed/green**. Add `?status/?automation/?person` filters + `failed_steps_count` badge ("Completed · 1 step failed") + a dashboard attention strip. (The verifier's correction: Overview *does* show a "Failed or bounced" tile — the real gap is attribution: no path from the number to which run/person.)
- `[m/s]` Stalled sends: a broadcast stuck in `sending` renders a bare amber pill forever. `started_at` is *already in the page props* — render "Sending · started 3m ago," and past ~10 min with queued rows, "Stalled — is your queue worker running?"
- `[m/s]` Analytics funnel counts failed+queued rows as "Sent" — filter the base or the rates are computed against attempts.
- `[m/m]` Person-level message history: "did user X get the email?" has no answer surface; add a Messages section to the person page, and render `message.error` in the run timeline (one-line JSX change).
- `[l/s]` Run timeline paints skipped steps with the same green dot as completed — give skipped its own grey dot + reason caption.

### Editor
- `[H/m]` Fidelity guard, `[H/s]` visible per-step errors, `[H/m]` version history, `[H/m]` publish confirm + paused-reactivation warning (fix #2).
- `[m/m]` **No way to test a journey**: no send-test, no preview-as-person, no test event, and `once_ever_per_person` means a person can never traverse twice (this week's testing required inventing fresh users). Cheapest ladder: send-test on template/broadcast → preview-as-person → "fire test event" form on Events → `test=true` runs that bypass reentry.
- `[m/s]` Unsaved changes: the Back link discards all edits with no warning; `form.isDirty` is available and unused. (Corrected: Pause does *not* lose edits — Inertia preserves state.)
- `[m/s]` The canvas is a false affordance: looks like the flow editor, but the only interaction is an undiscoverable nearest-Y drag that can silently reorder a live journey. Add node-click → scroll-to-card, a caption ("Drag a step to reorder · edit in the cards below"), and snap-back after drag.
- `[m/s]` Automations can't be renamed, deleted, or duplicated — a typo is forever, and duplicate is also the natural "safe sandbox" answer.
- `[l/s]` Changing an A/B variant's channel resets its weight and retry config via `freshVariant()` — patch, don't replace.
- `[l/s]` Editor default for wait timeout is `exit` but `buildGraph()` defaults a missing value to `continue` — standardize on `exit` (the safer surprise).

### Setup & first run
- `[H/m]` Channel edit/delete/re-test + placeholder badges (fix #3).
- `[H/m]` Computed launch checklist replacing the hardcoded pill (fix #3).
- `[H/s]` Delivered-0 explainer + webhook URLs on channel cards (fix #3).
- `[m/m]` No way to get API credentials from the UI (the key prints once to a console) — the People/Events empty states describe a step the operator can't perform. Add a "Connect your app" page: keys, ingestion URL, copy-paste identify/track snippets. Point empty states at it.
- `[m/m]` First-run sequencing: until the first real send, swap the greeting for a numbered getting-started strip (channel → template → event → automation → run) driven by the same checklist detections.
- `[m/s]` `engage:install` says "ready" without mentioning the queue worker or scheduler the engine dies without — append next-steps output; mirror in the checklist.
- `[m/s]` Broadcasts page with zero segments silently disables "Create draft" with no stated reason — say why and link to Segments.
- `[l/s]` Only one empty state in the app has a CTA; the shared component supports them. Give each its next action.

### IA & navigation
- `[H/m]` **Sent messages have no page anywhere** — a messaging platform that cannot answer "what did we send, to whom, when." Add a Messages index (channel, to, status, source run/broadcast, links) and make the dashboard tiles deep-link into it pre-filtered.
- `[H/s]` The person↔run↔automation triangle is one-way: runs list renders automation and person as **unlinked text**; the person page shows runs/messages as dead count tiles. Link everything; add the three `when()` filters.
- `[m/m]` Nav mixes one-time setup with daily drivers under one "Workspace" label. Regroup: Engage (Overview, Automations, Broadcasts, Templates) · Audience (People, Segments, Events) · Monitor & Setup (Runs, Analytics, Channels).
- `[m/m]` Events are a dead-end catalog — no per-event page, occurrence counts unclickable, no event→automation linkage. Add `events/{id}`: recent occurrences with payloads, automations it triggers.
- `[m/m]` No global search; only People is searchable. A cmd-K palette over one `/search` endpoint (people, automations, segments, templates, broadcasts, "run #N") is the single biggest daily-driver quality-of-life win.
- `[m/m]` Segment rule editing is non-addressable local state on the Index page (refresh/back loses it) while name/description live on the Show page — unify on the segment's own page.
- `[l/s]` Browser tabs read bare "Runs"/"People" — add the `· Trigger Engage` title callback (one line).
- `[l/s]` Templates have no usage backlinks ("Used in 3 automations · 1 broadcast").
- **Embedded-arrival (from the critique — the primary user's actual front door):** there is no Filament nav item pointing to `/trigger-engage` (a teammate must be handed the URL verbally), no link back to the host admin from inside, and the access gate is binary — anyone through it can broadcast to everyone. Add the Filament `NavigationItem`, a "← Mytherapist.ng admin" link in the sidebar, and consider a read-only role tier before wide team access.

### Copy & tone
- `[H/s]` **The public unsubscribe page** — the only surface end users ever see — leaks the internal word "workspace," shows no brand name, renders the raw channel enum ("Stop sms messages?"), and offers no undo. For a mental-health platform this page must feel safe: brand it, humanize it, add resubscribe. (The workspace relation is already on the model, unused.)
- `[m/s]` Raw enums leak everywhere: `waiting_event`, "once ever per person," "occurrences." One shared `labels.js` map; the audit proposes the worst-10 replacements verbatim (e.g. `once_ever_per_person` → "Each person enters once, ever").
- `[m/s]` Send confirms state neither scale nor finality; follow the segment-delete pattern the codebase already has: "Send 'July update' to **8 people** in Active users now? Sending starts immediately and can't be undone."
- `[m/m]` Three conflicting time stories: browser-local `toLocaleString()` with no zone label, workspace tz in the sidebar, "(UTC)" on Analytics, and a "wait until local time" step that actually means *workspace* time. One `formatDateTime(value, workspace.timezone)` helper + relabel the delay field.
- `[m/s]` Terminology drift: person/profile/customer, automation/journey/flow, "Campaigns" eyebrow over "Broadcasts." Pick one vocabulary; 30-minute sweep. (For the embedded install, never "customer" — the house rule is "users.")
- `[l/s]` Polish: "attempt(s)" pluralization; raw exception text shown unframed to non-engineers; "Identified" badge shown unconditionally.

### Accessibility & visual
- `[H/s]` Errors/flash are invisible to assistive tech (zero `aria-*`/`role=alert` in the codebase) and several forms funnel multiple fields into one collapsed error slot. Fix in `FieldError`/`Layout.jsx` once, sweeps every page.
- `[H/s]` The muted text ramp fails WCAG AA app-wide: `slate-500` (~4.1:1) is the standard 12px label color; `slate-600/700` worse, including on interactive controls. Mostly a find-replace up one shade, since tokens are shared.
- `[H/s]` The editor is pointer-first: drag-only canvas reorder; the fallback is unlabeled `↑`/`↓` glyphs. Real aria-labels with step context + a polite live region announcing reorders.
- `[m/s]` Focus rings are imperceptible (10%-alpha ring on near-black); one token change to `focus-visible:ring-2 ring-emerald-400/60`.
- `[m/m]` The person "properties wall" (field-observed): ~15 synced properties × 4 controls each. Default to read-only rows (key · value · inferred-type chip) with per-row Edit; separate synced from manual properties.
- `[m/s]` No `prefers-reduced-motion` anywhere; canvas edges march permanently. Two-line fix.
- `[m/s]` Mobile drawer isn't a managed dialog (no focus trap/Escape/`aria-expanded`).
- `[l/s]` 10px status badges gate real decisions; floor at 11–12px and extract one shared `StatusBadge` (three hand-rolled color ternaries exist today).
- `[l/m]` People list is an unlabeled CSS grid while Runs/Events use real tables; pagination is raw HTML with no nav landmark. Reuse the table pattern; extract one `<Pagination>`.

### Power features
- `[H/m]` The test-affordance ladder (see Editor).
- `[m/m]` Bulk operations: nothing anywhere — no multi-select, one-at-a-time segment membership. Checkbox select + sticky action bar (add-to-segment / remove / suppress).
- `[m/s]` Suppression state is invisible: `unsubscribed_at` drives real skip behavior but no page shows it, and there's no suppress/resubscribe control. (Note from the critique: there are **two** suppression stores — channel-scoped `suppressions` rows from the unsubscribe page and `person.unsubscribed_at` — reconcile them before building the UI.)
- `[m/s]` Volume safety: no audience count in the send confirm (it's in props on Index already), no typed-confirm threshold for large sends, no scheduled send.
- `[l/s]` Templates/automations can't be duplicated while segments can — copy the segment pattern.

### From the critique (unexamined corners worth a look)
- **Performance perception**: Events index does `withCount('occurrences')` unpaginated over the biggest table; People search is triple leading-wildcard LIKE; the dashboard fires ~13 COUNTs. Fine today, will crawl at host volumes — cache the dashboard, paginate events.
- **The brand model**: brand settings (8 colors, logo, crisis copy) are stored **per template** — a logo change means editing every template. Hoist to workspace-level with per-template override. Also: the host's brand is hardcoded into the OSS product ("Restore Mytherapist.ng" button) — should come from config for self-hosters.
- Session expiry mid-edit redirects to `/admin/login` and loses editor state — pairs with the unsaved-changes fix.

---

## Quick wins (each ≤ a day, most ≤ 2 hours)

1. Delete the "Production ready" + "Active" hardcoded chips.
2. Split "skipped/failed" into three counts on the broadcasts index.
3. `report($e)` in the channel catch blocks → Sentry sees crashes.
4. Paint `steps.N.*` errors onto the offending card + summary.
5. Runs index: link automation + person names; add the three filters.
6. `labels.js` enum map (worst 10 strings).
7. Contrast sweep: `slate-500→400`, `slate-600/700→500`.
8. Focus-visible ring token; aria on `FieldError`; flash `role=status/alert`.
9. Tab title callback (`· Trigger Engage`).
10. Send confirm with audience count + finality sentence.
11. Publish-reactivates-paused warning line.
12. "Delivered: 0" explainer states.
13. Unsubscribe page rebrand + resubscribe link.
14. Canvas caption + node-click scrolls to card; snap-back after drag.
15. `prefers-reduced-motion` gate on edge animation.
16. Run timeline: grey dot + reason for skipped; render `message.error`.
17. Stalled-send hint using the already-present `started_at`.
18. Filament `NavigationItem` → `/trigger-engage`, and a back-link in the sidebar.

## The delight layer (after trust)

The product already has more personality than most (the seeded subject lines, the crisis-copy field, "Good to see you"). Delight here should mean *the product feels alive and on your side*:

- **A setup checklist that celebrates**: items check themselves off as detections pass; confetti-level moment on first real delivered message ("Your first message is out in the world").
- **A living canvas**: per-step entered/completed counts rendered on the nodes, and a subtle pulse on nodes with people currently waiting — the journey map becomes a live dashboard, not a diagram. (Respect reduced motion.)
- **"Test on me"**: one click, sends the journey's first email to the signed-in admin, bypassing reentry — turns testing from a chore into a toy.
- **Empty states that hand you the next move** — the component already supports CTAs; use them everywhere.
- **An honest funnel**: when a stage is structurally impossible (SMTP → Delivered), say so instead of showing a zero. Honesty *is* the delightful version of analytics.
- **Humane failure copy**: "Something went wrong on our side" framing with the raw exception in a collapsed monospace block — respects both the marketer and the engineer.

## Suggested sequencing

| Week | Theme | Contents |
|---|---|---|
| 1 | Stop the bleeding | Fidelity guard + visible publish errors · broadcast report page + retry · `report()` → Sentry · kill fake status chips · quick wins 1–10 |
| 2 | Honest status | Launch checklist · channel edit/re-test + placeholder badges · Delivered explainer + webhook URLs · runs filters + failed badges · unsubscribe rebrand |
| 3 | Operate & navigate | Messages index · person page runs/messages panels · version history + restore · test ladder (send-test, preview-as-person, fire-test-event) · cmd-K |
| 4 | Comfort & polish | A11y sweep (contrast, focus, aria, drawer) · properties wall redesign · nav regrouping · timezone helper · copy sweep · bulk select |

## Fact-check appendix

61 substantive claims were independently verified against source; 59 confirmed, 2 corrected and reflected above: (1) the Pause button does **not** discard edits (Inertia v2 preserves state) — only the Back link does; (2) `started_at` for stalled broadcasts is already passed to the page — the gap is rendering only. Two audit findings were additionally downgraded by the critic and are stated in downgraded form here: automation failures are not "invisible everywhere" (the Overview failed tile exists — the gap is attribution), and the log-channel launch trap has a workable if unlabeled escape path (the editor's channel selects do show the driver).

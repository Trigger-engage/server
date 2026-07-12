# A/B testing

An A/B test is a `split` step inside an [automation](./automations.md). It routes each person
to one of 2–4 weighted message variants, then every path converges back to the next step in
the journey.

![A welcome A/B test in the journey editor: the trigger flows into an "A/B test" split that branches to Variant A and Variant B email sends before converging on Exit, with a live A/B results panel showing each variant's conversions](../images/ab-test-journey.png)

## What a variant is

Each variant is its own send. It carries a `key` (`A`, `B`, `C`, `D`), a `weight`, a channel
`type`, a `template`, and the channel provider that delivers it:

| Field | Values | Notes |
|---|---|---|
| `key` | `A`–`D` | Stable label for the variant |
| `weight` | integer | Share of traffic — relative, so `50/50` and `1/1` are the same split |
| `type` | `email`, `sms`, `push` | The channel to send on |
| `template` | template id | The message body/subject to render |
| `provider` | channel provider | Which configured provider sends it |

Because each variant is a full send, you usually A/B test different templates or subject lines
on the **same** channel — but variants can also differ by channel entirely.

## Deterministic, weighted assignment

Assignment is weighted **and** deterministic. The split hashes the person together with the
node, so a given person always lands on the same variant. Retries and re-runs never reshuffle
the experiment — nobody flips from A to B on a redelivery.

Weights set each variant's share of traffic. A `50/50` split sends half to each; an `80/20`
split biases toward the front-runner while still sampling the alternative.

## Add a split (dashboard)

1. Open the automation in the editor.
2. Add a step with **+ A/B test**.
3. Configure 2–4 variants. For each, set the **weight**, **channel**, **template**, and
   **channel provider**.
4. Add or remove variants as needed — **2 minimum, 4 maximum**.
5. **Publish** the automation.

## Under the hood

Publishing compiles the split into a `split` node plus one generated send node per variant,
wired with per-variant branch edges that converge to the next step. So an A/B test reuses the
same send and routing engine as an ordinary send step — you never hand-author those nodes, the
editor generates them when you publish.

## Example: two welcome subject lines

Test two subject lines for the welcome email, evenly split, with `activated` as the goal:

```
trigger: customer_sign_up
  └─ split (A/B test)
       ├─ A  weight 50  email  provider=postmark  template=welcome_aboard   "Welcome aboard"
       └─ B  weight 50  email  provider=postmark  template=welcome_youre_in "You're in"
  └─ delay: 3 days
  └─ exit

goal: activated
```

Both variants send the welcome email; the journey then waits before the goal window closes.
After a few days you compare which subject line drove more `activated` events.

## Reading the results

The editor shows a live **A/B results** panel for the published version. Per variant:

| Column | Meaning |
|---|---|
| Entered | People routed to this variant |
| Converted | People who then converted |
| Rate | `Converted ÷ Entered` |
| Leading | Badge on the current front-runner |

**Conversion** is the automation's **goal** if one is set, otherwise **completing the
journey**.

Reading the welcome-email example: if A entered 1,000 and converted 220 (22%) while B entered
1,000 and converted 180 (18%), A carries the **leading** badge. Because the split is
deterministic, those cohorts are stable — the numbers move as people convert, not as people get
reassigned.

## Goals only count while a run is active

A goal is only "reached" while a run is still **active**. This matters for A/B tests that
measure a goal:

- An automation with **no delay or wait** completes instantly. A goal set on such an automation
  can never be reached, so conversions show **0** for every variant.
- To measure a goal, the journey must still be running when the goal event fires. Put the
  conversion-defining event **after** a [`delay` or `wait_for_event`](./automations.md), or rely
  on journey-completion as the conversion instead.

Realistic A/B tests measure a goal that happens some time after the message is sent — which is
why the example above waits 3 days before the run exits. See [Concepts](../CONCEPTS.md) for how
runs, goals, and versions relate.

## Next

- [Building journeys](./automations.md) — the nodes a split lives among: delay, branch,
  wait-for-event, goal.
- [Analytics](./analytics.md) — workspace-wide metrics beyond a single experiment.

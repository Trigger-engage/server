# Trigger Engage documentation

Everything you need to install, use, extend, and operate Trigger Engage. New here?
Start with the [origin story](marketing/why.md), then [Concepts](CONCEPTS.md).

## Get started

| Guide | For |
|---|---|
| [Installation](INSTALLATION.md) | Embed in an existing Laravel app, or deploy standalone |
| [Concepts](CONCEPTS.md) | The mental model — workspaces, people, events, journeys, segments, channels |
| [Laravel SDK](../../laravel-sdk/README.md) | Fire events from your app, and test with the fake |

## Build

| Guide | What it covers |
|---|---|
| [Building journeys](guides/automations.md) | Every automation node — delay, branch, wait-for-event, A/B split, goal |
| [Segments](guides/segments.md) | Manual, event-driven, and rule-based behavioural audiences |
| [A/B testing](guides/ab-testing.md) | Weighted, deterministic message splits and reading results |
| [Anonymous → identified](guides/anonymous-identity.md) | Track before signup, then merge the history |
| [Analytics](guides/analytics.md) | The time-series metrics dashboard |

## Reference

| Reference | Contents |
|---|---|
| [HTTP API](API.md) | Every ingestion endpoint, payload, response, and error |
| [Architecture spec](../SPEC.md) | Data model, engine execution, design decisions |
| [Engine guarantees](../README.md#engine-guarantees) | No double-sends, durable delays, race-safe waits |

## Operate

| Guide | Contents |
|---|---|
| [Deploying the backend](../README.md#deploying-the-backend) | Docker Compose, TLS, backups, upgrades, smoke tests |
| [Production gates](../PRODUCTION.md) | Provider setup, shadow cutover, release checklist |
| [Publishing](PUBLISHING.md) | Maintainer release process for the SDK and server packages |

## Project

- [Changelog](../../CHANGELOG.md) — what shipped, by milestone
- [Contributing](../../CONTRIBUTING.md) — dev setup, tests, conventions
- [License](../LICENSE) — MIT

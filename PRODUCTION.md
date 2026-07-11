# Production deployment and cutover

## Deploy

1. Copy `.env.example` to `.env`, generate `APP_KEY`, and replace every placeholder credential.
2. Set `APP_URL` to the final HTTPS origin. TLS must terminate at the load balancer or reverse proxy.
3. Run `docker compose build` and `docker compose up -d postgres redis app web horizon scheduler`.
4. Run `docker compose exec app php artisan migrate --force`.
5. Create the workspace with `docker compose exec app php artisan engage:workspace "Mytherapist.ng" --timezone=Africa/Lagos`.
6. Keep at least two application replicas and one Horizon/scheduler process. Back up Postgres and test restores.

The web image sets clickjacking, MIME, referrer and CSP headers. Redis is required for queues, overlap locks and Horizon. Run `php artisan horizon:terminate` after every deploy so workers reload code.

## Provider configuration

- Termii: save the regional base URL, API key, sender ID, transactional `dnd` route, and secret key. Configure `POST /api/v1/webhooks/termii/{channel_id}` as its delivery-report URL. Requests are verified with `X-Termii-Signature` HMAC-SHA512.
- OneSignal: save App ID, REST API key and a random Event Stream bearer token. Configure `POST /api/v1/webhooks/onesignal/{channel_id}` and add `Authorization: Bearer <token>` in the Event Stream headers. Include `event.id`, `event.kind`, `message.id`, and `event.external_id` in its JSON body.
- SMTP/ZeptoMail: use a dedicated SMTP credential and verified sender domain. Rotate credentials independently per workspace.

## Mytherapist shadow cutover

1. Deploy with `TRIGGER_ENGAGE_MODE=off` (the default) and confirm no Trigger Engage jobs are emitted.
2. Backfill people, then switch to `shadow`. Customer.io remains authoritative and every identify/event is also queued to Trigger Engage.
3. Compare event counts, recipient identity, rendered messages, suppressions and delivery status for at least one full booking/payment cycle.
4. Disable outbound Trigger Engage channels during the first comparison window, then enable only internal/test recipients.
5. Move to `primary` only after the comparison is signed off. The existing service call sites do not change.

## Release gates

- Replace the local Composer path repository with the published, tagged SDK package before building Mytherapist production images.
- Run server, SDK and Mytherapist Customer.io lifecycle tests in CI against Postgres and Redis.
- Verify Termii and OneSignal in their sandbox/test environments with real credentials; local tests use HTTP fakes.
- Put `/app` and `/horizon` behind an identity-aware proxy or private network. The built-in UI currently authenticates with workspace Basic credentials and is not intended as a public admin login surface.
- Configure alerting for failed jobs, stale `processing` steps, overdue `waiting_event` runs,
  stale active goal subscriptions on terminal runs,
  webhook failures, queue wait time, message failure rate, database capacity and Redis persistence.
- Complete a backup restore exercise and a rollback rehearsal before `primary` mode.

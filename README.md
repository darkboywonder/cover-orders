# Cover Orders: ShipInsure coverage prototype

A Laravel prototype that takes webhooks from **Shopify** and **Recharge**, works out which orders ShipInsure covers, and keeps the records the store would be billed from.

The rule it implements: **if the original order the customer placed is covered, the Recharge subscription is covered too**, and so is every recurring order that subscription places.

There is no Shopify app, no live connection to either platform and no billing system. Webhooks are simulated with signed fixtures built from the documented Shopify Order and Recharge 2021-11 Order and Subscription payloads.

---

## Running it

**Requirements:** PHP 8.3+ with the `pdo_sqlite` extension, and Composer. You don't need Node, MySQL or Redis.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
```

`--seed` creates a demo store (`demo-store.myshopify.com`) and prints its webhook URLs. `.env.example` ships with local demo secrets only.

### 1. Watch the full flow (no server needed)

```bash
php artisan webhooks:simulate covered-subscription
```

This signs each fixture the way the platform does and sends it through the real HTTP stack in-process: signature check, inbox, queue (run synchronously here), handlers, then a billing report. The deliveries are:

1. Shopify `orders/create`: order #1001 includes the ShipInsure protection line ($2.95).
2. Recharge `subscription/created`
3. Recharge `order/created` (type `checkout`): links the subscription to #1001.
4. Recharge `order/created` (queued, not yet in Shopify): ignored.
5. Recharge `order/processed` (type `recurring`): order #1002, covered through the subscription.
6. Shopify `orders/create` for #1002: the same order again, as Shopify sees it. It is **not** billed twice.
7. Recharge `subscription/cancelled`: coverage already granted stays in place.

Result: two billable coverages, $5.90 in total, one `protection_line` and one `subscription_inherited`.

Other scenarios:

```bash
php artisan migrate:fresh --seed
```

```bash
php artisan webhooks:simulate out-of-order
```

`out-of-order` sends the same events roughly in reverse and ends in the same result. After another `migrate:fresh --seed`:

```bash
php artisan webhooks:simulate uncovered-subscription
```

That one ends with no coverage, because the original order had no protection line. Add `--repeat=3` to any scenario to send every delivery three times, the way platform retries do; the duplicates are acknowledged and dropped.

### 2. Run it as a real server with a queue worker

```bash
php artisan serve
```

```bash
php artisan queue:work
```

```bash
php artisan webhooks:simulate covered-subscription --url=http://localhost:8000
```

`composer run dev` starts the server, queue worker and log tail together. Request logs go to `storage/logs/webhooks-*.log`, one structured line per request with metadata only.

### 3. Billing view, replay and tests

```bash
php artisan coverage:report demo-store.myshopify.com --month=2026-11
```

```bash
php artisan webhooks:replay --status=failed
```

```bash
php artisan test
```

`webhooks:replay` flags:

| Flag | What it replays |
|---|---|
| `--status=failed` (default) | The dead letter queue: events that ran out of retries |
| `--id=12 --id=13` | Specific events, in any status |
| `--stuck` | Events left in `received`/`processing` (queue down, worker crashed). Scheduled every 5 minutes. |
| `--source=shopify` | Narrows the selection to one source |
| `--since=2026-11-01` | Narrows the selection by receipt time |
| `--sync` | Runs the selection in this process instead of queueing it |

---

## How it works

```
Shopify ──POST /webhooks/shopify──────────────────┐
Recharge ─POST /webhooks/recharge/{shop}/{topic}──┤
                                                  ▼
                1. verify HMAC on the raw body (401 if bad)
                2. store in webhook_events (encrypted, unique per delivery)
                3. queue ProcessWebhookEvent, return 200 straight away
                                                  │
                                                  ▼
        ProcessWebhookEvent (retries with exponential backoff and jitter)
          → WebhookEventProcessor picks a handler by source + topic
          → handler upserts Order / Subscription in a transaction with row locks
          → CoverageService recomputes coverage from stored state
          → event marked processed | ignored | failed
```

Replay and future reconciliation feed the **same handlers**. Every handler is idempotent, so running an event again is always safe.

### Records

| Table | Purpose |
|---|---|
| `webhook_events` | The inbox and audit log. Raw body (encrypted at rest because it holds customer data), selected headers, status, attempts, last error, processing time. Unique on `(source, dedup_key)`. |
| `orders` | One row per Shopify order, keyed by `(shop, shopify_order_id)`. Shopify and Recharge both write to it, so a recurring order reported by both platforms ends up as a single row. |
| `subscriptions` | Recharge subscriptions, their link to the origin order, `is_covered`, and a snapshot of the origin premium. |
| `coverages` | **What we bill from.** One row per covered order (unique `order_id`), with reason, premium in integer cents, currency, status, and void reason. |

### Coverage rules (`app/Coverage/CoverageService.php`)

1. A Shopify order with the ShipInsure protection line (matched by SKU, with vendor as fallback; set in `config/coverage.php`) is covered. The premium is that line's price × quantity minus its discounts.
2. A Recharge **checkout** order links each subscription line (`purchase_item_id`) to the Shopify order in `external_order_id.ecommerce`. If that origin order is covered, the subscription is covered and the origin premium is snapshotted onto it.
3. A Recharge **recurring** order with status `success`, from a covered subscription that was active when the order was placed, gets a `subscription_inherited` coverage at the snapshotted premium.
4. One coverage per Shopify order, however many events describe it. A Shopify update never downgrades coverage that came from a subscription.
5. The result doesn't depend on delivery order. Every sync recomputes from stored state, and links that arrive early (for example a Recharge checkout before its Shopify order) resolve when the missing record lands.
6. An update is applied only if its `updated_at` is not older than what is stored. Shopify and Recharge timestamps are tracked separately.
7. A cancelled order voids its coverage. Cancelling a subscription stops coverage for orders placed after `cancelled_at`; coverage already granted is never revoked retroactively.

### Platform details the design relies on

| | Shopify | Recharge (2021-11) |
|---|---|---|
| Signature | `X-Shopify-Hmac-Sha256`: base64 HMAC-SHA256 of the raw body, keyed with the app secret | `X-Recharge-Webhook-Signature: t=…,v1=…`: hex HMAC-SHA256 of `"t.body"`, keyed with **the store's** client secret. Legacy `X-Recharge-Hmac-Sha256` = sha256(secret + body), on by default and can be switched off. |
| Dedup key | `X-Shopify-Webhook-Id` | No event id exists, so we use a hash of topic + resource id + resource `updated_at`. |
| Topic / shop | `X-Shopify-Topic`, `X-Shopify-Shop-Domain` | No topic header, so both come from the URL we register: `/webhooks/recharge/{shop-uuid}/{topic}`. |
| Deadline / retries | 5s; 8 retries over 4h, then **the subscription is deleted** | 5s; 20 retries over 2 days, then **the webhook is deleted** |
| Recovery API | None; query orders by `updated_at` | None; `GET /events` (Plus plan, last 7 days only) or list endpoints with `updated_at_min` |
| Subscription → order link | n/a | The Subscription object has **no** reference to the Shopify order. The link comes from the Recharge checkout **Order** object. |

---

## Assumptions

- **Recurring orders are billed at the origin order's protection price**, snapshotted onto the subscription, because recurring orders usually don't contain the ShipInsure line. Pricing lives in one place (`CoverageService::eligibility`) so a rate-based model can replace it.
- Only Recharge orders with status `success` are billable, because that is when the order has actually been placed in Shopify. `queued` and `skipped` orders are not.
- If the origin order is cancelled, the subscription stops covering **future** recurring orders. Whether that is the right business rule is listed as an open question below.
- Any Shopify order with the protection line is covered, whatever its `financial_status`. Cancellation and (in future) refunds void it.
- A signed Shopify webhook proves the shop installed the app, so an unknown shop is created on first sight. A Recharge shop must already exist, because its secret is per store.
- Amounts assume a two-decimal currency.

---

## Critical paths

✅ built in this prototype · ⏭️ considered but not built (next steps)

### Ingest and security

| | Path | How |
|---|---|---|
| ✅ | Verify signatures on the raw body | `ShopifySignatureVerifier`, `RechargeSignatureVerifier`. Bad, missing or foreign-shop signatures return 401 and nothing is stored. |
| ✅ | Constant-time comparison | `hash_equals` everywhere |
| ✅ | Per-store secrets for Recharge | Secret looked up from the shop in the URL and stored encrypted (`encrypted` cast). Unknown shop returns 404. |
| ✅ | Replay-attack defense | Recharge: signed timestamp checked against a configurable window that defaults to **48h**. A 5-minute window would reject Recharge's own retries, which run for 2 days. Shopify sends **no signed timestamp** (only the body is signed; `Triggered-At` and `Webhook-Id` can be altered), so a time check there proves nothing. The real defense is layered: inbox dedup, then unique domain keys (one coverage per order), then the stale-`updated_at` guard on the signed body. A replayed body under a forged new `Webhook-Id` still can't create a second billable record (tested). |
| ✅ | HTTPS only | `EnsureWebhookIsSecure` returns 403 for plain HTTP in production. `TRUSTED_PROXIES` makes it work behind a load balancer that handles TLS. |
| ✅ | No CSRF or session on webhook routes | `routes/webhooks.php` is registered outside the `web` group; the signature is the authentication. |
| ✅ | PII at rest | Raw payloads are encrypted; signature headers are not stored. |
| ⏭️ | TLS and HSTS at the edge; request body size cap | Infrastructure |
| ⏭️ | IP allowlisting | Extra protection only. Shopify publishes no stable outbound IP list and advises against relying on IPs. If Recharge publishes one, enforce it at the load balancer, never instead of the HMAC check. |
| ⏭️ | Secret rotation | Accept old and new secrets during a rotation window |
| ⏭️ | Rate limiting | Must never return 429 to legitimate platform retries; protect at the edge instead |

### Reliability and correctness

| | Path | How |
|---|---|---|
| ✅ | Acknowledge within 5 seconds | The controller only verifies, stores and queues. All business logic runs in `ProcessWebhookEvent`. |
| ✅ | Idempotent ingest | Unique `(source, dedup_key)` plus `createOrFirst`. Duplicates get a 200 and are not queued again. |
| ✅ | Idempotent processing | Upserts on natural keys, a unique coverage per order, and an early return for events already processed |
| ✅ | Same order from two platforms | A recurring order arrives as Recharge `order/*` **and** Shopify `orders/create`. Both write to one `orders` row; billed once whichever arrives first (tested both ways). |
| ✅ | Out-of-order delivery | Results are recomputed from stored state; placeholder subscriptions; late links resolve when the missing record arrives (reverse-order scenario tested) |
| ✅ | Stale updates | `updated_at` guard per source |
| ✅ | Concurrency | Each event runs in a transaction with `lockForUpdate` on the rows it touches. Races on first insert hit a unique index, and the job retries. |
| ✅ | Retries with backoff and jitter | `ProcessWebhookEvent::backoff()`: roughly 10s, 30s, 90s, 270s and 810s, each plus up to 25% random delay |
| ✅ | Dead letter queue | Events that run out of retries stay in `webhook_events` with `status=failed` and `last_error`; `failed_jobs` holds the trace |
| ✅ | Replay after a fix | `webhooks:replay`, which reuses the same handlers, so it is safe (tested end to end) |
| ✅ | Unknown topics | Acknowledged and marked `ignored`, never failed |
| ⏭️ | Fetch latest on receive | For topics where order matters (status changes, cancellations), treat the webhook as a notification and re-fetch the resource from the API before applying it |
| ⏭️ | Per-resource ordering | Serialize jobs per order/subscription (`WithoutOverlapping` keyed by resource) instead of relying only on row locks |

### When something is down

| | Scenario | Handling |
|---|---|---|
| ✅ | **Our processing fails** (bug, database error) | Job retries with backoff, then lands in the dead letter queue. Fix it, then `webhooks:replay --status=failed`. |
| ✅ | **Our queue is down** (row stored, job never runs) | The event stays `received`. A scheduled `webhooks:replay --stuck` re-queues it. |
| ✅ | **We crash after storing but before responding** | The platform retries, dedup returns 200, and nothing happens twice. |
| ✅ (by design) | **Our endpoint is down briefly** | Shopify retries for 4h and Recharge for 48h; no data is lost within those windows. |
| ⏭️ | **Our endpoint is down longer than the retry window**, or the platform deleted our subscription | **Reconciliation**: `reconcile:shopify` (Admin GraphQL orders filtered by `updated_at`) and `reconcile:recharge` (`GET /orders` and `/subscriptions?updated_at_min=`, or `GET /events` within 7 days). Both write synthetic `reconciliation` events into the inbox, so the same handlers process them. Runs hourly over a trailing window, or on demand for a known outage. |
| ⏭️ | **Their system is down, delays or drops webhooks** | Same reconciliation sweep, plus **gap detection**: a covered, active subscription whose `next_charge_scheduled_at` has passed with no recurring order raises an alert and triggers reconciliation. |
| ⏭️ | **Our webhook subscriptions get deleted** after repeated failures (both platforms do this) | A scheduled health check (`GET /webhooks` on Recharge, `webhookSubscriptions` on Shopify) re-registers them, alerts, and starts reconciliation for the gap |
| ⏭️ | Outbound API client for all of the above | Backoff with jitter, honoring `429` and `Retry-After`, Shopify GraphQL cost limits and Recharge rate limits |

### Business and billing

| | Path | Notes |
|---|---|---|
| ✅ | Protection detected from the Shopify order | SKU first, vendor as fallback; discounts reduce the premium |
| ✅ | Subscription inherits coverage from its origin order | Through the Recharge checkout order |
| ✅ | Subscription cancelled or expired | Later orders not covered; earlier coverage kept |
| ✅ | Order cancelled | Coverage voided with a reason |
| ✅ | Queued Recharge orders | Not billed until placed |
| ⏭️ | Refunds and partial refunds | `refunds/create`, Recharge `charge/refunded` |
| ⏭️ | Order edits that add or remove protection | `orders/edited` (different payload shape) |
| ⏭️ | Involuntary cancellation | Recharge sends `charge/max_retries_reached` and **no** `subscription/cancelled` |
| ⏭️ | Skips, swaps, pauses, address merges; `subscription/deleted` | |
| ⏭️ | Prepaid subscriptions | `order/created` fires per scheduled shipment; bill when shipped, not when scheduled |
| ⏭️ | Append-only billing ledger | Replace the mutable coverage status with charge, void and adjustment entries (with idempotency keys), so closed billing periods are never rewritten and late events become adjustments in the next period |
| ⏭️ | Multi-currency | Shop vs. presentment money sets; zero- and three-decimal currencies; FX for invoices |
| ⏭️ | Other subscription platforms | The handler map is per source; add a verifier and handlers (e.g. Skio, Loop) |
| ❓ | Open policy questions | Does a refund or cancellation of the origin order revoke subscription coverage? Does coverage follow a product swap? What about checkouts with several subscriptions where only some items should be protected? |

### Operations and compliance

| | Path | How |
|---|---|---|
| ✅ | Structured request log | `LogWebhookRequest` writes source, topic, shop, delivery/event id, outcome (accepted, duplicate or rejected), status code and duration to the `webhooks` channel. **No body, signatures or PII.** |
| ✅ | Processing metrics per event | `attempts`, `processing_ms`, `last_error`, timestamps |
| ⏭️ | Alerting | On dead-letter count, inbox lag, signature-failure spikes and deleted webhook subscriptions |
| ⏭️ | Admin UI | Inspect and replay single events |
| ⏭️ | Queue infrastructure | Horizon or SQS, separate queues per source |
| ⏭️ | Shopify mandatory compliance webhooks | `customers/data_request`, `customers/redact`, `shop/redact`, plus `app/uninstalled` |
| ⏭️ | Payload retention | Scheduled pruning of raw payloads after the replay and audit window |

---

## Code map

| Path | What |
|---|---|
| `routes/webhooks.php` | The two webhook endpoints |
| `app/Http/Controllers/Webhooks/` | Verify, store, queue, acknowledge |
| `app/Http/Middleware/` | `LogWebhookRequest`, `EnsureWebhookIsSecure` |
| `app/Webhooks/Verification/` | Signature verifiers |
| `app/Webhooks/WebhookInbox.php` | Idempotent storage and dispatch |
| `app/Jobs/ProcessWebhookEvent.php` | Retries, backoff, dead letter |
| `app/Webhooks/WebhookEventProcessor.php` | Topic → handler routing, transaction, outcome |
| `app/Webhooks/Handlers/` | Shopify order, Recharge order and Recharge subscription handlers |
| `app/Webhooks/Payloads/` | Typed readers for the documented platform objects |
| `app/Coverage/CoverageService.php` | All coverage and billing rules |
| `app/Console/Commands/` | `webhooks:simulate`, `webhooks:replay`, `coverage:report` |
| `tests/Fixtures/` | Shopify and Recharge payloads following the documented schemas |
| `tests/Feature`, `tests/Unit` | 62 tests: signatures, dedup, lifecycle, ordering, replay, parsing |

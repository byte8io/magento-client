# Byte8 Client

Shared chassis for every Byte8 SaaS connector module on Magento 2. **Not directly merchant-facing** — installed transitively as a dependency of the per-provider modules (`byte8/module-sage-accounting`, future `byte8/module-xero`, …).

If you're a merchant, install one of the connector metapackages (e.g. `byte8/magento-sage-accounting`) — this module gets pulled in automatically.

If you're a Byte8 platform engineer building a new connector, this is the module you build on top of.

## Role in the stack

```
┌────────────────────────────────┐    ┌──────────────────────────┐
│  Magento 2                     │    │  ledger.byte8.io (SaaS)  │
│                                │    │                          │
│  ┌──────────────────────────┐  │    │  ┌────────────────────┐  │
│  │ byte8/module-sage-       │  │    │  │ Tenant + binding   │  │
│  │ accounting (or -xero …)  │  │    │  │ OAuth tokens       │  │
│  │                          │  │    │  │ Sage / Xero API    │  │
│  │   - observers            │  │    │  │ Retry + audit      │  │
│  │   - admin UI surfaces    │  │    │  │ Per-tenant rate    │  │
│  │   - per-provider config  │  │    │  │   limits           │  │
│  └────────────┬─────────────┘  │    │  └─────────┬──────────┘  │
│               │                │    │            │             │
│               ▼                │    │            ▼             │
│  ┌──────────────────────────┐  │    │  ┌────────────────────┐  │
│  │  byte8/module-client     │◄─┼────┼──┤  HTTP + JWT        │  │
│  │  (THIS MODULE)           │  │    │  │  Inbound + outbound│  │
│  │                          │  │    │  │                    │  │
│  │   - ByteClient publish   │  │    │  └────────────────────┘  │
│  │   - byte8_event_outbox   │  │    │                          │
│  │   - JWT verify + mint    │  │    │                          │
│  │   - canonical REST       │  │    │                          │
│  │   - sync-state mirror    │  │    │                          │
│  └──────────────────────────┘  │    │                          │
└────────────────────────────────┘    └──────────────────────────┘
```

This module owns the **wire**: outbox, JWT auth (both directions), canonical entity REST endpoints, and the Magento-side mirror of ledger sync state. Per-provider modules own the **events** that publish into this wire and the **admin UI surfaces** that the merchant sees.

By design, no PHP-side OAuth — that lives in the Byte8 Ledger SaaS, which talks to the actual provider APIs (`api.accounting.sage.com`, `api.xero.com`, …). Magento never touches a provider API directly.

## What this module provides

### `ByteClient` — outbound publish API

`Byte8\Client\Api\ByteClientInterface`. The single entry point per-provider observers use:

- **`enqueueEvent($eventName, $payload, $idempotencyKey?, $providerForMirror?)`** — outbox-only (no HTTP in the save transaction). The `OutboxDrain` cron picks it up within 60s. **Use this from observers.**
- **`publishEvent($eventName, $payload, $idempotencyKey?)`** — sync POST with outbox fallback on transient failure. Returns the ledger `sync_run_id` on success. Use only when the caller needs the id back immediately.
- **`fetchHealth()`** — cached tile health for the admin dashboard (30s TTL, swallows transient errors so a ledger outage doesn't break the dashboard).
- **`fetchStatus($entityType, $entityId)`** — per-entity sync-status lookup.
- **`disconnect()`** — best-effort tenant disconnect on revoke.

### Outbox

`byte8_event_outbox` table with three terminal states: `pending` / `succeeded` / `dead_lettered`. The classifier:

- **5xx / network / timeout** → `pending` with exponential backoff (10 attempts, ~7 days).
- **4xx (deterministic failure)** → `dead_lettered` on first attempt. No infinite-retry void.
- **Success** → `succeeded`. GC'd after 30 days by `OutboxGc` cron.

Operator triage:

```bash
bin/magento byte8:sage:outbox:inspect            # list dead-lettered rows
bin/magento byte8:sage:outbox:requeue <id>       # flip back to pending
bin/magento byte8:sage:outbox:cleanup --days=30  # purge succeeded
```

Dead-letter count surfaces as a banner on the admin config page (via the `ConnectionStatus` block in per-provider modules).

### Inbound REST surface

Under the `Byte8_Client::byte8_webapi` ACL, JWT-authed via `JwtUserContext`:

| Method | Path | Purpose |
| --- | --- | --- |
| `GET`  | `/V1/byte8/ping` | Liveness probe (ledger's proactive health loop) |
| `GET`  | `/V1/byte8/payment-methods` | Installed payment methods (for ledger's `payment_method_map` UI) |
| `GET`  | `/V1/byte8/invoice/:id` | Canonical invoice (snake_case shape per `ledger-core/src/canonical/invoice.rs`) |
| `GET`  | `/V1/byte8/customer/:id` | Canonical customer / contact |
| `GET`  | `/V1/byte8/creditmemo/:id` | Canonical credit memo (handles offline-payment refunds with no parent invoice) |
| `GET`  | `/V1/byte8/payment/:id` | Canonical payment |
| `GET`  | `/V1/byte8/product/:id` | Canonical product (with stock data) |
| `POST` | `/V1/byte8/sync-state` | Inbound from ledger worker — UPSERT `byte8_entity_sync_state` |

### JWT auth

- **HKDF-SHA256 subkeys** derived from the shared `api_key` per direction (`PURPOSE_INBOUND` / `PURPOSE_OUTBOUND`).
- **HS256 JWT** sign + verify with `iss` / `aud` / `iat` / `nbf` / `exp` validation, 5-min TTL ceiling.
- **JTI replay cache** (5-min Magento cache scope) to defend against captured-token replay.
- `JwtUserContext` runs ahead of Magento's `TokenUserContext` and grants the synthetic integration user when the bearer JWT verifies. `SyntheticAclPlugin` short-circuits ACL checks against `Byte8_Client::byte8_webapi`.

### Sync-state mirror (PR7)

`byte8_entity_sync_state` table, UNIQUE on `(entity_type, magento_id, provider)`. Read model only — never the source of truth. Two writers:

- **Magento-side write-through** at `enqueueEvent` time (when `$providerForMirror` is set) — UPSERTs `pending` row immediately so admin grids render the chip without waiting for the cron drain or ledger callback.
- **Ledger-side terminal callback** via `POST /V1/byte8/sync-state` — UPSERTs the terminal status (`synced` / `skipped` / `failed`) when the ledger sync_run terminates.

Per-provider modules render this via grid columns + admin info blocks — see `byte8/module-sage-accounting/Block/Adminhtml/SyncStatus/EntityInfo.php` for the canonical example.

## Adding a new connector module

A new `byte8/module-<provider>` module needs:

1. `composer.json` requiring `byte8/module-core` + `byte8/module-client`.
2. `etc/module.xml` with `<sequence>` listing `Byte8_Core` + `Byte8_Client`.
3. Per-provider config interface (mirror `Byte8\SageAccounting\Api\SageConfigInterface`) with a stable `PROVIDER_KEY` constant (`'sage_accounting'`, `'xero'`, …) — used as the `$providerForMirror` arg to `enqueueEvent`.
4. Observers on the entity events you want to sync — call `$this->byteClient->enqueueEvent($eventName, $payload, $idempotencyKey, ProviderConfig::PROVIDER_KEY)`. Stable idempotency keys (`invoice.created:{id}`).
5. Per-provider admin UI surfaces (Connect-flow blocks, settings page, sync-status grid columns) — copy the layout from `byte8/module-sage-accounting`.

You **don't** need to implement: outbox, drain cron, JWT auth, canonical entity getters, sync-state mirror table, dead-letter banner, retry logic, OAuth handshake. All in this module already.

## Database

`byte8_event_outbox` and `byte8_entity_sync_state`. Both managed via `etc/db_schema.xml` — `bin/magento setup:upgrade` applies them.

## Requirements

- PHP 8.1+ (8.4 supported)
- Magento Open Source / Adobe Commerce 2.4.4+
- MySQL 8.0+ / MariaDB 10.6+
- A configured Byte8 Ledger tenant (paired via the per-provider module's Connect flow)
- Outbound HTTPS to `ledger.byte8.io` (port 443)

## Console commands

```bash
bin/magento byte8:sage:outbox:inspect            # list dead-lettered events
bin/magento byte8:sage:outbox:requeue <id>       # requeue a dead-lettered event
bin/magento byte8:sage:outbox:cleanup [--days=N] # purge succeeded events older than N days
```

(The `byte8:sage:` prefix is historical — these commands are provider-agnostic and work for any connector.)

## Security

See [`SECURITY.md`](SECURITY.md) for the full operator runbook covering JWT key rotation, dead-letter triage, and incident-response playbooks.

## License

[MIT](LICENSE.txt)

## Support

Byte8 Ltd — support@byte8.io

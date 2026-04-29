<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api;

use Byte8\Client\Exception\BadRequestException;
use Byte8\Client\Exception\NotConnectedException;

/**
 * Thin trust-boundary HTTPS client to apps/ledger. Shared by Sage
 * Accounting, Xero, and any future Byte8 SaaS connector — per-provider
 * modules only supply the event name and payload body.
 *
 * Normal-path contract:
 *   - 2xx  → returns sync_run_id as a string (caller logs for audit)
 *   - 4xx  → throws BadRequestException (SaaS rejected; do not retry)
 *   - 5xx / network / timeout → event is written to byte8_event_outbox
 *                              and returns "" (empty string). The
 *                              OutboxDrain cron retries with exponential
 *                              backoff until the row is accepted or
 *                              expires at 24h worth of attempts.
 */
interface ByteClientInterface
{
    /**
     * Magento cache tag for the /v1/tile/health response. Exposed so per-
     * provider modules can invalidate the tile on state changes (e.g.
     * Disconnect) without coupling to the concrete ByteClient class.
     */
    public const HEALTH_CACHE_TAG = 'byte8_tile_health';

    /**
     * @param string $eventName Wire event name — matches the trailing segment of the
     *                          webhook URL (e.g. "invoice.paid", "creditmemo.created").
     * @param array  $payload   JSON-serialisable body. Must include at least
     *                          magento_entity_id; website_id / store_id / payload are
     *                          conventional per-event fields.
     * @param string|null $idempotencyKey Optional idempotency key used both as the
     *                          `Idempotency-Key` HTTP header and as the outbox unique
     *                          key. When omitted, one is derived from
     *                          sha1(eventName + canonical-json(payload)) — good enough
     *                          to dedupe observer re-fires but not semantic across
     *                          business-layer retries; callers with a stable business
     *                          key should pass it explicitly.
     *
     * @return string sync_run_id from apps/ledger, or "" if queued for retry.
     *
     * @throws BadRequestException    On 4xx from apps/ledger.
     * @throws NotConnectedException  When tenant_id or api_key are missing.
     */
    public function publishEvent(string $eventName, array $payload, ?string $idempotencyKey = null): string;

    /**
     * Outbox-only variant of `publishEvent`. Writes a single row to
     * `byte8_event_outbox` and returns without any HTTP — the
     * `OutboxDrain` cron (every 60s) picks it up.
     *
     * Use this from observers running inside the save transaction:
     *
     *   - `publishEvent` does a blocking POST to ledger (5s connect, 15s
     *     read) before falling back to the outbox. Three observers on
     *     the same save compound that cost on top of the merchant's
     *     Create Order click — unacceptable UX.
     *
     *   - `enqueueEvent` is O(1 INSERT): Create Order returns immediately,
     *     ledger learns of the event in ≤60s via the cron drain. For
     *     accounting sync this latency is invisible.
     *
     * Idempotency: the outbox `idempotency_key` column is UNIQUE. Repeat
     * enqueues with the same key are collapsed (the earlier row wins)
     * so observer re-fires, duplicate saves, etc. are safe by design.
     *
     * @param string      $eventName      Wire event name (e.g. `invoice.created`).
     * @param array       $payload        JSON-serialisable body.
     * @param string|null $idempotencyKey When omitted, derived from the payload.
     * @param string|null $providerForMirror  When set, also UPSERTs a `pending`
     *                                        row in `byte8_entity_sync_state`
     *                                        keyed on `(entity_type, magento_id,
     *                                        provider)` — derived from the
     *                                        event name's prefix
     *                                        (`invoice.created` → `invoice`)
     *                                        and `$payload['magento_entity_id']`.
     *                                        Drives the "Sage Status" chip
     *                                        on Magento admin grids without
     *                                        waiting for the ledger callback.
     *                                        Per-provider observers should
     *                                        pass their stable provider key
     *                                        (e.g. `SageConfigInterface::
     *                                        PROVIDER_KEY`). Best-effort: a
     *                                        mirror-write failure does NOT
     *                                        abort the outbox enqueue. PR7.
     *
     * @throws NotConnectedException When tenant_id or api_key are missing.
     */
    public function enqueueEvent(
        string $eventName,
        array $payload,
        ?string $idempotencyKey = null,
        ?string $providerForMirror = null
    ): void;

    /**
     * @throws BadRequestException    On 4xx.
     * @throws NotConnectedException  When tenant_id or api_key are missing.
     */
    public function fetchStatus(string $entityType, string $entityId): array;

    /**
     * Best-effort tenant disconnect. Called by per-provider Disconnect
     * controllers to tell ledger this tenant's binding is being revoked
     * locally (ledger marks its binding row revoked, stops polling, etc).
     *
     * Best-effort semantics: any transient failure is swallowed and
     * logged — local disconnect must always succeed from the merchant's
     * POV regardless of ledger reachability. Returns true when ledger
     * acknowledged the disconnect, false otherwise.
     */
    public function disconnect(): bool;

    /**
     * Tenant-level at-a-glance health used by the admin dashboard tile.
     * GET {base_url}/v1/tile/health — the contract is in LEDGER_INTEGRATION_SPEC §4.4.
     *
     * The result is cached in Magento's cache pool for 30 seconds under
     * cache tag `byte8_tile_health` so repeated dashboard renders don't
     * hammer the SaaS. On transient failure / pre-Connect state, an empty
     * array is returned — the tile block renders a graceful "unknown"
     * state rather than letting a ledger outage blow up the dashboard.
     *
     * @return array Decoded JSON body (structure per §4.4). Empty when
     *               the tenant isn't connected yet or ledger is
     *               unreachable — callers must tolerate missing keys.
     */
    public function fetchHealth(): array;
}

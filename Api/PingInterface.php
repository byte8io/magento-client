<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api;

/**
 * Liveness + auth-surface probe for ledger's proactive health loop.
 *
 * Deliberately a Byte8-owned route (guarded by the same
 * `Byte8_Client::byte8_webapi` resource as the canonical entity
 * repositories) so the probe exercises the exact auth path real calls
 * take — synthetic JWT user → SyntheticAclPlugin grant. Pointing the
 * probe at a Magento core route (e.g. `/rest/V1/store/storeConfigs`)
 * produces false-positive 401s because the synthetic user has no ACL
 * for core resources.
 *
 * Returns a tiny canonical envelope — no tenant-specific data, no side
 * effects. Logs nothing on success to avoid polluting `byte8.log` at
 * the probe cadence.
 */
interface PingInterface
{
    /**
     * @return array{ok: bool, tenant_id: string|null, server_time: string}
     */
    public function ping(): array;
}

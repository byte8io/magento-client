<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model;

use Byte8\Client\Api\ClientConfigInterface;
use Byte8\Client\Api\PingInterface;

/**
 * Minimal handler behind `GET /rest/V1/byte8/ping`. Reached only once
 * `JwtUserContext` + `SyntheticAclPlugin` have accepted the inbound JWT,
 * so the mere fact that this method runs is the signal ledger's probe
 * needs. The returned body is informational only.
 */
class Ping implements PingInterface
{
    public function __construct(
        private readonly ClientConfigInterface $config
    ) {
    }

    /**
     * @return array{ok: bool, tenant_id: string|null, server_time: string}
     */
    public function ping(): array
    {
        return [
            'ok' => true,
            'tenant_id' => $this->config->getTenantId(),
            'server_time' => gmdate('c'),
        ];
    }
}

<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Plugin\Authorization;

use Byte8\Client\Model\Authorization\JwtUserContext;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\Authorization;

/**
 * Around-plugin on `Magento\Framework\Authorization::isAllowed` that
 * short-circuits to `true` when:
 *   - the requested resource is Byte8_Client::byte8_webapi, AND
 *   - the current user context is the JwtUserContext-resolved synthetic
 *     integration (userId == SYNTHETIC_USER_ID && userType == INTEGRATION)
 *
 * For any other caller (real admin, real integration, customer) the
 * plugin delegates to the native implementation. We never grant wider
 * than the single Byte8_Client::byte8_webapi resource — see
 * `SECURITY.md §"Route surface"`.
 *
 * This plugin exists so we don't have to create a real
 * `authorization_role` + `authorization_rule` pair at install time for
 * the synthetic user. The rules would be fragile (magic user_id, no
 * integration row to bind to) and would leak into admin UIs. Keeping
 * the grant in code makes it auditable in one place and reviewable by
 * grep.
 */
class SyntheticAclPlugin
{
    public const GRANTED_RESOURCE = 'Byte8_Client::byte8_webapi';

    public function __construct(
        private readonly UserContextInterface $userContext
    ) {
    }

    /**
     * @param callable $proceed
     * @param string   $resource
     * @param string|null $privilege
     */
    public function aroundIsAllowed(
        Authorization $subject,
        callable $proceed,
        $resource,
        $privilege = null
    ): bool {
        if ($resource === self::GRANTED_RESOURCE && $this->isSyntheticUser()) {
            return true;
        }
        return $proceed($resource, $privilege);
    }

    private function isSyntheticUser(): bool
    {
        return (int) $this->userContext->getUserId() === JwtUserContext::SYNTHETIC_USER_ID
            && (int) $this->userContext->getUserType() === UserContextInterface::USER_TYPE_INTEGRATION;
    }
}

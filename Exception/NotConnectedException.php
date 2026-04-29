<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown when a publish/fetch is attempted before Connect has persisted
 * a tenant_id + api_key pair. Observers catch-and-log this rather than
 * bubbling to the admin user.
 */
class NotConnectedException extends LocalizedException
{
}

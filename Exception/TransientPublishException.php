<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * 5xx or network failure from apps/ledger. ByteClient catches this
 * internally and parks the event in the outbox for cron retry — it is
 * not re-thrown past the publishEvent() boundary under normal flow.
 * Observers remain unaware that delivery was delayed.
 */
class TransientPublishException extends LocalizedException
{
}

<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * 4xx response from apps/ledger — SaaS rejected the event (validation,
 * auth, missing tenant). Caller logs and drops; retry is pointless and
 * would just accumulate in the outbox forever.
 */
class BadRequestException extends LocalizedException
{
    public function __construct(
        \Magento\Framework\Phrase $phrase,
        private readonly int $httpStatus = 400,
        private readonly string $responseBody = '',
        ?\Exception $cause = null
    ) {
        parent::__construct($phrase, $cause);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }
}

<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\Canonical\Data;

use Byte8\Client\Api\Canonical\Data\PaymentInterface;

class Payment implements PaymentInterface
{
    /** @param array<string, mixed> $gatewayMetadata */
    public function __construct(
        private readonly int $magentoId,
        private readonly int $invoiceId,
        private readonly string $invoiceIncrementId,
        private readonly string $method,
        private readonly string $currency,
        private readonly float $amount,
        private readonly string $capturedAt,
        private readonly ?string $gatewayTxnId,
        private readonly array $gatewayMetadata
    ) {
    }

    public function getMagentoId(): int
    {
        return $this->magentoId;
    }

    public function getInvoiceId(): int
    {
        return $this->invoiceId;
    }

    public function getInvoiceIncrementId(): string
    {
        return $this->invoiceIncrementId;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCapturedAt(): string
    {
        return $this->capturedAt;
    }

    public function getGatewayTxnId(): ?string
    {
        return $this->gatewayTxnId;
    }

    public function getGatewayMetadata(): array
    {
        return $this->gatewayMetadata;
    }
}

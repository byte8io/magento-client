<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\Canonical\Data;

use Byte8\Client\Api\Canonical\Data\ContactInterface;
use Byte8\Client\Api\Canonical\Data\CreditMemoInterface;
use Byte8\Client\Api\Canonical\Data\InvoiceLineInterface;

class CreditMemo implements CreditMemoInterface
{
    /** @param InvoiceLineInterface[] $lines */
    public function __construct(
        private readonly int $magentoId,
        private readonly string $incrementId,
        private readonly ?int $invoiceId,
        private readonly ?string $invoiceIncrementId,
        private readonly int $websiteId,
        private readonly string $currency,
        private readonly ContactInterface $customer,
        private readonly array $lines,
        private readonly float $adjustmentPositive,
        private readonly float $adjustmentNegative,
        private readonly float $grandTotal,
        private readonly float $shippingAmount,
        private readonly float $shippingTaxAmount,
        private readonly ?string $reason,
        private readonly string $createdAt,
        private readonly ?string $invoiceCreatedAt = null,
        private readonly ?float $baseToOrderRate = null
    ) {
    }

    public function getMagentoId(): int
    {
        return $this->magentoId;
    }

    public function getIncrementId(): string
    {
        return $this->incrementId;
    }

    public function getInvoiceId(): ?int
    {
        return $this->invoiceId;
    }

    public function getInvoiceIncrementId(): ?string
    {
        return $this->invoiceIncrementId;
    }

    public function getWebsiteId(): int
    {
        return $this->websiteId;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCustomer(): ContactInterface
    {
        return $this->customer;
    }

    public function getLines(): array
    {
        return $this->lines;
    }

    public function getAdjustmentPositive(): float
    {
        return $this->adjustmentPositive;
    }

    public function getAdjustmentNegative(): float
    {
        return $this->adjustmentNegative;
    }

    public function getGrandTotal(): float
    {
        return $this->grandTotal;
    }

    public function getShippingAmount(): float
    {
        return $this->shippingAmount;
    }

    public function getShippingTaxAmount(): float
    {
        return $this->shippingTaxAmount;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getInvoiceCreatedAt(): ?string
    {
        return $this->invoiceCreatedAt;
    }

    public function getBaseToOrderRate(): ?float
    {
        return $this->baseToOrderRate;
    }
}

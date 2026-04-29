<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\Canonical\Data;

use Byte8\Client\Api\Canonical\Data\AddressInterface;
use Byte8\Client\Api\Canonical\Data\ContactInterface;
use Byte8\Client\Api\Canonical\Data\InvoiceInterface;
use Byte8\Client\Api\Canonical\Data\InvoiceLineInterface;

class Invoice implements InvoiceInterface
{
    /** @param InvoiceLineInterface[] $lines */
    public function __construct(
        private readonly int $magentoId,
        private readonly string $incrementId,
        private readonly int $orderId,
        private readonly string $orderIncrementId,
        private readonly int $websiteId,
        private readonly int $storeId,
        private readonly string $currency,
        private readonly string $state,
        private readonly ContactInterface $customer,
        private readonly array $lines,
        private readonly float $subtotal,
        private readonly float $taxAmount,
        private readonly float $shippingAmount,
        private readonly float $shippingTaxAmount,
        private readonly float $discountAmount,
        private readonly float $grandTotal,
        private readonly string $createdAt,
        private readonly ?string $paymentMethod = null,
        private readonly ?AddressInterface $billingAddress = null,
        private readonly ?AddressInterface $shippingAddress = null,
        private readonly ?string $poNumber = null,
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

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function getOrderIncrementId(): string
    {
        return $this->orderIncrementId;
    }

    public function getWebsiteId(): int
    {
        return $this->websiteId;
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getCustomer(): ContactInterface
    {
        return $this->customer;
    }

    public function getLines(): array
    {
        return $this->lines;
    }

    public function getSubtotal(): float
    {
        return $this->subtotal;
    }

    public function getTaxAmount(): float
    {
        return $this->taxAmount;
    }

    public function getShippingAmount(): float
    {
        return $this->shippingAmount;
    }

    public function getShippingTaxAmount(): float
    {
        return $this->shippingTaxAmount;
    }

    public function getDiscountAmount(): float
    {
        return $this->discountAmount;
    }

    public function getGrandTotal(): float
    {
        return $this->grandTotal;
    }

    public function getCreatedAt(): string
    {
        return $this->createdAt;
    }

    public function getPaymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    public function getBillingAddress(): ?AddressInterface
    {
        return $this->billingAddress;
    }

    public function getShippingAddress(): ?AddressInterface
    {
        return $this->shippingAddress;
    }

    public function getPoNumber(): ?string
    {
        return $this->poNumber;
    }

    public function getBaseToOrderRate(): ?float
    {
        return $this->baseToOrderRate;
    }
}

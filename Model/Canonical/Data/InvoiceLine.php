<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\Canonical\Data;

use Byte8\Client\Api\Canonical\Data\InvoiceLineInterface;

class InvoiceLine implements InvoiceLineInterface
{
    public function __construct(
        private readonly string $sku,
        private readonly string $name,
        private readonly float $qty,
        private readonly float $unitPrice,
        private readonly float $lineSubtotal,
        private readonly float $lineTax,
        private readonly float $lineTotal,
        private readonly ?float $taxRate,
        private readonly ?string $taxClass,
        private readonly ?string $productType = null
    ) {
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getQty(): float
    {
        return $this->qty;
    }

    public function getUnitPrice(): float
    {
        return $this->unitPrice;
    }

    public function getLineSubtotal(): float
    {
        return $this->lineSubtotal;
    }

    public function getLineTax(): float
    {
        return $this->lineTax;
    }

    public function getLineTotal(): float
    {
        return $this->lineTotal;
    }

    public function getTaxRate(): ?float
    {
        return $this->taxRate;
    }

    public function getTaxClass(): ?string
    {
        return $this->taxClass;
    }

    public function getProductType(): ?string
    {
        return $this->productType;
    }
}

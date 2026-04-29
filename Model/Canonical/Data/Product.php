<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\Canonical\Data;

use Byte8\Client\Api\Canonical\Data\ProductInterface;

class Product implements ProductInterface
{
    /** @param int[] $websiteIds */
    public function __construct(
        private readonly int $magentoId,
        private readonly string $sku,
        private readonly string $name,
        private readonly string $productType,
        private readonly ?float $price,
        private readonly ?float $specialPrice,
        private readonly ?float $cost,
        private readonly ?float $weight,
        private readonly ?float $stockQty,
        private readonly bool $manageStock,
        private readonly ?string $taxClass,
        private readonly array $websiteIds,
        private readonly ?string $createdAt,
        private readonly ?string $updatedAt
    ) {
    }

    public function getMagentoId(): int
    {
        return $this->magentoId;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getProductType(): string
    {
        return $this->productType;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function getSpecialPrice(): ?float
    {
        return $this->specialPrice;
    }

    public function getCost(): ?float
    {
        return $this->cost;
    }

    public function getWeight(): ?float
    {
        return $this->weight;
    }

    public function getStockQty(): ?float
    {
        return $this->stockQty;
    }

    public function getManageStock(): bool
    {
        return $this->manageStock;
    }

    public function getTaxClass(): ?string
    {
        return $this->taxClass;
    }

    public function getWebsiteIds(): array
    {
        return $this->websiteIds;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }
}

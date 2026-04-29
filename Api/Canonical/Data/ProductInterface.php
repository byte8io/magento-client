<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api\Canonical\Data;

/**
 * Mirror of `MagentoProduct` in
 * apps/ledger/server/crates/ledger-core/src/canonical/product.rs.
 *
 * `productType` is the Magento product-type code verbatim
 * ("simple", "virtual", "downloadable", "configurable", "bundle",
 * "grouped", "downloadable"). Ledger maps it onto Sage's three
 * catalog families (PRODUCT / SERVICE / STOCK_ITEM); composite
 * types (configurable / bundle / grouped) are skipped on the
 * ledger side.
 *
 * `price` / `specialPrice` / `cost` are nullable because Magento
 * configurable parents and grouped containers can have no price
 * of their own (the variants carry the prices). Stock-quantity
 * (`stockQty`) is reported but not consumed in PR4 — Scale-tier
 * stock sync is a separate slice.
 *
 * `websiteIds` is the array of Magento website IDs the product is
 * assigned to. Empty array means "all websites" for the merchant
 * but ledger treats absent + empty interchangeably.
 *
 * `createdAt` / `updatedAt` are RFC3339 UTC.
 *
 * Every getter carries `@return` (Magento webapi reflection
 * requirement — without it the JSON payload comes back as `[]`).
 */
interface ProductInterface
{
    /** @return int */
    public function getMagentoId(): int;

    /** @return string */
    public function getSku(): string;

    /** @return string */
    public function getName(): string;

    /** @return string */
    public function getProductType(): string;

    /** @return float|null */
    public function getPrice(): ?float;

    /** @return float|null */
    public function getSpecialPrice(): ?float;

    /** @return float|null */
    public function getCost(): ?float;

    /** @return float|null */
    public function getWeight(): ?float;

    /** @return float|null */
    public function getStockQty(): ?float;

    /** @return bool */
    public function getManageStock(): bool;

    /** @return string|null */
    public function getTaxClass(): ?string;

    /** @return int[] */
    public function getWebsiteIds(): array;

    /** @return string|null */
    public function getCreatedAt(): ?string;

    /** @return string|null */
    public function getUpdatedAt(): ?string;
}

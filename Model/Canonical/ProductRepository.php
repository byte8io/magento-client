<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\Canonical;

use Byte8\Client\Api\Canonical\Data\ProductInterface;
use Byte8\Client\Api\Canonical\ProductRepositoryInterface;
use Byte8\Client\Model\Canonical\Data\Product;
use Magento\Catalog\Api\Data\ProductInterface as MagentoProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface as MagentoProductRepository;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Translates `Magento\Catalog` products into the canonical `Product`
 * shape defined by `ledger-core::canonical::product::MagentoProduct`.
 *
 * Stock is fetched via `StockRegistryInterface` (default scope) — a
 * single source-of-truth read that works whether the merchant runs
 * legacy CataloginInventory or MSI on top of it. PR4 doesn't sync
 * stock to Sage; we still emit `stockQty` + `manageStock` so the
 * canonical payload is forward-compatible with PR5 stock_items sync.
 *
 * `productType` is the Magento type code verbatim (`simple`,
 * `virtual`, `downloadable`, `configurable`, `bundle`, `grouped`).
 * Mapping onto Sage's three catalog families
 * (PRODUCT / SERVICE / STOCK_ITEM) and the skip-policy for composite
 * types live in `ledger-sage-accounting::translate::product_from_magento`.
 */
class ProductRepository implements ProductRepositoryInterface
{
    public function __construct(
        private readonly MagentoProductRepository $productRepository,
        private readonly StockRegistryInterface $stockRegistry
    ) {
    }

    public function get(int $id): ProductInterface
    {
        $product = $this->productRepository->getById($id);

        [$stockQty, $manageStock] = $this->resolveStock($product);

        return new Product(
            magentoId: (int) $product->getId(),
            sku: (string) $product->getSku(),
            name: (string) $product->getName(),
            productType: (string) $product->getTypeId(),
            price: $this->nullableFloat($product->getPrice()),
            specialPrice: $this->nullableFloat($product->getSpecialPrice()),
            cost: $this->nullableFloat($product->getCost()),
            weight: $this->nullableFloat($product->getWeight()),
            stockQty: $stockQty,
            manageStock: $manageStock,
            taxClass: $this->nullIfEmpty((string) $product->getTaxClassId()),
            websiteIds: array_map('intval', $product->getWebsiteIds() ?? []),
            createdAt: $this->formatIso((string) $product->getCreatedAt()),
            updatedAt: $this->formatIso((string) $product->getUpdatedAt())
        );
    }

    /**
     * @return array{0: float|null, 1: bool}
     */
    private function resolveStock(MagentoProductInterface $product): array
    {
        try {
            $stockItem = $this->stockRegistry->getStockItem((int) $product->getId());
        } catch (NoSuchEntityException) {
            // Composite types (configurable / bundle / grouped) and
            // brand-new products may not have a stock_item row yet —
            // emit nulls and let ledger's policy decide what to do.
            return [null, false];
        }

        $qty = $stockItem->getQty();
        $manage = (bool) $stockItem->getManageStock();
        return [$qty === null ? null : (float) $qty, $manage];
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }

    private function nullIfEmpty(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }

    private function formatIso(string $mysqlTimestamp): ?string
    {
        if ($mysqlTimestamp === '') {
            return null;
        }
        $ts = strtotime($mysqlTimestamp);
        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}

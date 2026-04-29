<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api\Canonical;

use Byte8\Client\Api\Canonical\Data\ProductInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Canonical product reader. Exposed at `GET /V1/byte8/product/:id`.
 *
 * Called by `apps/ledger`'s worker when dispatching a
 * `JobKind::UpsertProduct` enqueued by the
 * `catalog_product_save_after` observer. Returns the Magento
 * canonical shape that `ledger-sage-accounting::translate::product_from_magento`
 * consumes.
 */
interface ProductRepositoryInterface
{
    /**
     * @param int $id Magento product entity id.
     * @return \Byte8\Client\Api\Canonical\Data\ProductInterface
     * @throws NoSuchEntityException
     */
    public function get(int $id): ProductInterface;
}

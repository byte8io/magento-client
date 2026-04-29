<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api\Canonical;

use Byte8\Client\Api\Canonical\Data\InvoiceInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Provider-agnostic canonical invoice reader. Exposed at
 * `GET /V1/byte8/invoice/:id` and consumed by `apps/ledger` to
 * materialise an invoice for translation into Sage / Xero / future
 * provider shapes.
 *
 * Returns the Magento canonical shape — DO NOT filter or transform for
 * a specific provider here. Provider-specific translation lives in
 * `apps/ledger/server/crates/ledger-<provider>/`.
 */
interface InvoiceRepositoryInterface
{
    /**
     * @param int $id Magento invoice entity id (not increment id).
     * @return \Byte8\Client\Api\Canonical\Data\InvoiceInterface
     * @throws NoSuchEntityException when the invoice does not exist.
     */
    public function get(int $id): InvoiceInterface;
}

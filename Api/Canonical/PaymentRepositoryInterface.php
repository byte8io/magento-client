<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api\Canonical;

use Byte8\Client\Api\Canonical\Data\PaymentInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Canonical payment reader. Exposed at `GET /V1/byte8/payment/:id`.
 *
 * NOTE on id semantics: Magento's payment entity (`sales_order_payment`)
 * is 1:1 with an order. What ledger calls "a payment" is really an
 * invoice-level capture — we surface the invoice's captured amount
 * here, keyed by the invoice id passed in. See PaymentRepository
 * implementation for the resolution rule.
 */
interface PaymentRepositoryInterface
{
    /**
     * @param int $id Magento invoice id (not the legacy sales_order_payment id).
     * @return \Byte8\Client\Api\Canonical\Data\PaymentInterface
     * @throws NoSuchEntityException
     */
    public function get(int $id): PaymentInterface;
}

<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api\Canonical;

use Byte8\Client\Api\Canonical\Data\CreditMemoInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Canonical credit memo reader. Exposed at `GET /V1/byte8/creditmemo/:id`.
 */
interface CreditMemoRepositoryInterface
{
    /**
     * @param int $id Magento credit memo entity id.
     * @return \Byte8\Client\Api\Canonical\Data\CreditMemoInterface
     * @throws NoSuchEntityException
     */
    public function get(int $id): CreditMemoInterface;
}

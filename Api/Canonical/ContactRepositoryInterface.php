<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api\Canonical;

use Byte8\Client\Api\Canonical\Data\ContactInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Canonical customer reader. Exposed at `GET /V1/byte8/customer/:id`.
 */
interface ContactRepositoryInterface
{
    /**
     * @param int $id Magento customer entity id.
     * @return \Byte8\Client\Api\Canonical\Data\ContactInterface
     * @throws NoSuchEntityException when the customer does not exist.
     */
    public function get(int $id): ContactInterface;
}

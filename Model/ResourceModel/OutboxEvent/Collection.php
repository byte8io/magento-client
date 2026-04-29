<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\ResourceModel\OutboxEvent;

use Byte8\Client\Api\Data\OutboxEventInterface;
use Byte8\Client\Model\OutboxEvent;
use Byte8\Client\Model\ResourceModel\OutboxEvent as OutboxEventResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = OutboxEventInterface::ENTITY_ID;

    protected function _construct()
    {
        $this->_init(OutboxEvent::class, OutboxEventResource::class);
    }
}

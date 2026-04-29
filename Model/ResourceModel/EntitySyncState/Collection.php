<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\ResourceModel\EntitySyncState;

use Byte8\Client\Api\Data\EntitySyncStateInterface;
use Byte8\Client\Model\EntitySyncState;
use Byte8\Client\Model\ResourceModel\EntitySyncState as EntitySyncStateResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = EntitySyncStateInterface::ENTITY_ID;

    protected function _construct()
    {
        $this->_init(EntitySyncState::class, EntitySyncStateResource::class);
    }
}

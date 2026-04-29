<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\ResourceModel;

use Byte8\Client\Api\Data\EntitySyncStateInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class EntitySyncState extends AbstractDb
{
    protected function _construct()
    {
        $this->_init(EntitySyncStateInterface::DB_TABLE_NAME, EntitySyncStateInterface::ENTITY_ID);
    }
}

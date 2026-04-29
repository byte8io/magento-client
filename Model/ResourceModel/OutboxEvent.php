<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\ResourceModel;

use Byte8\Client\Api\Data\OutboxEventInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class OutboxEvent extends AbstractDb
{
    protected function _construct()
    {
        $this->_init(OutboxEventInterface::DB_TABLE_NAME, OutboxEventInterface::ENTITY_ID);
    }
}

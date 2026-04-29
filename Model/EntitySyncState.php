<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model;

use Byte8\Client\Api\Data\EntitySyncStateInterface;
use Magento\Framework\Model\AbstractModel;

class EntitySyncState extends AbstractModel implements EntitySyncStateInterface
{
    protected function _construct()
    {
        $this->_init(ResourceModel\EntitySyncState::class);
    }

    public function getEntityId(): ?int
    {
        $value = $this->getData(self::ENTITY_ID);
        return null === $value ? null : (int) $value;
    }

    public function getEntityType(): string
    {
        return (string) $this->getData(self::ENTITY_TYPE);
    }

    public function setEntityType(string $entityType): self
    {
        return $this->setData(self::ENTITY_TYPE, $entityType);
    }

    public function getMagentoId(): int
    {
        return (int) $this->getData(self::MAGENTO_ID);
    }

    public function setMagentoId(int $magentoId): self
    {
        return $this->setData(self::MAGENTO_ID, $magentoId);
    }

    public function getProvider(): string
    {
        return (string) $this->getData(self::PROVIDER);
    }

    public function setProvider(string $provider): self
    {
        return $this->setData(self::PROVIDER, $provider);
    }

    public function getSyncStatus(): string
    {
        $value = $this->getData(self::SYNC_STATUS);
        return $value === null || $value === '' ? self::STATUS_PENDING : (string) $value;
    }

    public function setSyncStatus(string $syncStatus): self
    {
        return $this->setData(self::SYNC_STATUS, $syncStatus);
    }

    public function getProviderEntityId(): ?string
    {
        $value = $this->getData(self::PROVIDER_ENTITY_ID);
        return null === $value ? null : (string) $value;
    }

    public function setProviderEntityId(?string $providerEntityId): self
    {
        return $this->setData(self::PROVIDER_ENTITY_ID, $providerEntityId);
    }

    public function getProviderReference(): ?string
    {
        $value = $this->getData(self::PROVIDER_REFERENCE);
        return null === $value ? null : (string) $value;
    }

    public function setProviderReference(?string $providerReference): self
    {
        return $this->setData(self::PROVIDER_REFERENCE, $providerReference);
    }

    public function getSkipReason(): ?string
    {
        $value = $this->getData(self::SKIP_REASON);
        return null === $value ? null : (string) $value;
    }

    public function setSkipReason(?string $skipReason): self
    {
        return $this->setData(self::SKIP_REASON, $skipReason);
    }

    public function getErrorCode(): ?string
    {
        $value = $this->getData(self::ERROR_CODE);
        return null === $value ? null : (string) $value;
    }

    public function setErrorCode(?string $errorCode): self
    {
        return $this->setData(self::ERROR_CODE, $errorCode);
    }

    public function getLastSyncAt(): ?string
    {
        $value = $this->getData(self::LAST_SYNC_AT);
        return null === $value ? null : (string) $value;
    }

    public function setLastSyncAt(string $lastSyncAt): self
    {
        return $this->setData(self::LAST_SYNC_AT, $lastSyncAt);
    }
}

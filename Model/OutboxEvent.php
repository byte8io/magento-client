<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model;

use Byte8\Client\Api\Data\OutboxEventInterface;
use Magento\Framework\Model\AbstractModel;

class OutboxEvent extends AbstractModel implements OutboxEventInterface
{
    protected function _construct()
    {
        $this->_init(ResourceModel\OutboxEvent::class);
    }

    public function getEntityId(): ?int
    {
        $value = $this->getData(self::ENTITY_ID);
        return null === $value ? null : (int) $value;
    }

    public function getIdempotencyKey(): ?string
    {
        $value = $this->getData(self::IDEMPOTENCY_KEY);
        return null === $value ? null : (string) $value;
    }

    public function setIdempotencyKey(string $idempotencyKey): self
    {
        return $this->setData(self::IDEMPOTENCY_KEY, $idempotencyKey);
    }

    public function getEventName(): ?string
    {
        $value = $this->getData(self::EVENT_NAME);
        return null === $value ? null : (string) $value;
    }

    public function setEventName(string $eventName): self
    {
        return $this->setData(self::EVENT_NAME, $eventName);
    }

    public function getPayload(): ?string
    {
        $value = $this->getData(self::PAYLOAD);
        return null === $value ? null : (string) $value;
    }

    public function setPayload(string $payload): self
    {
        return $this->setData(self::PAYLOAD, $payload);
    }

    public function getStatus(): string
    {
        $value = $this->getData(self::STATUS);
        return $value === null || $value === '' ? self::STATUS_PENDING : (string) $value;
    }

    public function setStatus(string $status): self
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getAttempts(): int
    {
        return (int) $this->getData(self::ATTEMPTS);
    }

    public function setAttempts(int $attempts): self
    {
        return $this->setData(self::ATTEMPTS, $attempts);
    }

    public function getLastStatusCode(): ?int
    {
        $value = $this->getData(self::LAST_STATUS_CODE);
        return $value === null ? null : (int) $value;
    }

    public function setLastStatusCode(?int $code): self
    {
        return $this->setData(self::LAST_STATUS_CODE, $code);
    }

    public function getLastAttemptAt(): ?string
    {
        $value = $this->getData(self::LAST_ATTEMPT_AT);
        return $value === null ? null : (string) $value;
    }

    public function setLastAttemptAt(?string $ts): self
    {
        return $this->setData(self::LAST_ATTEMPT_AT, $ts);
    }

    public function getNextAttemptAt(): ?string
    {
        $value = $this->getData(self::NEXT_ATTEMPT_AT);
        return null === $value ? null : (string) $value;
    }

    public function setNextAttemptAt(?string $nextAttemptAt): self
    {
        return $this->setData(self::NEXT_ATTEMPT_AT, $nextAttemptAt);
    }

    public function getLastError(): ?string
    {
        $value = $this->getData(self::LAST_ERROR);
        return null === $value ? null : (string) $value;
    }

    public function setLastError(?string $lastError): self
    {
        return $this->setData(self::LAST_ERROR, $lastError);
    }

    public function getCreatedAt(): ?string
    {
        $value = $this->getData(self::CREATED_AT);
        return null === $value ? null : (string) $value;
    }
}

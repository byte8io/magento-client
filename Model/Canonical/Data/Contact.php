<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\Canonical\Data;

use Byte8\Client\Api\Canonical\Data\AddressInterface;
use Byte8\Client\Api\Canonical\Data\ContactInterface;

class Contact implements ContactInterface
{
    /** @param AddressInterface[] $addresses */
    public function __construct(
        private readonly int $magentoId,
        private readonly string $email,
        private readonly ?string $firstName,
        private readonly ?string $lastName,
        private readonly ?string $company,
        private readonly ?string $phone,
        private readonly int $websiteId,
        private readonly ?int $groupId,
        private readonly array $addresses,
        private readonly ?string $createdAt,
        private readonly ?string $updatedAt
    ) {
    }

    public function getMagentoId(): int
    {
        return $this->magentoId;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function getWebsiteId(): int
    {
        return $this->websiteId;
    }

    public function getGroupId(): ?int
    {
        return $this->groupId;
    }

    public function getAddresses(): array
    {
        return $this->addresses;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }
}

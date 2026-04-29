<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\Canonical\Data;

use Byte8\Client\Api\Canonical\Data\AddressInterface;

/**
 * Plain value object — the webapi framework introspects the interface
 * getters and serialises. We deliberately keep this off
 * AbstractExtensibleObject so output never leaks `extension_attributes`
 * or other Magento-framework detritus into the canonical JSON.
 */
class Address implements AddressInterface
{
    /** @param string[] $street */
    public function __construct(
        private readonly string $kind,
        private readonly array $street,
        private readonly string $city,
        private readonly ?string $region,
        private readonly ?string $postcode,
        private readonly string $countryId,
        private readonly ?string $telephone
    ) {
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getStreet(): array
    {
        return $this->street;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function getPostcode(): ?string
    {
        return $this->postcode;
    }

    public function getCountryId(): string
    {
        return $this->countryId;
    }

    public function getTelephone(): ?string
    {
        return $this->telephone;
    }
}

<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api\Canonical\Data;

/**
 * Mirror of `MagentoContact` in
 * apps/ledger/server/crates/ledger-core/src/canonical/contact.rs.
 *
 * Returned directly as the /V1/byte8/customer/:id response and nested
 * as `customer` inside the invoice response. `created_at` / `updated_at`
 * are RFC3339 UTC strings — chrono::DateTime<Utc> on the Rust side.
 *
 * Every getter carries `@return` (Magento webapi reflection requirement).
 */
interface ContactInterface
{
    /** @return int */
    public function getMagentoId(): int;

    /** @return string */
    public function getEmail(): string;

    /** @return string|null */
    public function getFirstName(): ?string;

    /** @return string|null */
    public function getLastName(): ?string;

    /** @return string|null */
    public function getCompany(): ?string;

    /** @return string|null */
    public function getPhone(): ?string;

    /** @return int */
    public function getWebsiteId(): int;

    /** @return int|null */
    public function getGroupId(): ?int;

    /** @return \Byte8\Client\Api\Canonical\Data\AddressInterface[] */
    public function getAddresses(): array;

    /** @return string|null */
    public function getCreatedAt(): ?string;

    /** @return string|null */
    public function getUpdatedAt(): ?string;
}

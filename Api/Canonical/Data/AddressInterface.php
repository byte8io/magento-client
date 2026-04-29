<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api\Canonical\Data;

/**
 * Mirror of `MagentoAddress` in
 * apps/ledger/server/crates/ledger-core/src/canonical/contact.rs.
 *
 * Drift detection: keep the field list in lock-step with the Rust struct.
 * When canonical/contact.rs changes, this interface and its concrete
 * implementation must change in the same PR or canonical JSON emission
 * will fail to deserialize on the ledger side.
 *
 * `kind` is a string enum ("billing" | "shipping") — matches Rust's
 * `#[serde(rename_all = "snake_case")]` on the `AddressKind` enum.
 *
 * Every getter carries `@return` (Magento webapi reflection requirement).
 */
interface AddressInterface
{
    public const KIND_BILLING  = 'billing';
    public const KIND_SHIPPING = 'shipping';

    /** @return string */
    public function getKind(): string;

    /** @return string[] */
    public function getStreet(): array;

    /** @return string */
    public function getCity(): string;

    /** @return string|null */
    public function getRegion(): ?string;

    /** @return string|null */
    public function getPostcode(): ?string;

    /** @return string */
    public function getCountryId(): string;

    /** @return string|null */
    public function getTelephone(): ?string;
}

<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api\Canonical\Data;

/**
 * Mirror of `MagentoInvoiceLine` in
 * apps/ledger/server/crates/ledger-core/src/canonical/invoice.rs.
 *
 * Monetary fields are floats with 2-decimal precision on the wire.
 * tax_rate is a decimal ratio (0.20 for 20%), not a percentage.
 *
 * Every getter carries a `@return` annotation — Magento's webapi
 * reflection (`Magento\Framework\Reflection\TypeProcessor`) requires
 * one per method on every type reachable from a webapi route, even
 * for methods with typed PHP returns. Missing annotations cause a
 * 500 with "Method's return type must be specified using @return"
 * the next time `setup:di:compile` invalidates the methods-map cache.
 */
interface InvoiceLineInterface
{
    /** @return string */
    public function getSku(): string;

    /** @return string */
    public function getName(): string;

    /** @return float */
    public function getQty(): float;

    /** @return float */
    public function getUnitPrice(): float;

    /** @return float */
    public function getLineSubtotal(): float;

    /** @return float */
    public function getLineTax(): float;

    /** @return float */
    public function getLineTotal(): float;

    /** @return float|null */
    public function getTaxRate(): ?float;

    /** @return string|null */
    public function getTaxClass(): ?string;

    /**
     * Magento product type code verbatim — `simple`, `virtual`,
     * `downloadable`, `configurable`, `bundle`, `grouped`. Sourced
     * from `$item->getProductType()` (free getter, no extra DB
     * round-trip). Used by ledger to resolve Sage's per-line
     * `eu_goods_services_type_id` on cross-border invoices.
     *
     * @return string|null
     */
    public function getProductType(): ?string;
}

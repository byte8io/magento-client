<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api\Canonical\Data;

/**
 * Mirror of `MagentoInvoice` in
 * apps/ledger/server/crates/ledger-core/src/canonical/invoice.rs.
 *
 * `state` is the string rendering of the Rust `InvoiceState` enum:
 *   "open"      — invoice created, unpaid
 *   "paid"      — invoice captured
 *   "cancelled" — invoice voided
 * Produced via `#[serde(rename_all = "snake_case")]` on the Rust side.
 *
 * `created_at` is RFC3339 UTC.
 *
 * Every getter carries a `@return` annotation — Magento's webapi
 * reflection requires one per method on every type reachable from a
 * webapi route, even for methods with typed PHP returns. Missing
 * annotations cause a 500 the next time `setup:di:compile`
 * invalidates the methods-map cache.
 */
interface InvoiceInterface
{
    public const STATE_OPEN      = 'open';
    public const STATE_PAID      = 'paid';
    public const STATE_CANCELLED = 'cancelled';

    /** @return int */
    public function getMagentoId(): int;

    /** @return string */
    public function getIncrementId(): string;

    /** @return int */
    public function getOrderId(): int;

    /** @return string */
    public function getOrderIncrementId(): string;

    /** @return int */
    public function getWebsiteId(): int;

    /** @return int */
    public function getStoreId(): int;

    /** @return string */
    public function getCurrency(): string;

    /** @return string */
    public function getState(): string;

    /** @return \Byte8\Client\Api\Canonical\Data\ContactInterface */
    public function getCustomer(): ContactInterface;

    /** @return \Byte8\Client\Api\Canonical\Data\InvoiceLineInterface[] */
    public function getLines(): array;

    /** @return float */
    public function getSubtotal(): float;

    /** @return float */
    public function getTaxAmount(): float;

    /** @return float */
    public function getShippingAmount(): float;

    /**
     * Tax on the shipping amount (Magento's
     * `$invoice->getShippingTaxAmount()`). Carried separately so the
     * Sage translate layer can populate the invoice-level
     * `shipping_tax_amount` scalar without reverse-engineering it from
     * `tax_amount` minus the per-line tax sum (penny-rounding hazard).
     * Zero on tax-exempt orders / regions where shipping isn't taxed.
     *
     * @return float
     */
    public function getShippingTaxAmount(): float;

    /** @return float */
    public function getDiscountAmount(): float;

    /** @return float */
    public function getGrandTotal(): float;

    /** @return string */
    public function getCreatedAt(): string;

    /**
     * Magento payment-method code (e.g. `stripe_payments`, `checkmo`,
     * `cashondelivery`). Pulled from the order's payment row at canonical
     * fetch time. Nullable: programmatic / fixture invoices may have no
     * payment object attached. Ledger uses this to look up the matching
     * bank-account override in `sync_policy.payment_method_map`; absent
     * methods fall back to `default_bank_account_id`.
     *
     * @return string|null
     */
    public function getPaymentMethod(): ?string;

    /**
     * The order's billing address — what should appear as the Sage
     * invoice's "Invoice Address". Sourced from
     * `order.getBillingAddress()`. Distinct from the contact-level
     * default; an order can be billed to an address other than the
     * customer's profile-default. Nullable for unusual orders that
     * have no billing row at all (very rare; data-quality issue).
     *
     * @return \Byte8\Client\Api\Canonical\Data\AddressInterface|null
     */
    public function getBillingAddress(): ?AddressInterface;

    /**
     * The order's shipping address — populates Sage's "Delivery
     * Address". Sourced from `order.getShippingAddress()`. Without
     * this, Sage mirrors the billing address into the delivery slot,
     * silently corrupting the merchant's record (the SI-11 bug).
     * Nullable for digital / virtual orders that legitimately have
     * no shipping address.
     *
     * @return \Byte8\Client\Api\Canonical\Data\AddressInterface|null
     */
    public function getShippingAddress(): ?AddressInterface;

    /**
     * Customer-supplied Purchase Order reference for B2B flows
     * (Magento's "Purchase Order Number" field on checkout).
     * Sourced from `order.getPayment().getPoNumber()`. Ledger
     * surfaces this on the Sage invoice's notes field as
     * "Purchase Order: {value}" so accountants reconciling against
     * a paper PO can match without round-tripping to Magento.
     * Nullable — only the `purchaseorder` payment method (and a few
     * customisations) populate it.
     *
     * @return string|null
     */
    public function getPoNumber(): ?string;

    /**
     * FX rate from invoice transaction currency to merchant's base
     * currency (`$invoice->getBaseToOrderRate()` — a free getter on
     * `Magento\Sales\Api\Data\InvoiceInterface`). Semantics:
     * `base_amount = order_amount * baseToOrderRate`.
     *
     * For single-currency stores Magento emits `1.0` and ledger
     * omits Sage's `exchange_rate` field entirely (Sage 422s
     * non-1.0 rate when invoice currency matches contact currency).
     * For multi-currency stores the value is the recorded rate at
     * order time (snapshot, not today's daily rate).
     *
     * Nullable — pre-PR6 module versions / synthetic test invoices
     * may not emit it; ledger treats `null` as "no FX info, behave
     * single-currency".
     *
     * @return float|null
     */
    public function getBaseToOrderRate(): ?float;
}

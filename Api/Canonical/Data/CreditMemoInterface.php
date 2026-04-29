<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api\Canonical\Data;

/**
 * Mirror of `MagentoCreditMemo` in
 * apps/ledger/server/crates/ledger-core/src/canonical/credit_memo.rs.
 *
 * `lines` reuses the same `InvoiceLineInterface` shape as a regular
 * invoice — a credit memo line has the same sku/qty/price/tax columns,
 * just representing a return or discount rather than an original sale.
 *
 * `adjustment_positive` / `adjustment_negative` capture the Magento
 * refund-fee / store-credit adjustments that don't fit line-by-line.
 *
 * `created_at` is RFC3339 UTC.
 *
 * Every getter carries `@return` (Magento webapi reflection requirement).
 */
interface CreditMemoInterface
{
    /** @return int */
    public function getMagentoId(): int;

    /** @return string */
    public function getIncrementId(): string;

    /**
     * Magento invoice id this credit memo refunds, or null when the
     * credit memo was created against the order rather than a specific
     * invoice. Offline payment methods (bank transfer, check / money
     * order) don't expose the "Credit Memo" button on the invoice
     * page in the Magento admin — the only way to refund them is from
     * the order itself, which produces a credit memo with no
     * `invoice_id`. The Sage side must fall back to the order
     * reference / credit-memo own ref for `notes` linkage.
     *
     * @return int|null
     */
    public function getInvoiceId(): ?int;

    /** @return string|null */
    public function getInvoiceIncrementId(): ?string;

    /** @return int */
    public function getWebsiteId(): int;

    /** @return string */
    public function getCurrency(): string;

    /** @return \Byte8\Client\Api\Canonical\Data\ContactInterface */
    public function getCustomer(): ContactInterface;

    /** @return \Byte8\Client\Api\Canonical\Data\InvoiceLineInterface[] */
    public function getLines(): array;

    /** @return float */
    public function getAdjustmentPositive(): float;

    /** @return float */
    public function getAdjustmentNegative(): float;

    /** @return float */
    public function getGrandTotal(): float;

    /**
     * Shipping refund aggregated at the credit memo header (Magento
     * doesn't model shipping as a refundable line item — it's a scalar
     * on the credit memo / invoice / order). Mirrors
     * `InvoiceInterface::getShippingAmount()`. The Sage translate
     * layer surfaces this on Sage's invoice-level shipping panel
     * (`shipping_net_amount`) so the credit note's totals reconcile
     * with `grand_total` and shipping is rendered in its dedicated
     * panel rather than as a synthetic "Shipping & Handling" line.
     *
     * @return float
     */
    public function getShippingAmount(): float;

    /**
     * Tax refunded on the shipping amount (Magento's
     * `$memo->getShippingTaxAmount()`). Carried alongside
     * `getShippingAmount()` so the Sage translate layer can populate
     * Sage's invoice-level `shipping_tax_amount` scalar. Zero on
     * tax-exempt regions / orders.
     *
     * @return float
     */
    public function getShippingTaxAmount(): float;

    /** @return string|null */
    public function getReason(): ?string;

    /** @return string */
    public function getCreatedAt(): string;

    /**
     * Created date of the parent sales invoice (Magento's
     * `$invoice->getCreatedAt()`). Populates Sage's
     * `original_invoice_date` field on credit notes — Sage's
     * reports key credit-vs-invoice linkage on this so a credit
     * issued in May for an April invoice still rolls up under
     * April. Null for credit memos created from an order rather
     * than a specific invoice (offline-payment refunds — the
     * Sage translate layer falls back to the credit memo's own
     * date in that case).
     *
     * @return string|null
     */
    public function getInvoiceCreatedAt(): ?string;

    /**
     * FX rate from credit-memo transaction currency to merchant's
     * base currency. Mirrors `InvoiceInterface::getBaseToOrderRate()`
     * — see that for full semantics. Sage's `sales_credit_notes`
     * carries currency the same way as `sales_invoices`, so credit
     * notes for foreign-currency orders need the matching
     * exchange_rate propagation.
     *
     * @return float|null
     */
    public function getBaseToOrderRate(): ?float;
}

<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\Canonical;

use Byte8\Client\Api\Canonical\CreditMemoRepositoryInterface;
use Byte8\Client\Api\Canonical\Data\ContactInterface;
use Byte8\Client\Api\Canonical\Data\CreditMemoInterface;
use Byte8\Client\Api\Canonical\Data\InvoiceLineInterface;
use Byte8\Client\Model\Canonical\Data\CreditMemo;
use Byte8\Client\Model\Canonical\Data\InvoiceLine;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\CreditmemoRepositoryInterface as MagentoCreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\CreditmemoInterface as MagentoCreditmemoInterface;
use Magento\Sales\Api\Data\CreditmemoItemInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Api\StoreRepositoryInterface;

/**
 * Translates Magento credit memo + its parent invoice + order + customer
 * into the canonical CreditMemo shape defined by
 * `ledger-core::canonical::credit_memo::MagentoCreditMemo`.
 *
 * Reason resolution: Magento stores credit memo rationales in a
 * `sales_creditmemo_comment` table. We surface the first comment's body
 * if present; if the merchant didn't leave a comment the canonical
 * `reason` is null.
 */
class CreditMemoRepository implements CreditMemoRepositoryInterface
{
    public function __construct(
        private readonly MagentoCreditmemoRepositoryInterface $creditmemoRepository,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly ContactRepository $contactRepository,
        private readonly InvoiceRepository $invoiceCanonicalRepository,
        private readonly StoreRepositoryInterface $storeRepository
    ) {
    }

    public function get(int $id): CreditMemoInterface
    {
        $memo = $this->creditmemoRepository->get($id);

        // Offline payment methods (bank transfer, check / money order)
        // don't show the "Credit Memo" button on the invoice page in
        // the Magento admin — refunds for those orders MUST be created
        // from the order itself, which produces a credit memo with
        // `invoice_id = NULL`. Don't throw; emit nullable invoice
        // fields and let Sage fall back to the order / credit-memo
        // own reference for the invoice-linkage display.
        $invoiceId = (int) $memo->getInvoiceId();
        $invoiceIncrementId = null;
        $invoiceCreatedAt = null;
        if ($invoiceId > 0) {
            $invoice = $this->invoiceRepository->get($invoiceId);
            $invoiceIncrementId = (string) $invoice->getIncrementId();
            // Preserve the parent invoice's date for Sage's
            // `original_invoice_date` field — keeps credit-vs-invoice
            // reconciliation rolled up under the original reporting
            // period when a credit is issued months later.
            $invoiceCreatedAt = $this->formatIso((string) $invoice->getCreatedAt());
        } else {
            $invoiceId = null;
        }

        $order = $this->orderRepository->get((int) $memo->getOrderId());
        $websiteId = (int) $this->storeRepository->getById((int) $memo->getStoreId())->getWebsiteId();
        $currency = (string) ($memo->getOrderCurrencyCode() ?: $memo->getBaseCurrencyCode() ?: $order->getOrderCurrencyCode());

        return new CreditMemo(
            magentoId: (int) $memo->getEntityId(),
            incrementId: (string) $memo->getIncrementId(),
            invoiceId: $invoiceId,
            invoiceIncrementId: $invoiceIncrementId,
            websiteId: $websiteId,
            currency: $currency,
            customer: $this->resolveCustomer($order),
            lines: $this->mapLines($memo),
            adjustmentPositive: (float) $memo->getAdjustmentPositive(),
            adjustmentNegative: (float) $memo->getAdjustmentNegative(),
            grandTotal: (float) $memo->getGrandTotal(),
            shippingAmount: (float) $memo->getShippingAmount(),
            shippingTaxAmount: (float) $memo->getShippingTaxAmount(),
            reason: $this->firstComment($memo),
            createdAt: $this->formatIso((string) $memo->getCreatedAt()),
            invoiceCreatedAt: $invoiceCreatedAt,
            // FX rate snapshot — same semantics as InvoiceRepository.
            // Magento records `base_to_order_rate` on the credit memo
            // entity itself (not derived from the order); use the
            // memo's own value so partially-refunded multi-currency
            // orders still translate cleanly.
            baseToOrderRate: $memo->getBaseToOrderRate() === null
                ? null
                : (float) $memo->getBaseToOrderRate()
        );
    }

    private function resolveCustomer(OrderInterface $order): ContactInterface
    {
        $customerId = $order->getCustomerId();
        if ($customerId !== null && (int) $customerId > 0) {
            try {
                return $this->contactRepository->toCanonical(
                    $this->customerRepository->getById((int) $customerId)
                );
            } catch (NoSuchEntityException) {
                // Customer deleted — fall through.
            }
        }
        return $this->invoiceCanonicalRepository->syntheticGuestContactForOrder($order);
    }

    /**
     * @return InvoiceLineInterface[]
     */
    private function mapLines(MagentoCreditmemoInterface $memo): array
    {
        $lines = [];
        foreach ($memo->getItems() ?? [] as $item) {
            $lines[] = $this->mapLine($item);
        }
        return $lines;
    }

    private function mapLine(CreditmemoItemInterface $item): InvoiceLine
    {
        $rowTotal = (float) $item->getRowTotal();
        $taxAmount = (float) $item->getTaxAmount();
        $taxRate = null;
        if ($rowTotal > 0.0 && $taxAmount > 0.0) {
            $taxRate = round($taxAmount / $rowTotal, 4);
        }

        // CreditmemoItemInterface doesn't expose getProductType() as
        // a first-class typed getter (Magento's interface predates the
        // attribute), but the underlying entity carries it via the
        // generic getData() seam. Use it so cross-border credit notes
        // get the same eu_goods_services_type_id resolution as
        // invoices (PR6 multi-currency).
        $productType = method_exists($item, 'getProductType')
            ? $item->getProductType()
            : $item->getData('product_type');

        return new InvoiceLine(
            sku: (string) $item->getSku(),
            name: (string) $item->getName(),
            qty: (float) $item->getQty(),
            unitPrice: (float) $item->getPrice(),
            lineSubtotal: $rowTotal,
            lineTax: $taxAmount,
            lineTotal: $rowTotal + $taxAmount - (float) $item->getDiscountAmount(),
            taxRate: $taxRate,
            taxClass: null,
            productType: ($productType === null || $productType === '') ? null : (string) $productType
        );
    }

    private function firstComment(MagentoCreditmemoInterface $memo): ?string
    {
        $comments = $memo->getComments();
        if (!$comments) {
            return null;
        }
        foreach ($comments as $comment) {
            $body = $comment->getComment();
            if (is_string($body) && $body !== '') {
                return $body;
            }
        }
        return null;
    }

    private function formatIso(string $mysqlTimestamp): string
    {
        if ($mysqlTimestamp === '') {
            return '';
        }
        $ts = strtotime($mysqlTimestamp);
        return $ts === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}

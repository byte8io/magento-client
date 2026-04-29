<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\Canonical;

use Byte8\Client\Api\Canonical\Data\PaymentInterface;
use Byte8\Client\Api\Canonical\PaymentRepositoryInterface;
use Byte8\Client\Model\Canonical\Data\Payment;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Canonical payment resolver.
 *
 * Magento has no "invoice-level capture" entity — `sales_order_payment`
 * is order-scoped and represents the gateway integration, not a
 * discrete capture event. But from an accounting POV, what ledger needs
 * to post to Sage's /contact_payments is one row per invoice captured.
 *
 * So the contract is: ledger passes the **invoice id** as `:id`, we
 * return the invoice's captured amount as a payment. The observer on
 * `sales_order_payment_pay` fires when the order's payment captures —
 * pair that with the invoice that was created at the same moment (the
 * observer publishes the invoice id, not the payment id, for this
 * reason).
 *
 * `gateway_metadata` is the order payment's `additional_information`
 * array verbatim — gateway-specific extras (Stripe charge id, 3DS
 * outcome, Klarna order id, etc.) for audit trail.
 */
class PaymentRepository implements PaymentRepositoryInterface
{
    public function __construct(
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    public function get(int $id): PaymentInterface
    {
        $invoice = $this->invoiceRepository->get($id);
        $order = $this->orderRepository->get((int) $invoice->getOrderId());
        $payment = $order->getPayment();
        if ($payment === null) {
            throw new NoSuchEntityException(
                __('Invoice %1 has no associated order payment.', $id)
            );
        }

        $currency = (string) ($invoice->getOrderCurrencyCode() ?: $invoice->getBaseCurrencyCode() ?: $order->getOrderCurrencyCode());
        $method = (string) $payment->getMethod();
        $lastTxn = $payment->getLastTransId();
        $additionalInfo = $payment->getAdditionalInformation();
        if (!is_array($additionalInfo)) {
            $additionalInfo = [];
        }

        return new Payment(
            magentoId: (int) $invoice->getEntityId(),
            invoiceId: (int) $invoice->getEntityId(),
            invoiceIncrementId: (string) $invoice->getIncrementId(),
            method: $method,
            currency: $currency,
            amount: (float) $invoice->getGrandTotal(),
            capturedAt: $this->formatIso((string) $invoice->getCreatedAt()),
            gatewayTxnId: is_string($lastTxn) && $lastTxn !== '' ? $lastTxn : null,
            gatewayMetadata: $additionalInfo
        );
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

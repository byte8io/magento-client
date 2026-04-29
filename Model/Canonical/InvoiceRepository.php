<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\Canonical;

use Byte8\Client\Api\Canonical\Data\AddressInterface;
use Byte8\Client\Api\Canonical\Data\ContactInterface;
use Byte8\Client\Api\Canonical\Data\InvoiceInterface;
use Byte8\Client\Api\Canonical\Data\InvoiceLineInterface;
use Byte8\Client\Api\Canonical\InvoiceRepositoryInterface;
use Byte8\Client\Model\Canonical\Data\Address;
use Byte8\Client\Model\Canonical\Data\Contact;
use Byte8\Client\Model\Canonical\Data\Invoice;
use Byte8\Client\Model\Canonical\Data\InvoiceLine;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\InvoiceInterface as MagentoInvoiceInterface;
use Magento\Sales\Api\Data\InvoiceItemInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface as MagentoInvoiceRepository;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Invoice as InvoiceModel;
use Magento\Store\Api\StoreRepositoryInterface;

/**
 * Translates Magento invoice + order + customer data into the canonical
 * Invoice shape defined by `ledger-core::canonical::invoice::MagentoInvoice`.
 *
 * `customer` resolution:
 *   - registered buyer: load via CustomerRepositoryInterface and emit the
 *     canonical Contact from that
 *   - guest buyer:      synthesize a Contact from the order's billing
 *     address + order.customer_email / firstname / lastname. Magento has
 *     no customer entity for guests, so magento_id is emitted as 0 to
 *     signal "synthetic, do not look up". Ledger should branch on 0 and
 *     use the embedded fields directly rather than GETting /customer/:0
 */
class InvoiceRepository implements InvoiceRepositoryInterface
{
    public function __construct(
        private readonly MagentoInvoiceRepository $invoiceRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly ContactRepository $contactRepository,
        private readonly StoreRepositoryInterface $storeRepository
    ) {
    }

    public function get(int $id): InvoiceInterface
    {
        $invoice = $this->invoiceRepository->get($id);
        $order = $this->orderRepository->get((int) $invoice->getOrderId());
        $customer = $this->resolveCustomer($order);
        $websiteId = (int) $this->storeRepository->getById((int) $invoice->getStoreId())->getWebsiteId();
        $currency = (string) ($invoice->getOrderCurrencyCode() ?: $invoice->getBaseCurrencyCode() ?: $order->getOrderCurrencyCode());

        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();

        return new Invoice(
            magentoId: (int) $invoice->getEntityId(),
            incrementId: (string) $invoice->getIncrementId(),
            orderId: (int) $order->getEntityId(),
            orderIncrementId: (string) $order->getIncrementId(),
            websiteId: $websiteId,
            storeId: (int) $invoice->getStoreId(),
            currency: $currency,
            state: $this->mapState((int) $invoice->getState()),
            customer: $customer,
            lines: $this->mapLines($invoice),
            subtotal: (float) $invoice->getSubtotal(),
            taxAmount: (float) $invoice->getTaxAmount(),
            shippingAmount: (float) $invoice->getShippingAmount(),
            shippingTaxAmount: (float) $invoice->getShippingTaxAmount(),
            discountAmount: (float) $invoice->getDiscountAmount(),
            grandTotal: (float) $invoice->getGrandTotal(),
            createdAt: $this->formatIso((string) $invoice->getCreatedAt()),
            paymentMethod: $this->resolvePaymentMethod($order),
            billingAddress: $billing
                ? $this->orderAddressToCanonical($billing, AddressInterface::KIND_BILLING)
                : null,
            shippingAddress: $shipping
                ? $this->orderAddressToCanonical($shipping, AddressInterface::KIND_SHIPPING)
                : null,
            poNumber: $this->resolvePoNumber($order),
            // FX rate snapshot at order time. Magento records both
            // base_to_order_rate and order_to_base_rate on the
            // invoice; ledger consumes the former (base = order *
            // rate). Nullable so legacy modules / synthetic
            // fixtures that don't set the field still serialise.
            // Single-currency stores naturally emit `1.0`.
            baseToOrderRate: $invoice->getBaseToOrderRate() === null
                ? null
                : (float) $invoice->getBaseToOrderRate()
        );
    }

    /**
     * Customer-supplied PO reference. Magento exposes this on the
     * order's payment row via `getPoNumber()` for the `purchaseorder`
     * method (and any custom payment that respects the same convention).
     * Nullable: most B2C orders won't have one.
     */
    private function resolvePoNumber(OrderInterface $order): ?string
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return null;
        }
        // getPoNumber() lives on the OrderPaymentInterface for the
        // purchaseorder method; for other methods it returns null/empty.
        $po = method_exists($payment, 'getPoNumber') ? $payment->getPoNumber() : null;
        return ($po === null || $po === '') ? null : (string) $po;
    }

    /**
     * Pull the Magento payment-method code from the order. Nullable —
     * orders without a payment row (programmatic / fixture / future
     * non-payment workflows) are valid and ledger handles the None
     * case via `default_bank_account_id`.
     */
    private function resolvePaymentMethod(OrderInterface $order): ?string
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return null;
        }
        $method = $payment->getMethod();
        return ($method === null || $method === '') ? null : (string) $method;
    }

    private function resolveCustomer(OrderInterface $order): ContactInterface
    {
        $customerId = $order->getCustomerId();
        if ($customerId !== null && (int) $customerId > 0) {
            try {
                $contact = $this->contactRepository->toCanonical(
                    $this->customerRepository->getById((int) $customerId)
                );
                // Registered customers often have empty profile addresses
                // (first-time buyer, no default-billing set, or checkout
                // used a one-off address). Magento's order row is the
                // authoritative billing/shipping for THIS invoice
                // anyway, so enrich the contact with the order's
                // addresses whenever the profile didn't supply them.
                // Without this, Sage 422s with "Invoice Address is
                // required" on POST /sales_invoices.
                return $this->ensureInvoiceableAddresses($contact, $order);
            } catch (NoSuchEntityException) {
                // Customer deleted after checkout — fall through to guest path.
            }
        }

        return $this->syntheticGuestContact($order);
    }

    /**
     * If the profile-sourced contact has no billing (and/or shipping)
     * address, fill the gap from the order. Preserves any profile
     * address that IS present — we only ever add, never overwrite —
     * so a merchant who's gone to the trouble of curating addresses
     * on the customer record still sees them in Sage.
     *
     * Returns a fresh Contact instance because `Contact` is immutable
     * (readonly promoted constructor props).
     */
    private function ensureInvoiceableAddresses(
        ContactInterface $contact,
        OrderInterface $order
    ): ContactInterface {
        $hasBilling = false;
        $hasShipping = false;
        foreach ($contact->getAddresses() as $addr) {
            if ($addr->getKind() === AddressInterface::KIND_BILLING) {
                $hasBilling = true;
            }
            if ($addr->getKind() === AddressInterface::KIND_SHIPPING) {
                $hasShipping = true;
            }
        }
        if ($hasBilling && $hasShipping) {
            return $contact;
        }

        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();

        $extras = [];
        if (!$hasBilling && $billing) {
            $extras[] = $this->orderAddressToCanonical($billing, AddressInterface::KIND_BILLING);
        }
        if (!$hasShipping && $shipping && $this->addressesAreDifferent($billing, $shipping)) {
            $extras[] = $this->orderAddressToCanonical($shipping, AddressInterface::KIND_SHIPPING);
        }

        if ($extras === []) {
            return $contact;
        }

        return new Contact(
            magentoId: $contact->getMagentoId(),
            email: (string) $contact->getEmail(),
            firstName: $contact->getFirstName(),
            lastName: $contact->getLastName(),
            // Company lives on addresses in Magento; if the profile
            // didn't carry it and the order's billing has one, promote
            // it to the contact level so Sage contact listings show
            // the company name for B2B customers.
            company: $contact->getCompany() ?? $this->nullIfEmpty(
                $billing ? $billing->getCompany() : null
            ),
            phone: $contact->getPhone() ?? $this->nullIfEmpty(
                $billing ? $billing->getTelephone() : null
            ),
            websiteId: $contact->getWebsiteId(),
            groupId: $contact->getGroupId(),
            addresses: array_merge($contact->getAddresses(), $extras),
            createdAt: $contact->getCreatedAt(),
            updatedAt: $contact->getUpdatedAt()
        );
    }

    /**
     * Public so the CreditMemoRepository can reuse the exact same
     * guest-contact logic without forking the branching rules.
     * Returns a Contact with magento_id=0 to signal "synthetic" — ledger
     * must NOT attempt GET /customer/0 on these.
     */
    public function syntheticGuestContactForOrder(OrderInterface $order): ContactInterface
    {
        return $this->syntheticGuestContact($order);
    }

    private function syntheticGuestContact(OrderInterface $order): ContactInterface
    {
        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();

        $addresses = [];
        if ($billing) {
            $addresses[] = $this->orderAddressToCanonical($billing, AddressInterface::KIND_BILLING);
        }
        if ($shipping && $this->addressesAreDifferent($billing, $shipping)) {
            $addresses[] = $this->orderAddressToCanonical($shipping, AddressInterface::KIND_SHIPPING);
        }

        return new Contact(
            magentoId: 0, // synthetic — ledger must not GET /customer/:0
            email: (string) $order->getCustomerEmail(),
            firstName: $this->nullIfEmpty($order->getCustomerFirstname()),
            lastName: $this->nullIfEmpty($order->getCustomerLastname()),
            company: $billing ? $this->nullIfEmpty($billing->getCompany()) : null,
            phone: $billing ? $this->nullIfEmpty($billing->getTelephone()) : null,
            websiteId: 0, // unknown for guests at this layer; ledger derives from order.website_id
            groupId: null,
            addresses: $addresses,
            createdAt: $this->formatIso((string) $order->getCreatedAt()),
            updatedAt: $this->formatIso((string) $order->getUpdatedAt())
        );
    }

    private function orderAddressToCanonical(OrderAddressInterface $address, string $kind): Address
    {
        $street = $address->getStreet() ?? [];
        return new Address(
            kind: $kind,
            street: array_values(array_filter(array_map('strval', $street), fn (string $s): bool => $s !== '')),
            city: (string) $address->getCity(),
            region: $this->nullIfEmpty($address->getRegion()),
            postcode: $this->nullIfEmpty($address->getPostcode()),
            countryId: (string) $address->getCountryId(),
            telephone: $this->nullIfEmpty($address->getTelephone())
        );
    }

    private function addressesAreDifferent(
        ?OrderAddressInterface $a,
        ?OrderAddressInterface $b
    ): bool {
        if ($a === null || $b === null) {
            return true;
        }
        return (int) $a->getEntityId() !== (int) $b->getEntityId();
    }

    /**
     * @return InvoiceLineInterface[]
     */
    private function mapLines(MagentoInvoiceInterface $invoice): array
    {
        $lines = [];
        foreach ($invoice->getItems() ?? [] as $item) {
            $lines[] = $this->mapLine($item);
        }
        return $lines;
    }

    private function mapLine(InvoiceItemInterface $item): InvoiceLine
    {
        $rowTotal = (float) $item->getRowTotal();
        $taxAmount = (float) $item->getTaxAmount();
        $taxRate = null;
        if ($rowTotal > 0.0 && $taxAmount > 0.0) {
            // Derive rate from totals; Magento's invoice-item row doesn't
            // carry the applied-tax percentage directly in this API layer.
            // Ledger-side may cross-check against reference_cache.tax_rates.
            $taxRate = round($taxAmount / $rowTotal, 4);
        }

        $productType = $item->getProductType();

        return new InvoiceLine(
            sku: (string) $item->getSku(),
            name: (string) $item->getName(),
            qty: (float) $item->getQty(),
            unitPrice: (float) $item->getPrice(),
            lineSubtotal: $rowTotal,
            lineTax: $taxAmount,
            lineTotal: $rowTotal + $taxAmount - (float) $item->getDiscountAmount(),
            taxRate: $taxRate,
            // tax_class not exposed on the invoice-item repository; loading the
            // product for every line would add N queries. Deferred — ledger
            // has the same tax_class info via the reference_cache snapshot.
            taxClass: null,
            // product_type drives Sage's per-line eu_goods_services_type_id
            // resolution on cross-border invoices (PR6 multi-currency).
            // `getProductType()` is a free getter on InvoiceItemInterface
            // — no extra DB round-trip per line.
            productType: ($productType === null || $productType === '') ? null : (string) $productType
        );
    }

    private function mapState(int $magentoState): string
    {
        return match ($magentoState) {
            InvoiceModel::STATE_OPEN      => InvoiceInterface::STATE_OPEN,
            InvoiceModel::STATE_PAID      => InvoiceInterface::STATE_PAID,
            InvoiceModel::STATE_CANCELED  => InvoiceInterface::STATE_CANCELLED,
            default                       => InvoiceInterface::STATE_OPEN,
        };
    }

    private function nullIfEmpty(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }

    private function formatIso(string $mysqlTimestamp): string
    {
        if ($mysqlTimestamp === '') {
            return '';
        }
        $ts = strtotime($mysqlTimestamp);
        if ($ts === false) {
            return '';
        }
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}

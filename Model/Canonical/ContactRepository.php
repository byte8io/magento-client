<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\Canonical;

use Byte8\Client\Api\Canonical\ContactRepositoryInterface;
use Byte8\Client\Api\Canonical\Data\AddressInterface;
use Byte8\Client\Api\Canonical\Data\ContactInterface;
use Byte8\Client\Model\Canonical\Data\Address;
use Byte8\Client\Model\Canonical\Data\Contact;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface as MagentoAddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Translates `Magento\Customer` entities into the canonical `Contact`
 * shape defined by `ledger-core::canonical::contact::MagentoContact`.
 *
 * Address emission: we emit one canonical Address per default flag set
 * on the Magento customer — if an address is both default_billing AND
 * default_shipping, it appears twice (once per kind). Non-default
 * addresses are skipped for PR2. This matches how accounting providers
 * model contact→address relations (Sage Business Cloud keeps billing/
 * shipping as distinct contact-address rows).
 */
class ContactRepository implements ContactRepositoryInterface
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder
    ) {
    }

    public function get(int $id): ContactInterface
    {
        $customer = $this->customerRepository->getById($id);
        return $this->toCanonical($customer);
    }

    public function toCanonical(CustomerInterface $customer): ContactInterface
    {
        $addresses = $this->collectAddresses($customer);
        $companyFromOrder = null;
        $phoneFromOrder = null;

        // Profile-saved addresses are commonly empty for first-time
        // buyers, guest-checkout-then-registered flows, or merchants
        // whose checkout doesn't auto-save address-to-account. Sage's
        // POST /sales_invoices 422s if the contact has no
        // invoice-able address, so fall back to the most recent
        // order's billing/shipping when the profile has nothing.
        // Without this fallback, the customer.upserted webhook would
        // persistently create addressless contacts that subsequent
        // invoice posts can't use.
        if ($addresses === []) {
            [$addresses, $companyFromOrder, $phoneFromOrder] =
                $this->fallbackFromMostRecentOrder($customer);
        }

        return new Contact(
            magentoId: (int) $customer->getId(),
            email: (string) $customer->getEmail(),
            firstName: $this->nullIfEmpty($customer->getFirstname()),
            lastName: $this->nullIfEmpty($customer->getLastname()),
            company: $companyFromOrder,
            phone: $this->primaryPhone($customer) ?? $phoneFromOrder,
            websiteId: (int) $customer->getWebsiteId(),
            groupId: $customer->getGroupId() !== null ? (int) $customer->getGroupId() : null,
            addresses: $addresses,
            createdAt: $this->formatIso($customer->getCreatedAt()),
            updatedAt: $this->formatIso($customer->getUpdatedAt())
        );
    }

    /**
     * Fetch the customer's most recent order and emit canonical
     * billing (and, if different, shipping) addresses from it. Also
     * returns the company + telephone from the order's billing
     * address — useful for B2B contacts whose company name lives on
     * the order, not the profile.
     *
     * @return array{0: AddressInterface[], 1: string|null, 2: string|null}
     */
    private function fallbackFromMostRecentOrder(CustomerInterface $customer): array
    {
        $sortByCreatedAtDesc = $this->sortOrderBuilder
            ->setField('created_at')
            ->setDirection(SortOrder::SORT_DESC)
            ->create();
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('customer_id', (int) $customer->getId())
            ->setSortOrders([$sortByCreatedAtDesc])
            ->setPageSize(1)
            ->create();

        $orders = $this->orderRepository->getList($criteria)->getItems();
        $order = $orders[0] ?? null;
        if ($order === null) {
            return [[], null, null];
        }

        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();

        $addresses = [];
        if ($billing) {
            $addresses[] = $this->orderAddressToCanonical($billing, AddressInterface::KIND_BILLING);
        }
        if ($shipping && (!$billing || (int) $shipping->getEntityId() !== (int) $billing->getEntityId())) {
            $addresses[] = $this->orderAddressToCanonical($shipping, AddressInterface::KIND_SHIPPING);
        }

        $company = $billing ? $this->nullIfEmpty($billing->getCompany()) : null;
        $phone = $billing ? $this->nullIfEmpty($billing->getTelephone()) : null;

        return [$addresses, $company, $phone];
    }

    /**
     * Inline conversion from a sales-order address to the canonical
     * `Address`. Mirrors `InvoiceRepository::orderAddressToCanonical`
     * — kept inline rather than extracted to a shared helper because
     * the two repositories have different upstream types
     * (CustomerAddress vs OrderAddress) and unifying them would be
     * more boilerplate than the duplication.
     */
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

    /** @return AddressInterface[] */
    private function collectAddresses(CustomerInterface $customer): array
    {
        $defaultBillingId = (string) $customer->getDefaultBilling();
        $defaultShippingId = (string) $customer->getDefaultShipping();

        $canonical = [];
        foreach ($customer->getAddresses() ?? [] as $address) {
            $addressId = (string) $address->getId();
            if ($addressId !== '' && $addressId === $defaultBillingId) {
                $canonical[] = $this->toCanonicalAddress($address, AddressInterface::KIND_BILLING);
            }
            if ($addressId !== '' && $addressId === $defaultShippingId) {
                $canonical[] = $this->toCanonicalAddress($address, AddressInterface::KIND_SHIPPING);
            }
        }
        return $canonical;
    }

    private function toCanonicalAddress(MagentoAddressInterface $address, string $kind): Address
    {
        $street = $address->getStreet() ?? [];
        $region = $address->getRegion()?->getRegion();

        return new Address(
            kind: $kind,
            street: array_values(array_filter(array_map('strval', $street), fn (string $s): bool => $s !== '')),
            city: (string) $address->getCity(),
            region: $this->nullIfEmpty($region),
            postcode: $this->nullIfEmpty($address->getPostcode()),
            countryId: (string) $address->getCountryId(),
            telephone: $this->nullIfEmpty($address->getTelephone())
        );
    }

    private function primaryPhone(CustomerInterface $customer): ?string
    {
        $defaultBillingId = (string) $customer->getDefaultBilling();
        foreach ($customer->getAddresses() ?? [] as $address) {
            if ((string) $address->getId() === $defaultBillingId) {
                return $this->nullIfEmpty($address->getTelephone());
            }
        }
        // Fallback: first non-empty telephone on any address.
        foreach ($customer->getAddresses() ?? [] as $address) {
            $phone = $this->nullIfEmpty($address->getTelephone());
            if ($phone !== null) {
                return $phone;
            }
        }
        return null;
    }

    private function nullIfEmpty(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }

    private function formatIso(?string $mysqlTimestamp): ?string
    {
        if ($mysqlTimestamp === null || $mysqlTimestamp === '') {
            return null;
        }
        $ts = strtotime($mysqlTimestamp);
        if ($ts === false) {
            return null;
        }
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}

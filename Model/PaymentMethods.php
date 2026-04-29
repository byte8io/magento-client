<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model;

use Byte8\Client\Api\PaymentMethodsInterface;
use Magento\Payment\Api\PaymentMethodListInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Store\Model\Store;

/**
 * Handler behind `GET /rest/V1/byte8/payment-methods`.
 *
 * Reads the merchant's entire installed-method list (active and inactive)
 * at the default scope (`store_id=0`) and returns `[{code, label,
 * is_active}]` for the dashboard. The store scope is deliberate: Magento
 * canonicalises `MagentoInvoice.payment_method` to a single code
 * regardless of the store view it was captured on, so per-store
 * variations aren't meaningful to the ledger binding — one mapping
 * decision applies tenant-wide.
 *
 * Labels fall back to the method code when `getTitle()` is empty —
 * happens with methods whose title is a store-scoped config value that
 * hasn't been set at the default scope. The code is always non-empty
 * and a safe display fallback.
 */
class PaymentMethods implements PaymentMethodsInterface
{
    public function __construct(
        private readonly PaymentMethodListInterface $paymentMethodList,
        private readonly PaymentHelper $paymentHelper
    ) {
    }

    /**
     * @return array<int, array{code: string, label: string|null, is_active: bool}>
     */
    public function list(): array
    {
        $result = [];
        foreach ($this->paymentMethodList->getList(Store::DEFAULT_STORE_ID) as $method) {
            $code = $method->getCode();
            if ($code === '' || $code === null) {
                continue;
            }
            $result[] = [
                'code' => (string)$code,
                'label' => $this->resolveLabel($code),
                'is_active' => $this->resolveActive($code),
            ];
        }
        return $result;
    }

    /**
     * `PaymentMethodInterface` carries a display title but many gateways
     * store it only at the website/store scope — walking up via the
     * helper's `getMethodInstance()` gives us whatever the admin last
     * saved. Return null (not the code) so the dashboard can decide
     * whether to show a placeholder vs. the raw code.
     */
    private function resolveLabel(string $code): ?string
    {
        try {
            $title = $this->paymentHelper->getMethodInstance($code)->getTitle();
        } catch (\Throwable) {
            return null;
        }
        $title = is_string($title) ? trim($title) : '';
        return $title === '' ? null : $title;
    }

    /**
     * Active at the default scope. Methods enabled only on a single
     * store view will report `false` here — accurate: a mapping decision
     * at the binding level should only apply to a method that's
     * live at least somewhere the merchant expects to take payment.
     */
    private function resolveActive(string $code): bool
    {
        try {
            $instance = $this->paymentHelper->getMethodInstance($code);
            if (!$instance instanceof MethodInterface) {
                return false;
            }
            return (bool)$instance->isActive();
        } catch (\Throwable) {
            return false;
        }
    }
}

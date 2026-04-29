<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api;

/**
 * Lists Magento payment methods so the ledger dashboard can render a
 * dropdown of real, installed codes instead of a free-form text field.
 *
 * Consumed by `apps/ledger` via `GET /rest/V1/byte8/payment-methods`,
 * which the dashboard proxies through its
 * `GET /v1/bindings/{id}/magento-payment-methods` endpoint. The ledger
 * side tolerates 404 (module not yet deployed) and falls back to the
 * text input — so this route is safely additive.
 *
 * Active-only? No. We return *every* installed method (including
 * currently-disabled ones) so merchants who temporarily disabled a
 * gateway during the year end can still see historical codes when
 * reviewing past sync runs. `is_active` reflects the current global
 * (store_id=0) enablement so the dashboard can de-emphasise inactive
 * rows without hiding them.
 */
interface PaymentMethodsInterface
{
    /**
     * @return array<int, array{code: string, label: string|null, is_active: bool}>
     */
    public function list(): array;
}

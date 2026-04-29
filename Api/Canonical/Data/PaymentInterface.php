<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api\Canonical\Data;

/**
 * Mirror of `MagentoPayment` in
 * apps/ledger/server/crates/ledger-core/src/canonical/payment.rs.
 *
 * `method` is the Magento payment method code (e.g. "stripe_payments",
 * "paypal_express"), NOT the human-readable title. Ledger maps method
 * codes to provider-side payment types via reference data.
 *
 * `gateway_metadata` is a free-form associative array — whatever the
 * Magento payment gateway stashed on the payment's additional_information.
 * The Rust side deserialises into `serde_json::Value`, so any
 * JSON-serialisable PHP structure round-trips cleanly.
 *
 * `captured_at` is RFC3339 UTC.
 *
 * Every getter carries `@return` (Magento webapi reflection requirement).
 */
interface PaymentInterface
{
    /** @return int */
    public function getMagentoId(): int;

    /** @return int */
    public function getInvoiceId(): int;

    /** @return string */
    public function getInvoiceIncrementId(): string;

    /** @return string */
    public function getMethod(): string;

    /** @return string */
    public function getCurrency(): string;

    /** @return float */
    public function getAmount(): float;

    /** @return string */
    public function getCapturedAt(): string;

    /** @return string|null */
    public function getGatewayTxnId(): ?string;

    /** @return array<string, mixed> */
    public function getGatewayMetadata(): array;
}

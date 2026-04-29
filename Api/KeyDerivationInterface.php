<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api;

use Byte8\Client\Exception\NotConnectedException;

/**
 * HKDF-SHA256 subkey derivation off the stored `byte8_api_key`.
 *
 * The stored master is never used to sign or verify directly. Each
 * direction has its own purpose-bound subkey so a leak of one direction's
 * subkey can't be reused against the other direction. Info strings are
 * part of the cryptographic binding — DO NOT change them without a
 * coordinated rotation on the ledger side.
 *
 * Subkey separation also gives us clean rotation: rotate the master
 * once on the ledger side, both directions move atomically. No
 * partial-state race windows.
 *
 * See `packages/modules/module-client/SECURITY.md` §"Key derivation".
 */
interface KeyDerivationInterface
{
    /**
     * Purpose tag for the Magento → ledger push channel (JWT signing).
     * Change only in coordinated rotation with `byte8-magento-outbound-v2`.
     */
    public const PURPOSE_OUTBOUND = 'byte8-magento-outbound-v1';

    /**
     * Purpose tag for the ledger → Magento pull channel (JWT verification).
     */
    public const PURPOSE_INBOUND = 'byte8-magento-inbound-v1';

    /**
     * Derive a 32-byte purpose-bound subkey from the stored master api_key.
     *
     * @param string $purpose  One of the PURPOSE_* constants.
     * @return string          32-byte raw binary subkey (not hex, not base64).
     *
     * @throws NotConnectedException If no master key is configured yet.
     */
    public function deriveKey(string $purpose): string;
}

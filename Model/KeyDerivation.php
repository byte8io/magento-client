<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model;

use Byte8\Client\Api\ClientConfigInterface;
use Byte8\Client\Api\KeyDerivationInterface;
use Byte8\Client\Exception\NotConnectedException;

class KeyDerivation implements KeyDerivationInterface
{
    private const SUBKEY_LENGTH_BYTES = 32;

    public function __construct(
        private readonly ClientConfigInterface $config
    ) {
    }

    public function deriveKey(string $purpose): string
    {
        if ($purpose !== self::PURPOSE_OUTBOUND && $purpose !== self::PURPOSE_INBOUND) {
            throw new \InvalidArgumentException(
                'Unknown HKDF purpose: ' . $purpose
                . ' (must be PURPOSE_OUTBOUND or PURPOSE_INBOUND)'
            );
        }

        $master = $this->config->getApiKey();
        if ($master === null || $master === '') {
            throw new NotConnectedException(
                __('Byte8 client master api_key is not configured — cannot derive subkey.')
            );
        }

        // hash_hkdf is available since PHP 7.1.2 and is the only officially
        // supported RFC 5869 HKDF function in core. No salt (empty string)
        // because the master key already has full entropy — HKDF's salt is
        // for low-entropy inputs. Info carries the purpose binding.
        $subkey = hash_hkdf('sha256', $master, self::SUBKEY_LENGTH_BYTES, $purpose, '');

        if ($subkey === false || strlen($subkey) !== self::SUBKEY_LENGTH_BYTES) {
            throw new \RuntimeException('HKDF subkey derivation failed.');
        }

        return $subkey;
    }
}

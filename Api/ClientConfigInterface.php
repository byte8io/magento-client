<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Api;

/**
 * Reads byte8/client/* config values. Decrypts api_key transparently so
 * callers never touch the EncryptorInterface directly.
 */
interface ClientConfigInterface
{
    public const XML_PATH_TENANT_ID = 'byte8/client/tenant_id';
    public const XML_PATH_API_KEY = 'byte8/client/api_key';
    public const XML_PATH_BASE_URL = 'byte8/client/base_url';

    public function getTenantId(): ?string;

    /**
     * Decrypted api_key (HMAC secret for the JWT channel).
     */
    public function getApiKey(): ?string;

    public function getBaseUrl(): string;

    /**
     * True once both tenant_id and api_key have been persisted —
     * ByteClient publishes become no-ops with a logger warning before
     * this point to avoid dead events piling in the outbox before
     * Connect has completed.
     */
    public function isConnected(): bool;
}

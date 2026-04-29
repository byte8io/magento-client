<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model;

use Byte8\Client\Api\ClientConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class ClientConfig implements ClientConfigInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function getTenantId(): ?string
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_TENANT_ID);
        return $value ? (string) $value : null;
    }

    public function getApiKey(): ?string
    {
        $cipher = (string) $this->scopeConfig->getValue(self::XML_PATH_API_KEY);
        if ($cipher === '') {
            return null;
        }
        $plain = $this->encryptor->decrypt($cipher);
        return $plain !== '' ? $plain : null;
    }

    public function getBaseUrl(): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_BASE_URL);
        return rtrim($value, '/');
    }

    public function isConnected(): bool
    {
        return $this->getTenantId() !== null && $this->getApiKey() !== null;
    }
}

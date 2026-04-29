<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\Jwt;

use Byte8\Client\Api\ClientConfigInterface;
use Byte8\Client\Api\KeyDerivationInterface;
use Byte8\Client\Exception\NotConnectedException;
use Magento\Framework\DataObject\IdentityGeneratorInterface;

/**
 * HS256 JWT minter for the outbound Magento → ledger channel.
 *
 * Inlined instead of pulling in firebase/php-jwt — the token shape is
 * trivial (fixed iss/aud/nbf/exp, v4 uuid jti) and the crypto is one
 * hash_hmac call. A composer dep adds upgrade friction that isn't worth
 * saving ~30 lines.
 *
 * **Signing key** is the HKDF-derived outbound subkey, NOT the master
 * api_key. See `SECURITY.md §"Key derivation"`. If an outbound-signed
 * token leaks (e.g. logged with the Authorization header), the attacker
 * cannot craft ledger→Magento tokens from it — they'd need the master.
 *
 * Claims emitted (both directions share this shape — `Verifier` is the
 * mirror):
 *   iss   = "magento"
 *   sub   = <tenant_id from byte8/client/tenant_id>
 *   aud   = "byte8-ledger"
 *   iat   = now (unix seconds)
 *   nbf   = iat
 *   exp   = iat + 60   (outbound path is a single-RTT call, short TTL)
 *   jti   = v4 UUID (ledger-side replay-protection cache key)
 */
class Signer
{
    private const ISSUER = 'magento';
    private const AUDIENCE = 'byte8-ledger';
    private const LIFETIME_SECONDS = 60;

    public function __construct(
        private readonly ClientConfigInterface $config,
        private readonly KeyDerivationInterface $keyDerivation,
        private readonly IdentityGeneratorInterface $identityGenerator
    ) {
    }

    /**
     * @throws NotConnectedException When the module hasn't completed Connect yet.
     */
    public function mint(): string
    {
        if (!$this->config->isConnected()) {
            throw new NotConnectedException(
                __('Byte8 client is not connected — tenant_id or api_key missing.')
            );
        }

        $now = time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => self::ISSUER,
            'sub' => $this->config->getTenantId(),
            'aud' => self::AUDIENCE,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + self::LIFETIME_SECONDS,
            'jti' => $this->identityGenerator->generateId(),
        ];

        $headerEncoded = $this->base64UrlEncode((string) json_encode($header, JSON_UNESCAPED_SLASHES));
        $claimsEncoded = $this->base64UrlEncode((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
        $signingInput = $headerEncoded . '.' . $claimsEncoded;

        $outboundKey = $this->keyDerivation->deriveKey(KeyDerivationInterface::PURPOSE_OUTBOUND);
        $signature = hash_hmac('sha256', $signingInput, $outboundKey, true);
        $signatureEncoded = $this->base64UrlEncode($signature);

        return $signingInput . '.' . $signatureEncoded;
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}

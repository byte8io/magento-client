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
use Magento\Framework\App\CacheInterface;

/**
 * HS256 JWT verifier for the inbound ledger → Magento channel.
 *
 * Mirror of `Signer` with the directions flipped. Verifies:
 *   1. Header  — must be {"alg":"HS256","typ":"JWT"}
 *   2. Signature — HMAC-SHA256 over `header.payload` with the inbound
 *                  HKDF subkey (NOT the master api_key)
 *   3. Claims:
 *      - iss == "byte8-ledger"
 *      - aud == "magento"
 *      - sub == stored tenant_id (byte8/client/tenant_id)
 *      - iat <= now + 30s  (clock-skew tolerance)
 *      - nbf <= now
 *      - exp >  now
 *      - exp - iat <= 300 seconds  (hard TTL ceiling — see §"TTLs" in SECURITY.md)
 *      - jti is a non-empty string
 *   4. Replay — jti must not appear in Magento's cache pool under the
 *      `byte8_jti:<jti>` key. On successful verify the jti is cached
 *      with TTL = exp - now so the same token cannot be re-used even
 *      if leaked. See SECURITY.md §"Replay protection".
 *
 * Returns the decoded claims array on success; throws on any failure
 * with a specific reason (never leak the decoded token content in the
 * log — the raw token body may contain the tenant id we're trying to
 * protect).
 */
class Verifier
{
    public const EXPECTED_ISSUER = 'byte8-ledger';
    public const EXPECTED_AUDIENCE = 'magento';
    private const MAX_TTL_SECONDS = 300;
    private const CLOCK_SKEW_TOLERANCE_SECONDS = 30;
    private const JTI_CACHE_KEY_PREFIX = 'byte8_jti:';

    public function __construct(
        private readonly ClientConfigInterface $config,
        private readonly KeyDerivationInterface $keyDerivation,
        private readonly CacheInterface $cache
    ) {
    }

    /**
     * @return array<string,mixed> Decoded claims on success.
     * @throws JwtVerificationException  Every failure reason is a
     *                                   subclass message-only; never
     *                                   embed the raw token in logs.
     * @throws NotConnectedException     If the module hasn't completed
     *                                   paired setup yet.
     */
    public function verify(string $jwt): array
    {
        if (!$this->config->isConnected()) {
            throw new NotConnectedException(
                __('Byte8 client is not connected — cannot verify inbound JWTs.')
            );
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new JwtVerificationException('malformed token: expected three dot-separated segments');
        }
        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        $header = $this->decodeJsonSegment($headerEncoded, 'header');
        if (($header['alg'] ?? null) !== 'HS256') {
            throw new JwtVerificationException('unsupported alg (only HS256 is accepted)');
        }
        if (($header['typ'] ?? null) !== 'JWT') {
            throw new JwtVerificationException('unsupported typ (only JWT is accepted)');
        }

        $inboundKey = $this->keyDerivation->deriveKey(KeyDerivationInterface::PURPOSE_INBOUND);
        $expectedSig = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $inboundKey, true);
        $providedSig = $this->base64UrlDecode($signatureEncoded);
        if (!hash_equals($expectedSig, $providedSig)) {
            throw new JwtVerificationException('signature mismatch');
        }

        $claims = $this->decodeJsonSegment($payloadEncoded, 'payload');
        $this->assertClaims($claims);
        $this->assertReplay((string) $claims['jti'], (int) $claims['exp']);

        return $claims;
    }

    /**
     * @param array<string,mixed> $claims
     */
    private function assertClaims(array $claims): void
    {
        if (($claims['iss'] ?? null) !== self::EXPECTED_ISSUER) {
            throw new JwtVerificationException('iss mismatch');
        }
        if (($claims['aud'] ?? null) !== self::EXPECTED_AUDIENCE) {
            throw new JwtVerificationException('aud mismatch');
        }
        if (($claims['sub'] ?? null) !== $this->config->getTenantId()) {
            throw new JwtVerificationException('sub does not match stored tenant_id');
        }
        foreach (['iat', 'nbf', 'exp'] as $numClaim) {
            if (!isset($claims[$numClaim]) || !is_int($claims[$numClaim])) {
                throw new JwtVerificationException("$numClaim missing or not an integer");
            }
        }
        if (!isset($claims['jti']) || !is_string($claims['jti']) || $claims['jti'] === '') {
            throw new JwtVerificationException('jti missing or empty');
        }

        $now = time();
        if ((int) $claims['iat'] > $now + self::CLOCK_SKEW_TOLERANCE_SECONDS) {
            throw new JwtVerificationException('iat is too far in the future (clock skew exceeded)');
        }
        if ((int) $claims['nbf'] > $now) {
            throw new JwtVerificationException('token not yet valid (nbf > now)');
        }
        if ((int) $claims['exp'] <= $now) {
            throw new JwtVerificationException('token expired');
        }
        if (((int) $claims['exp'] - (int) $claims['iat']) > self::MAX_TTL_SECONDS) {
            throw new JwtVerificationException(
                'token TTL exceeds policy ceiling of ' . self::MAX_TTL_SECONDS . 's'
            );
        }
    }

    private function assertReplay(string $jti, int $exp): void
    {
        $cacheKey = self::JTI_CACHE_KEY_PREFIX . sha1($jti);
        if ($this->cache->load($cacheKey) !== false) {
            throw new JwtVerificationException('jti already seen — replay rejected');
        }
        $remaining = max(1, $exp - time());
        $this->cache->save('1', $cacheKey, [], $remaining);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonSegment(string $segment, string $label): array
    {
        $raw = $this->base64UrlDecode($segment);
        if ($raw === '') {
            throw new JwtVerificationException($label . ' segment is empty');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new JwtVerificationException($label . ' segment is not a JSON object');
        }
        return $decoded;
    }

    private function base64UrlDecode(string $segment): string
    {
        $remainder = strlen($segment) % 4;
        if ($remainder !== 0) {
            $segment .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}

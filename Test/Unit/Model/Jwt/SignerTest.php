<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Test\Unit\Model\Jwt;

use Byte8\Client\Api\ClientConfigInterface;
use Byte8\Client\Api\KeyDerivationInterface;
use Byte8\Client\Exception\NotConnectedException;
use Byte8\Client\Model\Jwt\Signer;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SignerTest extends TestCase
{
    /** @var ClientConfigInterface&MockObject */
    private ClientConfigInterface $config;

    /** @var KeyDerivationInterface&MockObject */
    private KeyDerivationInterface $keyDerivation;

    /** @var IdentityGeneratorInterface&MockObject */
    private IdentityGeneratorInterface $identityGenerator;

    private Signer $signer;

    /** The outbound subkey we pretend HKDF has derived for every test. */
    private const FAKE_OUTBOUND_SUBKEY = 'fake-derived-32byte-subkey-aaaaa';

    protected function setUp(): void
    {
        $this->config = $this->createMock(ClientConfigInterface::class);
        $this->keyDerivation = $this->createMock(KeyDerivationInterface::class);
        $this->identityGenerator = $this->createMock(IdentityGeneratorInterface::class);

        // Signer ALWAYS requests the outbound subkey — never the master.
        $this->keyDerivation->method('deriveKey')
            ->with(KeyDerivationInterface::PURPOSE_OUTBOUND)
            ->willReturn(self::FAKE_OUTBOUND_SUBKEY);

        $this->signer = new Signer($this->config, $this->keyDerivation, $this->identityGenerator);
    }

    public function testMintProducesThreePartJwtSignedWithOutboundSubkey(): void
    {
        $this->config->method('isConnected')->willReturn(true);
        $this->config->method('getTenantId')->willReturn('tenant-42');
        $this->identityGenerator->method('generateId')->willReturn('abc-jti');

        $token = $this->signer->mint();
        $parts = explode('.', $token);
        self::assertCount(3, $parts, 'JWT must have header.payload.signature segments');

        [$header, $payload, $signature] = $parts;

        $decodedHeader = json_decode($this->base64UrlDecode($header), true);
        self::assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $decodedHeader);

        $decodedClaims = json_decode($this->base64UrlDecode($payload), true);
        self::assertSame('magento', $decodedClaims['iss']);
        self::assertSame('byte8-ledger', $decodedClaims['aud']);
        self::assertSame('tenant-42', $decodedClaims['sub']);
        self::assertSame('abc-jti', $decodedClaims['jti']);
        self::assertSame($decodedClaims['iat'], $decodedClaims['nbf'], 'nbf == iat');
        self::assertSame(60, $decodedClaims['exp'] - $decodedClaims['iat'], 'Token must live exactly 60 seconds');

        // Critical security-model assertion: signature verifies against the
        // derived OUTBOUND subkey, NOT the raw api_key. Proves the Signer
        // calls KeyDerivation and uses its output, not the master.
        $expectedSig = $this->base64UrlEncode(
            hash_hmac('sha256', $header . '.' . $payload, self::FAKE_OUTBOUND_SUBKEY, true)
        );
        self::assertSame($expectedSig, $signature, 'Signature must verify against outbound subkey');
    }

    public function testMintThrowsWhenNotConnected(): void
    {
        $this->config->method('isConnected')->willReturn(false);

        $this->expectException(NotConnectedException::class);
        $this->signer->mint();
    }

    public function testMintGeneratesDistinctJtiPerCall(): void
    {
        $this->config->method('isConnected')->willReturn(true);
        $this->config->method('getTenantId')->willReturn('tenant-42');
        $this->identityGenerator->method('generateId')->willReturnOnConsecutiveCalls('jti-1', 'jti-2');

        $a = $this->signer->mint();
        $b = $this->signer->mint();
        self::assertNotSame($a, $b, 'Unique jti values must yield unique tokens');

        $headerA = explode('.', $a)[0];
        $headerB = explode('.', $b)[0];
        self::assertSame($headerA, $headerB, 'Header segment is stable across mints');
    }

    private function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $segment): string
    {
        $remainder = strlen($segment) % 4;
        if ($remainder !== 0) {
            $segment .= str_repeat('=', 4 - $remainder);
        }
        return (string) base64_decode(strtr($segment, '-_', '+/'), true);
    }
}

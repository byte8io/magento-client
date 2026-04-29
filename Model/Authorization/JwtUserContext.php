<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\Authorization;

use Byte8\Client\Api\ClientConfigInterface;
use Byte8\Client\Exception\NotConnectedException;
use Byte8\Client\Model\Jwt\JwtVerificationException;
use Byte8\Client\Model\Jwt\Verifier;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the current user for inbound ledger→Magento webapi calls
 * signed with an HS256 JWT whose issuer is `byte8-ledger`. Runs BEFORE
 * Magento's `TokenUserContext` in the CompositeUserContext chain so
 * we get first crack at the Authorization header.
 *
 * Resolution rules:
 *   - If the Authorization header doesn't start with "Bearer <three-segment jwt>"
 *     whose unverified iss == "byte8-ledger", return (null, null) → chain
 *     falls through to Magento's standard handlers. This way non-Byte8
 *     integrations, admin tokens, and customer tokens are unaffected.
 *   - If the unverified iss matches but verification fails (bad signature,
 *     expired, replay, etc.), return (null, null) and log the reason in
 *     the byte8 channel. The request goes on to the next context, which
 *     typically produces a 401. We DO NOT return a verification error
 *     message to the HTTP client — that'd be a probing oracle.
 *   - On successful verification, return (SYNTHETIC_USER_ID,
 *     USER_TYPE_INTEGRATION). The SyntheticAclPlugin then grants this
 *     synthetic id the `Byte8_Client::byte8_webapi` resource without
 *     touching the authorization_role/rule tables.
 *
 * HTTPS enforcement: in production deploy mode, non-HTTPS requests
 * bearing a Byte8 JWT are rejected outright — we log and refuse to
 * resolve. Developer mode accepts HTTP for localhost integration
 * testing. See `SECURITY.md §"HTTPS enforcement"`.
 */
class JwtUserContext implements UserContextInterface, ResetAfterRequestInterface
{
    /**
     * Sentinel user id returned when a Byte8 JWT successfully verifies.
     * Magento integration ids auto-increment from 1, so 0 is unreachable
     * as a real integration id and safe as a sentinel. The
     * SyntheticAclPlugin discriminates on this value.
     */
    public const SYNTHETIC_USER_ID = 0;

    private ?int $userId = null;
    private ?int $userType = null;
    private bool $processed = false;

    public function __construct(
        private readonly RequestInterface $request,
        private readonly ClientConfigInterface $config,
        private readonly Verifier $verifier,
        private readonly AppState $appState,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getUserId()
    {
        $this->processRequest();
        return $this->userId;
    }

    public function getUserType()
    {
        $this->processRequest();
        return $this->userType;
    }

    public function _resetState(): void
    {
        $this->userId = null;
        $this->userType = null;
        $this->processed = false;
    }

    private function processRequest(): void
    {
        if ($this->processed) {
            return;
        }
        $this->processed = true;

        $header = (string) $this->request->getHeader('Authorization');
        if ($header === '' || stripos($header, 'bearer ') !== 0) {
            return;
        }

        $jwt = trim(substr($header, 7));
        if (!$this->looksLikeByte8Jwt($jwt)) {
            // Not ours — fall through to next context (admin/customer/
            // integration tokens all route via TokenUserContext).
            return;
        }

        if (!$this->allowInsecureRequest()) {
            $this->logger->error(
                'Byte8: inbound JWT rejected — request is not HTTPS and deploy mode is production'
            );
            return;
        }

        try {
            if (!$this->config->isConnected()) {
                // Module has an iss=byte8-ledger JWT but no stored master
                // key — impossible in a paired install, but swallow
                // cleanly if it happens (pre-pair probing, test harness).
                return;
            }
            $this->verifier->verify($jwt);
            $this->userId = self::SYNTHETIC_USER_ID;
            $this->userType = UserContextInterface::USER_TYPE_INTEGRATION;
        } catch (JwtVerificationException $e) {
            $this->logger->warning(
                'Byte8: inbound JWT verification failed: ' . $e->getMessage()
            );
        } catch (NotConnectedException $e) {
            $this->logger->warning(
                'Byte8: inbound JWT cannot be verified (not connected): ' . $e->getMessage()
            );
        }
    }

    /**
     * Peek at the unverified header+payload to check if the token
     * identifies itself as ours. Never trust these fields — they're
     * guarded by the signature check in `Verifier::verify()`. This is
     * only a routing hint so we don't blow log noise every time a
     * legitimate Magento admin token passes through.
     */
    private function looksLikeByte8Jwt(string $jwt): bool
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return false;
        }
        $payloadRaw = $this->base64UrlDecode($parts[1]);
        if ($payloadRaw === '') {
            return false;
        }
        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            return false;
        }
        return ($payload['iss'] ?? null) === Verifier::EXPECTED_ISSUER
            && ($payload['aud'] ?? null) === Verifier::EXPECTED_AUDIENCE;
    }

    private function allowInsecureRequest(): bool
    {
        if ($this->request instanceof \Magento\Framework\HTTP\PhpEnvironment\Request
            && $this->request->isSecure()
        ) {
            return true;
        }
        try {
            $mode = $this->appState->getMode();
        } catch (\Throwable) {
            // Area not set (rare in webapi) — be permissive only if
            // the request happens to be secure; otherwise refuse.
            return false;
        }
        return $mode !== AppState::MODE_PRODUCTION;
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

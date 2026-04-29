<?php
/**
 * Copyright © Byte8 Ltd. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Byte8\Client\Model\Jwt;

/**
 * Thrown by Verifier when a token fails any validation step.
 *
 * Intentionally a plain RuntimeException (not LocalizedException) —
 * callers in the auth-plugin layer should catch, log the reason in the
 * Byte8 channel, and return "no user" to the webapi framework. Never
 * propagate the reason to the HTTP client, which would give an attacker
 * a probing oracle.
 */
class JwtVerificationException extends \RuntimeException
{
}

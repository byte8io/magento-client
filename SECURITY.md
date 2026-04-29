# Byte8 Client — Security Design

Audience: security-focused merchants, auditors, and engineers reviewing this
module before installing it on a production Magento instance.

This document describes the authentication and authorization model used by
`byte8/module-client` + `byte8/module-sage-accounting` to talk to the Byte8
Ledger SaaS backend and vice versa. It explains why we rejected Adobe's
stock options and what invariants we rely on instead.

---

## 1. Threat model

What we are protecting:

1. The **master `byte8_api_key`** delivered to Magento by `apps/ledger` during
   paired setup. It is encrypted at rest via Magento's `EncryptorInterface`
   (`crypt_key` in `env.php`) and is the root of trust for both sync
   directions.
2. Canonical Magento data (orders, invoices, customer PII) exposed via
   `GET /V1/byte8/invoice/:id` and siblings.
3. The integrity of outbound event webhooks (Magento → ledger).

Who we defend against:

- Passive eavesdroppers on the wire (defended by HTTPS only — see §6).
- Token theft from logs, CI secrets, laptops, and backups (defended by key
  separation + short TTLs + single-use pairing codes — see §2, §3, §4).
- Replay of a stolen JWT (defended by `jti` cache — see §5).
- Authenticated-but-unauthorized callers trying to reach non-Byte8 webapi
  routes with our synthetic context (defended by narrow resource grants —
  see §8).
- Rogue admins who can read `core_config_data` — note this is a bypass
  scenario we do NOT fully defend against: such an admin already has root
  access to the Magento install and can fabricate arbitrary API calls. The
  model assumes admin access is itself protected by Magento-standard
  controls.

Out of scope:

- Side-channel attacks on the Magento host (timing, RAM dumping).
- Compromise of the `crypt_key` in `env.php` — Magento's own key, not ours
  to harden.
- Compromise of the Byte8 Ledger backend itself.

---

## 2. Why we rejected Adobe's two token options

We considered three options for authenticating inbound `apps/ledger → Magento`
REST calls:

### 2.1 Magento Integration Token as a standalone Bearer

Gated by the config flag `oauth/consumer/enable_integration_as_bearer`. Off
by default since Magento 2.4.4, explicitly
[discouraged by Adobe](https://developer.adobe.com/commerce/webapi/get-started/authentication/gs-authentication-token/)
because the token is **never-expiring**: once leaked (log, CI secret,
backup, laptop theft) an attacker has persistent admin-API access until a
human manually revokes the integration.

**Verdict: rejected.** Enabling the flag is a hard "no" in our threat model.

### 2.2 OAuth 1.0a signed requests

The Adobe-blessed path. Deprecated at IETF level (OAuth 1.0a is superseded
by OAuth 2.0), uses HMAC-SHA1 by default in most client libraries, requires
per-request nonce tracking + NTP-synced clocks, and lacks token refresh.
See
[magento/magento2#34780](https://github.com/magento/magento2/issues/34780)
for ongoing community pressure to add OAuth 2.0 — open since 2021, zero
maintainer response, no milestone.

**Verdict: rejected.** The delivery requires merchants to hand-configure an
Integration with Callback URLs, the OAuth 1.0a request-signing code is
fragile, and the protocol itself is stuck at HMAC-SHA1 for most clients.

### 2.3 Symmetric HS256 JWT with HKDF-derived subkeys

What we ship. One master key per merchant, two cryptographically distinct
subkeys (one per direction), 60s outbound / 300s inbound TTLs with `jti`
replay protection.

**Verdict: shipped.** See §3–§8 for details.

---

## 3. Key derivation — HKDF subkey separation

Ledger delivers one `byte8_api_key` during paired setup. Both sides
immediately derive two **purpose-bound subkeys** using HKDF-SHA256
(RFC 5869), via PHP's built-in `hash_hkdf()` and Rust's `hkdf` crate.

```
outbound_key = HKDF(byte8_api_key, info="byte8-magento-outbound-v1", length=32)
inbound_key  = HKDF(byte8_api_key, info="byte8-magento-inbound-v1",  length=32)
```

**Invariants:**

- The master is never used to sign or verify anything directly.
- A leaked outbound key cannot forge inbound tokens, and vice versa — they
  are cryptographically independent outputs of HKDF with different `info`
  strings.
- The info strings carry the version suffix `-v1`. Any change to the
  derivation contract must bump to `-v2` and be coordinated across both
  sides of the wire.

**Rotation:** rotate the master once on the ledger side; call
`POST /V1/byte8/setup/pair` again with a fresh `byte8_api_key`; Magento
overwrites. Both subkeys move atomically. There is no half-rotated state.

Code entry points:
- `packages/modules/module-client/Api/KeyDerivationInterface.php`
- `packages/modules/module-client/Model/KeyDerivation.php`

---

## 4. Pairing flow — how the master key is delivered

Bootstrap is the trickiest moment. Ledger must deliver the master to
Magento over HTTPS, authenticated, but there is no key yet. We use a
**short-lived, single-use pairing code** generated in Magento admin and
read-of-once by the merchant.

1. Merchant clicks **Generate Pairing Code** in Stores → Configuration →
   Byte8 → Sage Accounting.
2. Magento's `Byte8\SageAccounting\Controller\Adminhtml\PairingCode\Generate`
   mints 128 bits of CSPRNG output (`random_bytes(16)` → 32 hex chars),
   stores `SHA-256(code)` and `time()` in `core_config_data`, and reveals
   the plaintext **once** via a session flash on the next page render.
3. Merchant copy-pastes the code + Magento base URL into the Connect form
   at `ledger.byte8.io/dashboard`.
4. Ledger POSTs to `{magento_url}/rest/V1/byte8/setup/pair` with
   `{pairing_code, tenant_id, byte8_api_key, ledger_base_url}` — the
   webapi route is marked `anonymous` in `etc/webapi.xml` because there
   is no key yet; auth is enforced in `Byte8\SageAccounting\Model\Setup\Pair`
   via `hash_equals(stored_hash, hash("sha256", incoming_code))`.
5. On first valid call, Magento persists the master key (encrypted),
   `tenant_id`, and `ledger_base_url`, then deletes the pairing-code hash
   and timestamp. The code is **single-use**.

**Invariants:**

- Pairing code plaintext is never stored. Only the SHA-256 hash lives in
  `core_config_data`.
- TTL: **30 minutes**. `Setup\Pair` rejects any code older than this.
- Mismatch, missing code, and expired code all return the identical
  generic `401 Invalid or expired pairing code.` to prevent oracle
  behaviour. The Magento log distinguishes reasons for the merchant.
- Constant-time comparison via `hash_equals` blocks timing attacks.

Code entry points:
- `packages/modules/module-sage-accounting/Controller/Adminhtml/PairingCode/Generate.php`
- `packages/modules/module-sage-accounting/Model/Setup/Pair.php`
- `packages/modules/module-sage-accounting/etc/webapi.xml`

---

## 5. Claim contract and replay protection

### 5.1 Required JWT claims (both directions)

| Claim | Value | Notes |
| --- | --- | --- |
| `iss` | `"byte8-ledger"` (inbound) / `"magento"` (outbound) | String equality |
| `aud` | `"magento"` (inbound) / `"byte8-ledger"` (outbound) | String equality |
| `sub` | `tenant_id` (UUID) | Must equal stored `byte8/client/tenant_id` |
| `iat` | unix seconds | Rejected if > `now + 30s` (clock-skew tolerance) |
| `nbf` | unix seconds | Rejected if > `now` |
| `exp` | unix seconds | Rejected if <= `now` |
| `jti` | v4 UUID | Must be a non-empty string |

### 5.2 TTL policy

| Direction | Max `exp - iat` | Rationale |
| --- | --- | --- |
| Magento → ledger (outbound push) | 60s | Single-RTT webhook; 60s tolerates retries within the same window |
| Ledger → Magento (inbound pull) | 300s | Matches the Sage Accounting norm; absorbs modest clock drift between ledger's worker and the Magento host |

The Verifier enforces a **hard ceiling** — any token with
`exp - iat > 300` is rejected, regardless of signature validity. Ledger
cannot issue long-lived tokens even by mistake.

### 5.3 Replay protection

Every inbound `jti` is cached in Magento's `CacheInterface` under
`byte8_jti:<sha1(jti)>` with TTL = `exp - now`. On subsequent tokens with
the same `jti` the Verifier rejects via `JwtVerificationException('jti
already seen — replay rejected')`. Cache footprint at 300s TTL is
bounded by ledger's actual throughput; worst-case ~3000 entries at 10
RPS sustained, well under Redis's single-key overhead.

Code entry point: `packages/modules/module-client/Model/Jwt/Verifier.php`

---

## 6. HTTPS enforcement

The `JwtUserContext` refuses to resolve a Byte8 JWT when:
- `Magento\Framework\App\State::getMode() === MODE_PRODUCTION`, **and**
- `Request::isSecure() === false`

This is logged to `var/log/byte8/client.log` and the request falls through
to the default 401 path. Developer mode allows HTTP for localhost
integration testing — never enable developer mode on a production host.

Code entry point: `packages/modules/module-client/Model/Authorization/JwtUserContext.php`

---

## 7. The synthetic user context

When a Byte8 JWT verifies, `JwtUserContext` populates
`UserContextInterface::getUserId()` with the reserved sentinel **0** and
`getUserType()` with `USER_TYPE_INTEGRATION`. Magento integration ids
auto-increment from 1, so 0 is unreachable by any real integration — there
is zero collision risk with legitimate Magento users.

`SyntheticAclPlugin` is an around-plugin on `Magento\Framework\Authorization::isAllowed`.
For `$resource === 'Byte8_Client::byte8_webapi'` AND `userId === 0` AND
`userType === USER_TYPE_INTEGRATION`, it returns `true`. For every other
resource it calls `proceed()` unchanged — the plugin cannot widen access
beyond that single resource.

**Why plugin, not a real authorization_role row:** creating a real role
for the sentinel user would require a data patch, would leak into admin
UI (`System → Permissions → User Roles`), and would live in DB forever.
The plugin keeps the grant in one auditable code file.

Code entry points:
- `packages/modules/module-client/Model/Authorization/JwtUserContext.php`
- `packages/modules/module-client/Plugin/Authorization/SyntheticAclPlugin.php`

---

## 8. Route surface

The ACL resource `Byte8_Client::byte8_webapi` is granted by
`SyntheticAclPlugin` only. It guards exactly these routes:

| Method | Route | Service |
| --- | --- | --- |
| GET | `/V1/byte8/invoice/:id` | `Api\Canonical\InvoiceRepositoryInterface::get` |
| GET | `/V1/byte8/customer/:id` | `Api\Canonical\ContactRepositoryInterface::get` |
| GET | `/V1/byte8/creditmemo/:id` | `Api\Canonical\CreditMemoRepositoryInterface::get` |
| GET | `/V1/byte8/payment/:id` | `Api\Canonical\PaymentRepositoryInterface::get` |

All four are **read-only**. There is no route that mutates Magento data
from the synthetic context. Inbound product creation / stock update (PR4)
will be added with explicit review of the threat model before they land.

The pairing endpoint `POST /V1/byte8/setup/pair` is `anonymous` and
authenticates in-handler via the pairing code — see §4.

Code entry point: `packages/modules/module-client/etc/webapi.xml`

---

## 9. What auditors will see in the logs

Every authentication failure path writes to `var/log/byte8/client.log`:

| Log line | Meaning |
| --- | --- |
| `inbound JWT verification failed: signature mismatch` | Attacker forged a token without the inbound subkey — or a legitimate token was tampered with. |
| `inbound JWT verification failed: jti already seen — replay rejected` | Token was valid once; being re-used now. Investigate source. |
| `inbound JWT verification failed: token expired` | Clock skew too large, or a stale token is being replayed past its exp. |
| `inbound JWT verification failed: token TTL exceeds policy ceiling of 300s` | Ledger shipped a token with `exp-iat > 300` — ledger-side bug, not an attack. |
| `inbound JWT rejected — request is not HTTPS and deploy mode is production` | Someone is talking plain HTTP to a production Magento. |
| `/setup/pair rejected — pairing code expired` | Merchant took too long between Generate and ledger's call. |
| `/setup/pair rejected — pairing code mismatch` | Wrong code pasted. |
| `/setup/pair rejected — no pairing code is pending generation` | Ledger called /setup/pair but Magento has no pending code — possible scanner or stale ledger state. |

None of these log lines include the raw JWT, the master key, or the
pairing code. Logs are safe to ship to a remote aggregator.

---

## 10. Review checklist

Files to audit in order:

1. `module-client/Api/KeyDerivationInterface.php` — derivation contract
2. `module-client/Model/KeyDerivation.php` — HKDF implementation
3. `module-client/Model/Jwt/Signer.php` — outbound signing
4. `module-client/Model/Jwt/Verifier.php` — inbound verification + replay cache
5. `module-client/Model/Authorization/JwtUserContext.php` — request resolution + HTTPS gate
6. `module-client/Plugin/Authorization/SyntheticAclPlugin.php` — ACL short-circuit
7. `module-client/etc/webapi_rest/di.xml` — chain ordering (sortOrder 5, before TokenUserContext at 10)
8. `module-client/etc/webapi.xml` — route → ACL resource mapping
9. `module-sage-accounting/Controller/Adminhtml/PairingCode/Generate.php` — code minting
10. `module-sage-accounting/Model/Setup/Pair.php` — pairing validation
11. `module-sage-accounting/etc/webapi.xml` — anonymous `/setup/pair` route

Questions welcome — file an issue with tag `security-review` on the
module-client repo.

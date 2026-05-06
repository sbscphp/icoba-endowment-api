# icoba-endowment-api

## Authentication & Security

This API uses **Laravel Sanctum token auth** with OTP-backed login and password reset flows, plus optional per-client transport encryption for API payloads.

### Authentication Overview

- **Primary auth model:** Sanctum bearer tokens for authenticated API access.
- **Customer auth routes:** `POST /api/v1/auth/login`, `POST /api/v1/auth/login/verify-otp`, `POST /api/v1/auth/logout`.
- **Password reset flow:** challenge-based OTP verification followed by a short-lived reset token.
- **Token invalidation:** on successful password reset, existing personal access tokens are revoked.

> ⚠️ This project currently uses **Sanctum**, not OAuth2/Passport access token grants.

---

### OTP + Challenge Token Flow (Login and Password Reset)

The system uses an encrypted, time-bound **challenge token** to represent OTP verification state.

1. Client requests OTP (`login` or `forgot-password`).
2. API issues a `challenge_token` and `expires_in`.
3. Client submits `challenge_token + otp` for verification.
4. For password reset, a separate encrypted `reset_token` is issued after OTP verification.
5. Client calls reset endpoint with `reset_token + new password`.

Implementation references:
- `ChallengeTokenService` for issuing/decoding challenge tokens.
- `OtpService` for OTP lifecycle, cooldown, and verification attempts.
- `PasswordResetService` for reset token decoding, expiry checks, and token revocation.

---

### API Transport Encryption

`RequestResponseEncryptionMiddleware` supports per-client request/response encryption based on the API consumer profile (`api_users.encryption_mode`).

- Client identity is resolved using `X-ClientKey`.
- Request decryption and response encryption are applied per route unless bypassed.
- Dev and provider callback routes are intentionally bypassed.
- Optional non-production response preview is supported.

#### Envelope Formats

- **Encrypted request body (POST/PUT/PATCH):**
  ```json
  { "payload": "<base64-ciphertext>" }
  ```
- **Encrypted response body:**
  ```json
  { "response": "<base64-ciphertext>" }
  ```
- **Optional response preview (non-production only):**
  ```json
  {
    "response": "<base64-ciphertext>",
    "preview": { "error": false, "message": "...", "data": {} }
  }
  ```

---

### Encryption Modes

`ApiEncryptionMode` controls inbound/outbound behavior per API user.

| Mode | Incoming Request | Outgoing Response | Typical Use Case |
|---|---|---|---|
| `both` | Decrypt encrypted payload/query | Encrypt response envelope | Full transport confidentiality for partner integrations |
| `request_only` | Decrypt encrypted payload/query (or accept plain JSON for body fallback) | Plain JSON response | Confidential inbound data, simpler downstream client response handling |
| `response_only` | Plain JSON request | Encrypt response envelope | Transitional rollout where clients can encrypt-read before encrypt-write |

> Recommended default for sensitive integrations: `both`.

---

### Registration Secret (Developer Provisioning Routes)

Developer provisioning endpoints are protected by a secret header:

- Header: `X-Dev-Api-User-Secret`
- Config: `security.api_user_dev_registration.secret`
- Enable flag: `security.api_user_dev_registration.enabled`
- Rate limit: `throttle:api-user-dev-registration` (5 requests/min per IP)

Developer routes:
- `POST /api/v1/dev/api-users`
- `POST /api/v1/dev/crypto/encrypt`
- `POST /api/v1/dev/crypto/decrypt`

> ⚠️ Treat this secret as highly sensitive operational credential.  
> Do not expose it to frontend code, mobile builds, logs, screenshots, or repo history.

---

### Rate Limiting and Abuse Controls

Rate limits are centrally configured in `AppServiceProvider`.

| Limiter | Applied To | Policy |
|---|---|---|
| `customer-login` | customer login endpoint | 5 requests/min per `ip|email` |
| `admin-login` | admin login endpoint | 5 requests/min per `ip|email` |
| `customer-otp-send` | customer OTP send/resend | `OTP_SEND_MAX_PER_WINDOW` over `OTP_MINUTES` |
| `customer-otp-verify` | customer OTP verify/reset verify | `OTP_VERIFY_MAX_PER_WINDOW` over `OTP_MINUTES` |
| `admin-otp-send` | admin OTP send/resend | `OTP_SEND_MAX_PER_WINDOW` over `OTP_MINUTES` |
| `admin-otp-verify` | admin OTP verify | `OTP_VERIFY_MAX_PER_WINDOW` over `OTP_MINUTES` |
| `api-user-dev-registration` | dev API-user routes | 5 requests/min per IP |

OTP send cooldown is additionally enforced at challenge level (`otp_send_cooldown_seconds`) with safe reuse semantics.

---

### Configuration

#### Core Security Settings (`.env`)

```env
# OTP and throttling
OTP_MINUTES=5
OTP_SEND_MAX_PER_WINDOW=3
OTP_VERIFY_MAX_PER_WINDOW=20

# Transport encryption
API_ENCRYPTION_MIDDLEWARE_ENABLED=true
API_ENCRYPTION_DEFAULT_MODE=both
API_ENCRYPTION_RESPONSE_PREVIEW=false

# Dev provisioning controls
API_USER_DEV_REGISTRATION_ENABLED=false
API_USER_DEV_REGISTRATION_SECRET=change-me
```

#### Notes

- `API_ENCRYPTION_RESPONSE_PREVIEW` is ignored in production by design.
- Encryption mode is persisted per API user and can differ between clients.
- Keep default mode strict (`both`) unless migration constraints require otherwise.

---

### Client Integration Guide

#### Frontend/Client Key Usage (What to Share with Integrators)

- `X-ClientKey` is a client identifier header. It tells the API which API user profile to resolve.
- `encryption_key` and `iv` are private cryptographic materials used to encrypt/decrypt payload content.
- The server uses `X-ClientKey` to fetch the matching encryption settings before decrypting/encrypting.

Header contract for encrypted calls:

```http
Content-Type: application/json
X-ClientKey: <client_key>
Authorization: Bearer <token>   # where endpoint requires auth
```

Encrypted request/response flow:

1. Build normal JSON payload.
2. Serialize (`JSON.stringify` or equivalent).
3. Encrypt with `encryption_key` + `iv`.
4. Send `{ "payload": "<base64-ciphertext>" }` with `X-ClientKey` header.
5. Receive `{ "response": "<base64-ciphertext>" }`.
6. Decrypt `response` with the same `encryption_key` + `iv`.
7. Parse decrypted JSON.

Security architecture note:

- Browser apps cannot keep `encryption_key`/`iv` truly secret if shipped in client bundles.
- Recommended for web: use a BFF/proxy backend to hold cryptographic secrets and perform encryption/decryption server-side.
- Native mobile apps may store secrets in platform secure storage (Keychain/Keystore), but must still treat them as sensitive.

#### 1) Send Encrypted Request (mode: `both` / `request_only`)

1. Serialize payload to JSON string.
2. Encrypt JSON with shared key+IV.
3. Send as:
   ```json
   { "payload": "<base64-ciphertext>" }
   ```
4. Include `X-ClientKey` header.

#### 2) Read Encrypted Response (mode: `both` / `response_only`)

1. Parse JSON response.
2. Read `response` field.
3. Decrypt base64 ciphertext using same key+IV.
4. Parse decrypted JSON into object.

#### 3) Query Encryption for GET/DELETE

When applicable, send encrypted query JSON in `enc`:

```text
GET /api/endpoint?enc=<base64-ciphertext>
```

---

### Security Considerations and Best Practices

> ⚠️ **Never commit secrets**  
> Do not commit client keys, encryption keys, IVs, registration secrets, or production `.env` files.

> ⚠️ **Do not expose transport secrets client-side**  
> Keep `X-ClientKey`, encryption material, and dev registration secret in secure backend systems only.

- **Current cipher:** AES-256-CBC (`CryptoService`).
- **Recommendation:** Consider migration to **AES-256-GCM** for authenticated encryption (confidentiality + integrity in one primitive).
- **IV handling:** Use unique/random IV per key context; IV length must be 16 bytes for CBC.
- **Key storage:** Store encrypted at rest (KMS/HSM/secret manager), rotate periodically, and scope per integration.
- **Logging:** Do not log plaintext sensitive payloads or full secrets. Keep redacted prefixes only where necessary.
- **Environment safety:** Preview plaintext in encrypted responses is explicitly non-production behavior.

---

### Minimal Example: Decrypting a Response Envelope

```php
$ciphertext = $responseBody['response']; // base64
$plaintextJson = CryptoService::decryptAes($ciphertext, $base64Key, $base64Iv);
$payload = json_decode($plaintextJson, true, flags: JSON_THROW_ON_ERROR);
```

### Minimal Example: Encrypting a Request Envelope

```php
$json = json_encode(['amount' => 5000], JSON_THROW_ON_ERROR);
$cipher = CryptoService::encryptAes($json, $base64Key, $base64Iv);
$body = ['payload' => $cipher];
```

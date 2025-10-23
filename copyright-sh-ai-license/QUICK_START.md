# HMAC License Token - Quick Start Guide

## 5-Minute Setup

### 1. Get Your HMAC Secret
```bash
# Contact Ledger admin or access dashboard
https://ai-license-ledger.ddev.site/admin
```

### 2. Configure WordPress Plugin
```
WordPress Admin → Settings → AI License
↓
Find "HMAC License Secret" field
↓
Paste your secret key
↓
Click "Save Changes"
```

### 3. Enable Enforcement
```
Settings → AI License → Enforcement Mode
↓
Toggle "Enabled"
↓
Disable "Observation Mode" (optional - blocks immediately)
```

### 4. Test It Works
```bash
# Should get 402
curl -I -A "GPTBot/1.0" https://your-site.com/

# Should get 200
curl -I -A "Googlebot/2.1" https://your-site.com/

# Generate valid token
LICENSE_ID="12345"
SECRET="your-secret"
SIG=$(echo -n "$LICENSE_ID" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

# Should get 200
curl -I -A "GPTBot/1.0" "https://your-site.com/?ai-license=${LICENSE_ID}-${SIG}"
```

## How AI Bots Use It

### 1. Bot Attempts Access (No Token)
```bash
GET https://example.com/article
User-Agent: GPTBot/1.0
```

**Response:**
```
HTTP/1.1 402 Payment Required
Content-Type: application/json

{
  "error": "Payment Required",
  "price_per_1k_tokens": 0.10,
  "currency": "USD",
  "payto": "example.com",
  "acquire_license_url": "https://ai-license-ledger.ddev.site/api/v1/licenses/acquire",
  "documentation": "https://docs.copyright.sh/api/licenses"
}
```

### 2. Bot Acquires License
```bash
POST https://ai-license-ledger.ddev.site/api/v1/licenses/acquire
Content-Type: application/json

{
  "url": "https://example.com/article",
  "stage": "infer",
  "estimated_tokens": 1000
}
```

**Response:**
```json
{
  "license_token": "12345-abc123def456...",
  "price_paid": 0.10,
  "currency": "USD"
}
```

### 3. Bot Uses License Token
```bash
GET https://example.com/article?ai-license=12345-abc123def456...
User-Agent: GPTBot/1.0
```

**Response:**
```
HTTP/1.1 200 OK
Content-Type: text/html

<html>...</html>
```

## Token Format

```
ai-license={license_version_id}-{license_sig}
           └────────┬──────────┘ └─────┬─────┘
                    |                  |
             Integer ID          HMAC-SHA256
             from Ledger         (64 hex chars)
```

**Example:**
```
ai-license=12345-abc123def456789...
```

## Generate Test Token (PHP)

```php
<?php
$license_version_id = '12345';
$hmac_secret = 'your-secret-here';

$license_sig = hash_hmac('sha256', $license_version_id, $hmac_secret);
$token = $license_version_id . '-' . $license_sig;

echo "Token: ai-license=" . $token . "\n";
```

## Generate Test Token (Bash)

```bash
LICENSE_ID="12345"
HMAC_SECRET="your-secret"
SIGNATURE=$(echo -n "$LICENSE_ID" | openssl dgst -sha256 -hmac "$HMAC_SECRET" | awk '{print $2}')
TOKEN="${LICENSE_ID}-${SIGNATURE}"

echo "Token: ai-license=${TOKEN}"
```

## Error Codes (401 Response)

| Error Code | Meaning |
|------------|---------|
| `empty_token` | No token provided |
| `malformed_token` | Token missing hyphen separator |
| `invalid_version_id` | Version ID not numeric |
| `invalid_signature_format` | Signature not 64-char hex |
| `hmac_secret_not_configured` | Server missing HMAC secret |
| `signature_invalid` | HMAC signature doesn't match |

## Troubleshooting

### ❌ AI bots getting through without payment
```
Check: Settings → AI License → Enforcement Mode
Ensure: "Enabled" is ON
Ensure: "Observation Mode" is OFF
```

### ❌ Valid tokens getting 401
```bash
# Verify token generation matches server
echo -n "12345" | openssl dgst -sha256 -hmac "your-secret"

# Check server has correct secret
Settings → AI License → HMAC License Secret
```

### ❌ Search engines getting blocked
```
This should NEVER happen - indicates bug
Check: WordPress error logs
Check: Search_Verifier reverse DNS lookups
```

## Performance Tips

- HMAC validation adds <1ms overhead
- Bot detection is cached for 15 minutes
- Search verification is cached for 12 hours
- No database queries for token validation

## Security Checklist

- [x] HMAC secret is strong (32+ chars random)
- [x] Secret never exposed in logs or client code
- [x] Using password field in admin (masked)
- [x] Rate limiting enabled (100 req/5min default)
- [x] Search engines verified via reverse DNS
- [x] Timing-safe comparison prevents timing attacks

## Full Documentation

- **Complete Guide:** `docs/HMAC_BLOCKING.md`
- **Implementation Details:** `IMPLEMENTATION_SUMMARY.md`
- **Test Suite:** `./test-hmac-blocking.sh`

## Support

- **Docs:** https://docs.copyright.sh
- **GitHub:** https://github.com/tymrtn/ai-license-wp
- **Email:** support@copyright.sh

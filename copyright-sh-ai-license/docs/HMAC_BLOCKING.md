# HTTP 402 Payment Required Blocking with HMAC License Token Validation

## Overview

This WordPress plugin implements HTTP 402 Payment Required blocking for AI crawlers using HMAC-SHA256 license token validation. AI bots without valid license tokens receive a 402 response with payment instructions, while bots with valid tokens are granted access.

## How It Works

### 1. Bot Detection

The plugin detects AI crawlers by analyzing the User-Agent header. Detected bots include:

**AI Crawlers (require payment):**
- GPTBot (OpenAI)
- ChatGPT-User (OpenAI)
- anthropic-ai, Claude-Web (Anthropic)
- CCBot (Common Crawl)
- PerplexityBot (Perplexity)
- Google-Extended (Google AI)
- ByteSpider (TikTok)
- And many more...

**Search Engines (always allowed):**
- Googlebot (verified via reverse DNS)
- Bingbot (verified via reverse DNS)
- DuckDuckBot (verified via reverse DNS)
- Applebot (verified via reverse DNS)

### 2. License Token Validation

License tokens are passed via URL parameter:

```
https://example.com/article?ai-license=12345-abc123def456
```

**Token Format:**
```
license_version_id-license_sig
```

- `license_version_id`: Integer ID from the AI License Ledger
- `license_sig`: HMAC-SHA256 signature computed as `hash_hmac('sha256', license_version_id, HMAC_SECRET)`

### 3. Response Flow

```
Request from AI bot
    ↓
Is it a search engine? → YES → Allow (200 OK)
    ↓ NO
Has ai-license parameter? → NO → Return 402 Payment Required
    ↓ YES
Validate HMAC signature
    ↓
Valid? → YES → Allow (200 OK) + log usage
    ↓ NO
Return 401 Unauthorized
```

## Configuration

### Admin Settings

1. Navigate to **Settings → AI License** in WordPress admin
2. Find the **HMAC License Secret** field under "Pricing & Payment"
3. Enter your HMAC secret key from the AI License Ledger admin dashboard
4. Click **Save Changes**

**Important:** The HMAC secret must match the secret used by the AI License Ledger API to generate license tokens.

### Get Your HMAC Secret

Contact the AI License Ledger administrator or access your ledger admin dashboard at:
```
https://ai-license-ledger.ddev.site/admin
```

## HTTP Responses

### 402 Payment Required

Returned when an AI bot attempts access without a valid license token.

**Status Code:** `402 Payment Required`

**Headers:**
```
HTTP/1.1 402 Payment Required
Content-Type: application/json
WWW-Authenticate: License realm="copyright.sh", methods="x402 jwt hmac-sha256"
X-License-Terms: allow;distribution:public;price:0.10;payto:example.com
```

**Body:**
```json
{
  "error": "Payment Required",
  "price_per_1k_tokens": 0.10,
  "currency": "USD",
  "payto": "example.com",
  "acquire_license_url": "https://ai-license-ledger.ddev.site/api/v1/licenses/acquire",
  "documentation": "https://docs.copyright.sh/api/licenses"
}
```

### 401 Unauthorized

Returned when an AI bot provides an invalid license token.

**Status Code:** `401 Unauthorized`

**Headers:**
```
HTTP/1.1 401 Unauthorized
Content-Type: application/json
WWW-Authenticate: License realm="copyright.sh", error="signature_invalid"
```

**Body:**
```json
{
  "status": 401,
  "title": "Unauthorized request",
  "detail": "The provided licence token is missing, expired, or invalid.",
  "error": "signature_invalid"
}
```

**Possible Error Codes:**
- `empty_token` - No token provided
- `malformed_token` - Token format is incorrect
- `invalid_version_id` - Version ID is not numeric
- `invalid_signature_format` - Signature is not hex
- `hmac_secret_not_configured` - Server not configured
- `signature_invalid` - HMAC signature does not match

### 200 OK

Returned for:
- Valid license tokens
- Verified search engines
- Regular users/browsers
- Whitelisted user agents

## Testing

### Manual Testing with curl

```bash
# Test 1: AI bot without token (should get 402)
curl -I -A "GPTBot/1.0" https://example.com/

# Test 2: Search engine (should get 200)
curl -I -A "Googlebot/2.1" https://example.com/

# Test 3: Generate valid token and test
LICENSE_ID="12345"
HMAC_SECRET="your-secret-here"
SIGNATURE=$(echo -n "$LICENSE_ID" | openssl dgst -sha256 -hmac "$HMAC_SECRET" | awk '{print $2}')
curl -I -A "GPTBot/1.0" "https://example.com/?ai-license=${LICENSE_ID}-${SIGNATURE}"

# Test 4: Invalid token (should get 401)
curl -I -A "GPTBot/1.0" "https://example.com/?ai-license=12345-invalid"
```

### Automated Test Suite

Run the included test script:

```bash
./test-hmac-blocking.sh https://your-site.com your-hmac-secret
```

This tests:
1. ✅ AI bots without tokens get 402
2. ✅ Search engines are whitelisted (200)
3. ✅ Valid HMAC tokens grant access (200)
4. ✅ Invalid signatures are rejected (401)
5. ✅ Malformed tokens are rejected (401)
6. ✅ Different AI bots are blocked consistently
7. ✅ Regular browsers are allowed

## Performance

### Overhead
- **<10ms** added latency for token validation
- HMAC validation is cryptographically secure and very fast
- No database queries required for token validation
- No external API calls for validation

### Caching
- Bot detection results are cached for 15 minutes per IP
- Search engine verification is cached for 12 hours
- HMAC verifier is lazily loaded and singleton

## Security Considerations

### HMAC Secret Storage
- Store HMAC secret securely in WordPress options (encrypted at rest)
- Never expose HMAC secret in client-side code
- Rotate secret periodically (coordinate with Ledger API)

### Timing-Safe Comparison
The plugin uses `hash_equals()` for constant-time string comparison to prevent timing attacks:

```php
if ( ! hash_equals( $expected_sig, strtolower( $license_sig ) ) ) {
    return $this->invalid( 'signature_invalid' );
}
```

### Rate Limiting
The plugin includes rate limiting (default: 100 requests per 5 minutes per IP) to prevent abuse and DoS attacks.

## Integration with AI License Ledger

### License Acquisition Flow

1. AI bot requests content without token → receives 402
2. AI bot calls `acquire_license_url` with payment
3. Ledger API validates payment and generates license token
4. Ledger API returns token: `{license_version_id}-{license_sig}`
5. AI bot requests content with token → receives 200 + content
6. WordPress logs usage event to analytics queue

### Usage Logging

When a valid HMAC token is used, the plugin logs:

```php
[
  'request_url'      => 'https://example.com/article',
  'purpose'          => 'ai-crawl-licensed',
  'token_type'       => 'hmac',
  'license_token'    => [
    'license_version_id' => 12345,
    'license_sig'        => 'abc123...',
    'token_type'         => 'hmac'
  ],
  'user_agent'       => 'GPTBot/1.0',
]
```

This data is queued for async dispatch to the analytics backend.

## Architecture

### Key Components

1. **Hmac_Token_Verifier** (`includes/Auth/Hmac_Token_Verifier.php`)
   - Validates HMAC-SHA256 signatures
   - Extracts and parses license tokens from URL parameters
   - Returns validation result with error codes

2. **Enforcement_Manager** (`includes/Blocking/Enforcement_Manager.php`)
   - Orchestrates the enforcement flow
   - Checks HMAC tokens before JWT tokens
   - Handles allow/block decisions
   - Logs usage events

3. **Response_Builder** (`includes/Blocking/Response_Builder.php`)
   - Builds standardized HTTP responses
   - Generates 402 Payment Required with pricing info
   - Generates 401 Unauthorized for invalid tokens

4. **Bot_Detector** (`includes/Blocking/Bot_Detector.php`)
   - Pattern matching for AI crawler detection
   - Scoring system for unknown bots
   - Search engine verification via reverse DNS

### Service Registration

The HMAC verifier is registered in the dependency injection container:

```php
$services->set(
    Hmac_Token_Verifier::class,
    static function ( Service_Provider $container ) {
        return new Hmac_Token_Verifier(
            $container->get( Options_Repository::class )
        );
    }
);
```

## Troubleshooting

### AI bots are not getting 402 responses

1. **Check enforcement is enabled:**
   - Settings → AI License → Enforcement Mode → Enabled

2. **Check bot patterns are current:**
   - Plugin auto-updates patterns from remote feed
   - Verify detection threshold (default: 60)

3. **Check observation mode:**
   - Observation mode lets bots through but logs them
   - Disable to enforce blocking

### Valid tokens are being rejected (401)

1. **Verify HMAC secret matches Ledger API:**
   ```bash
   # Test token generation matches
   echo -n "12345" | openssl dgst -sha256 -hmac "your-secret"
   ```

2. **Check token format:**
   - Must be `{numeric-id}-{64-char-hex-signature}`
   - No spaces, extra characters, or URL encoding

3. **Check WordPress error logs:**
   - Look for specific error codes in 401 responses
   - Common issues: `hmac_secret_not_configured`

### Search engines are getting blocked

This should never happen. If it does:

1. **Verify reverse DNS is working:**
   ```bash
   dig -x [IP_ADDRESS]
   ```

2. **Check search engine verification cache:**
   - Cached for 12 hours
   - Clear cache: delete `searchbot_*` transients

3. **Check allow list:**
   - Settings → AI License → Allow List
   - Verify search engine UAs aren't accidentally blocked

## Future Enhancements

- [ ] Support for JWT tokens (already implemented, runs after HMAC)
- [ ] Token TTL and expiration checking
- [ ] User-specific token binding
- [ ] Multi-stage license tiers (infer, train, embed, tune)
- [ ] Real-time token revocation via API webhook
- [ ] Dashboard for viewing blocked requests and usage stats

## Support

For technical support or questions:
- Documentation: https://docs.copyright.sh
- GitHub Issues: https://github.com/tymrtn/ai-license-wp
- Email: support@copyright.sh

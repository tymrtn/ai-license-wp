# WordPress Plugin v2.0.0 Testing Protocol

**Date**: 2025-10-16
**Plugin Version**: 2.0.0
**Test Environment**: DDEV Local (https://copyrightish.ddev.site)
**Database**: wp_csh_ai_usage_queue table created successfully

## 🎯 Testing Objectives

1. Validate 402 Payment Required enforcement
2. Verify profile-based configuration (default/strict/audit)
3. Test observation mode → enforcement transition
4. Validate JWT token verification
5. Test rate limiting and token bucket
6. Verify usage queue and async logging
7. Test crawler detection and scoring
8. Validate health diagnostics panel

## 📋 Pre-Test Setup Checklist

- [x] Plugin v2.0.0 installed on https://copyrightish.ddev.site
- [x] Database table `wp_csh_ai_usage_queue` created
- [x] Plugin activated successfully
- [ ] Admin settings configured with test profile
- [ ] Observation mode status verified
- [ ] JWKS cache populated

## 🧪 Test Suite

### 1. Profile Configuration Tests

#### 1.1 Default Profile ("Demand Licence")
**Objective**: Verify default profile applies correct settings

**Steps**:
```bash
# Access admin settings
open https://copyrightish.ddev.site/wp-admin/admin.php?page=copyright-sh-ai-license

# Or via WP-CLI
ddev exec wp option get csh_ai_license_settings --path=/var/www/html/wordpress --format=json
```

**Expected Results**:
- Threshold: 60
- Rate limit: 120 requests / 300 seconds
- Allow list: Googlebot, Bingbot, etc. (search engines)
- Challenge list: GPTBot, ClaudeBot, PerplexityBot (AI crawlers)
- Block list: Bad actors (scrapers)

**Validation**:
```bash
# Check if profile settings are applied
ddev exec wp db query "SELECT * FROM wp_options WHERE option_name='csh_ai_license_settings'" --path=/var/www/html/wordpress
```

#### 1.2 Strict Profile ("Block Everything New")
**Objective**: Verify strict mode blocks all AI except search

**Test Command**:
```bash
# Switch to strict profile via settings page
# Then test with curl
curl -A "GPTBot" https://copyrightish.ddev.site/
```

**Expected**: 403 Forbidden (GPTBot is in block list for strict profile)

#### 1.3 Audit Profile ("Observe Traffic")
**Objective**: Verify audit mode logs without blocking

**Expected Results**:
- Threshold: 90 (very permissive)
- No blocking occurs
- All requests logged to usage queue

### 2. Observation Mode Tests

#### 2.1 Observation Mode Active
**Objective**: Verify no enforcement occurs during observation

**Steps**:
```bash
# Verify observation mode is enabled
ddev exec wp option get csh_ai_license_settings --path=/var/www/html/wordpress | grep observation

# Test with AI crawler UA
curl -A "GPTBot/1.0" -I https://copyrightish.ddev.site/
```

**Expected Results**:
- HTTP 200 OK (no blocking)
- Request logged to usage queue
- Admin notice shows observation countdown

**Database Verification**:
```bash
ddev exec wp db query "SELECT * FROM wp_csh_ai_usage_queue ORDER BY enqueue_ts DESC LIMIT 5" --path=/var/www/html/wordpress
```

#### 2.2 Observation Mode Expired
**Objective**: Verify enforcement begins after observation period

**Steps**:
1. Disable observation mode in admin settings
2. Test with unlicensed AI crawler

```bash
curl -A "GPTBot/1.0" -I https://copyrightish.ddev.site/
```

**Expected**: HTTP 402 Payment Required with WWW-Authenticate header

### 3. Crawler Detection & Enforcement Tests

#### 3.1 Search Engine Bypass (Allowlist)
**Objective**: Verify verified search bots always pass

**Test Commands**:
```bash
# Test Googlebot (should pass even with strict enforcement)
curl -A "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)" -I https://copyrightish.ddev.site/

# Test Bingbot
curl -A "Mozilla/5.0 (compatible; Bingbot/2.0; +http://www.bing.com/bingbot.htm)" -I https://copyrightish.ddev.site/
```

**Expected**: HTTP 200 OK (search bots never blocked)

**Verification**: Check reverse DNS verification works
```bash
# Plugin should verify bot IPs via reverse DNS
# Log should show "verified search bot" in status
```

#### 3.2 AI Crawler Challenge (402 Response)
**Objective**: Verify 402 Payment Required for unlicensed AI bots

**Test Commands**:
```bash
# Test GPTBot
curl -A "GPTBot/1.0" -v https://copyrightish.ddev.site/ 2>&1 | grep -E "(HTTP|WWW-Authenticate|X-License)"

# Test ClaudeBot
curl -A "ClaudeBot/1.0" -v https://copyrightish.ddev.site/ 2>&1 | grep -E "(HTTP|WWW-Authenticate|X-License)"

# Test PerplexityBot
curl -A "PerplexityBot/1.0" -v https://copyrightish.ddev.site/ 2>&1 | grep -E "(HTTP|WWW-Authenticate|X-License)"
```

**Expected Response Headers**:
```
HTTP/1.1 402 Payment Required
WWW-Authenticate: License realm="copyright.sh", methods="x402 jwt hmac-sha256"
X-License-Terms: allow;distribution:public;price:0.50;payto:copyrightish.ddev.site
Link: <https://ledger.copyright.sh/register>; rel="license-register"
Cache-Control: private, no-store
Content-Type: application/json
```

**Expected JSON Body**:
```json
{
  "version": "1.0",
  "type": "payment_required",
  "title": "License Required",
  "detail": "This content requires a valid AI license.",
  "payment_request_url": "https://ledger.copyright.sh/register",
  "payment_context_token": "[JWT token]",
  "offers": [
    {
      "price": 0.50,
      "currency": "USD",
      "unit": "per_1k_tokens",
      "distribution": "public"
    }
  ],
  "terms_url": "https://copyright.sh/terms"
}
```

#### 3.3 Bad Actor Blocking (403 Response)
**Objective**: Verify hard blocks for scrapers

**Test Commands**:
```bash
# Test generic scrapers
curl -A "python-requests/2.28.0" -I https://copyrightish.ddev.site/
curl -A "Scrapy/2.5.0" -I https://copyrightish.ddev.site/
curl -A "curl/7.68.0" -I https://copyrightish.ddev.site/
```

**Expected**: HTTP 403 Forbidden

#### 3.4 Rate Limiting (429 Response)
**Objective**: Verify token bucket rate limiting

**Test Script**:
```bash
# Rapid fire requests to trigger rate limit (default: 120 req / 300s)
for i in {1..125}; do
  curl -A "GPTBot/1.0" -I https://copyrightish.ddev.site/ 2>&1 | grep "HTTP" &
done
wait

# After ~120 requests, should see 429
curl -A "GPTBot/1.0" -I https://copyrightish.ddev.site/
```

**Expected**: HTTP 429 Too Many Requests with Retry-After header

### 4. JWT Token Verification Tests

#### 4.1 Valid JWT Token (200 OK)
**Objective**: Verify licensed bots with valid tokens pass

**Note**: This requires generating a valid JWT token signed by Copyright.sh Ledger

**Test Command**:
```bash
# Generate test JWT token (requires HMAC_SECRET_KEY from ledger)
# For now, manual test through admin dashboard

# Test with valid token
curl -H "Authorization: Bearer [VALID_JWT_TOKEN]" -I https://copyrightish.ddev.site/
```

**Expected**: HTTP 200 OK with tracking headers

#### 4.2 Invalid/Expired JWT Token (401 Unauthorized)
**Objective**: Verify expired tokens are rejected

**Test Command**:
```bash
curl -H "Authorization: Bearer eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.invalid.signature" -I https://copyrightish.ddev.site/
```

**Expected**: HTTP 401 Unauthorized with renewal instructions

#### 4.3 JWKS Cache Verification
**Objective**: Verify JWKS caching works (24h TTL)

**Steps**:
```bash
# Check JWKS transient in database
ddev exec wp db query "SELECT * FROM wp_options WHERE option_name LIKE '%jwks%'" --path=/var/www/html/wordpress

# Check last sync timestamp in admin health panel
open https://copyrightish.ddev.site/wp-admin/admin.php?page=copyright-sh-ai-license
```

**Expected**: JWKS cached with recent timestamp

### 5. Usage Queue & Async Logging Tests

#### 5.1 Queue Population
**Objective**: Verify requests are logged to usage queue

**Steps**:
```bash
# Make several test requests
curl -A "GPTBot/1.0" https://copyrightish.ddev.site/
curl -A "ClaudeBot/1.0" https://copyrightish.ddev.site/
curl -A "PerplexityBot/1.0" https://copyrightish.ddev.site/

# Check queue
ddev exec wp db query "SELECT id, request_url, purpose, user_agent, status, enqueue_ts FROM wp_csh_ai_usage_queue ORDER BY enqueue_ts DESC LIMIT 10" --path=/var/www/html/wordpress
```

**Expected**: Entries in queue with status='pending'

#### 5.2 WP-Cron Dispatcher
**Objective**: Verify cron job processes queue

**Steps**:
```bash
# List cron events
ddev exec wp cron event list --path=/var/www/html/wordpress | grep csh

# Manually trigger queue dispatcher
ddev exec wp cron event run csh_ai_usage_queue_dispatch --path=/var/www/html/wordpress

# Check queue status after dispatch
ddev exec wp db query "SELECT status, COUNT(*) as count FROM wp_csh_ai_usage_queue GROUP BY status" --path=/var/www/html/wordpress
```

**Expected**: Status changes from 'pending' to 'dispatched' or 'failed'

#### 5.3 Idempotency Verification
**Objective**: Verify duplicate requests aren't logged twice

**Steps**:
```bash
# Make same request multiple times quickly
for i in {1..5}; do
  curl -A "GPTBot/1.0" https://copyrightish.ddev.site/sample-page/
done

# Check for unique idempotency_key
ddev exec wp db query "SELECT idempotency_key, COUNT(*) as count FROM wp_csh_ai_usage_queue GROUP BY idempotency_key HAVING count > 1" --path=/var/www/html/wordpress
```

**Expected**: No duplicate idempotency keys

### 6. Health Diagnostics Tests

#### 6.1 Health Panel Display
**Objective**: Verify health metrics are accurate

**Steps**:
1. Access admin settings page
2. Scroll to "Health Diagnostics" section

**Expected Metrics**:
- Last JWKS sync timestamp
- Pattern feed sync status
- Usage queue depth (number of pending entries)
- Recent errors (if any)
- Top crawlers list with quick actions

#### 6.2 Manual JWKS Refresh
**Objective**: Verify manual refresh button works

**Steps**:
1. Click "Refresh JWKS" button in health panel
2. Verify timestamp updates

**Database Check**:
```bash
ddev exec wp db query "SELECT option_value FROM wp_options WHERE option_name='_transient_csh_ai_jwks_cache'" --path=/var/www/html/wordpress
```

#### 6.3 Quick Actions (Promote/Challenge/Block)
**Objective**: Verify one-click crawler management

**Steps**:
1. View top crawlers in health panel
2. Click "Promote" on a crawler (move to allow list)
3. Click "Challenge" on a crawler (move to challenge list)
4. Click "Block" on a crawler (move to block list)

**Verification**:
```bash
# Check updated lists
ddev exec wp option get csh_ai_license_settings --path=/var/www/html/wordpress --format=json | jq '.allow_list, .challenge_list, .block_list'
```

### 7. Admin Experience Tests

#### 7.1 Observation Mode Notice
**Objective**: Verify prominent observation mode notice

**Steps**:
1. Ensure observation mode is enabled
2. Navigate to WordPress admin dashboard

**Expected**: Yellow admin notice showing:
- "Observation Mode Active"
- Time remaining (e.g., "23 hours remaining")
- "Extend" and "End Observation" buttons

#### 7.2 Profile Switching
**Objective**: Verify profile changes apply settings

**Steps**:
1. Select "Strict – Block Everything New" profile
2. Save settings
3. Test with AI crawler
4. Switch to "Audit Only – Observe Traffic" profile
5. Save settings
6. Test with AI crawler

**Expected**: Behavior changes based on profile

#### 7.3 Settings Validation
**Objective**: Verify input validation works

**Test Cases**:
- Invalid threshold value (>100 or <0)
- Invalid rate limit values (negative numbers)
- Malformed UA/IP in allow/block lists
- Invalid pricing values

**Expected**: Error messages prevent saving invalid settings

### 8. Integration Tests

#### 8.1 Robots.txt Sync
**Objective**: Verify robots.txt aligns with PHP enforcement

**Steps**:
```bash
# Check robots.txt
curl https://copyrightish.ddev.site/robots.txt

# Verify sync with enforcement settings
ddev exec wp option get csh_ai_license_settings --path=/var/www/html/wordpress | grep robots_enabled
```

**Expected**: robots.txt blocks same bots as PHP enforcement

#### 8.2 Meta Tag Injection
**Objective**: Verify meta tags still work with v2.0.0

**Steps**:
```bash
# Check homepage for meta tag
curl -s https://copyrightish.ddev.site/ | grep -i "ai-license"

# Check post with override
curl -s https://copyrightish.ddev.site/sample-page/ | grep -i "ai-license"
```

**Expected**: Meta tags present with correct license grammar

#### 8.3 ai-license.txt Endpoint
**Objective**: Verify ai-license.txt still works

**Steps**:
```bash
curl https://copyrightish.ddev.site/ai-license.txt
```

**Expected**: License file with correct grammar

### 9. Performance Tests

#### 9.1 Detection Speed
**Objective**: Verify detection adds <1ms overhead

**Test Script**:
```bash
# Benchmark without plugin (deactivate first)
ddev exec wp plugin deactivate copyright-sh-ai-license --path=/var/www/html/wordpress
time curl -s https://copyrightish.ddev.site/ > /dev/null

# Benchmark with plugin
ddev exec wp plugin activate copyright-sh-ai-license --path=/var/www/html/wordpress
time curl -s https://copyrightish.ddev.site/ > /dev/null
```

**Expected**: <5ms difference

#### 9.2 Cache Effectiveness
**Objective**: Verify transient caching reduces DB queries

**Steps**:
1. Enable Query Monitor plugin
2. Make multiple requests with same UA
3. Check DB query count

**Expected**: Subsequent requests use cached detection results

### 10. Security Tests

#### 10.1 JTI Replay Protection
**Objective**: Verify same token can't be used twice

**Test**: (Requires valid JWT)
```bash
# Use same token twice
curl -H "Authorization: Bearer [SAME_TOKEN]" https://copyrightish.ddev.site/
curl -H "Authorization: Bearer [SAME_TOKEN]" https://copyrightish.ddev.site/
```

**Expected**: Second request fails with 401 (replay detected)

#### 10.2 SQL Injection Protection
**Objective**: Verify input sanitization

**Test Cases**:
```bash
# Attempt SQL injection in UA
curl -A "'; DROP TABLE wp_csh_ai_usage_queue; --" https://copyrightish.ddev.site/

# Check table still exists
ddev exec wp db query "SHOW TABLES LIKE 'wp_csh_ai_usage_queue'" --path=/var/www/html/wordpress
```

**Expected**: Table intact, request safely handled

## 🎬 Quick Test Workflow

For rapid validation, run this sequence:

```bash
# 1. Verify plugin is active
ddev exec wp plugin list --path=/var/www/html/wordpress | grep copyright

# 2. Check database table
ddev exec wp db query "SHOW TABLES LIKE 'wp_csh%'" --path=/var/www/html/wordpress

# 3. Test search bot (should pass)
curl -A "Googlebot/2.1" -I https://copyrightish.ddev.site/ | head -1

# 4. Test AI crawler in observation mode (should pass with 200)
curl -A "GPTBot/1.0" -I https://copyrightish.ddev.site/ | head -1

# 5. Disable observation mode via admin UI
open https://copyrightish.ddev.site/wp-admin/admin.php?page=copyright-sh-ai-license

# 6. Test AI crawler with enforcement (should get 402)
curl -A "GPTBot/1.0" -I https://copyrightish.ddev.site/ | head -3

# 7. Check usage queue populated
ddev exec wp db query "SELECT COUNT(*) FROM wp_csh_ai_usage_queue" --path=/var/www/html/wordpress

# 8. Trigger queue dispatch
ddev exec wp cron event run csh_ai_usage_queue_dispatch --path=/var/www/html/wordpress

# 9. View health diagnostics
open https://copyrightish.ddev.site/wp-admin/admin.php?page=copyright-sh-ai-license
```

## 📊 Test Results Template

| Test Category | Test Case | Status | Notes |
|--------------|-----------|--------|-------|
| Profile Configuration | Default Profile | ⏳ | |
| Profile Configuration | Strict Profile | ⏳ | |
| Profile Configuration | Audit Profile | ⏳ | |
| Observation Mode | Active Mode Logging | ⏳ | |
| Observation Mode | Enforcement After Expiry | ⏳ | |
| Crawler Detection | Search Engine Bypass | ⏳ | |
| Crawler Detection | AI Crawler 402 Challenge | ⏳ | |
| Crawler Detection | Bad Actor 403 Block | ⏳ | |
| Crawler Detection | Rate Limit 429 | ⏳ | |
| JWT Verification | Valid Token Pass | ⏳ | |
| JWT Verification | Invalid Token Reject | ⏳ | |
| Usage Queue | Queue Population | ⏳ | |
| Usage Queue | Cron Dispatcher | ⏳ | |
| Usage Queue | Idempotency | ⏳ | |
| Health Diagnostics | Panel Display | ⏳ | |
| Health Diagnostics | JWKS Refresh | ⏳ | |
| Health Diagnostics | Quick Actions | ⏳ | |
| Admin Experience | Observation Notice | ⏳ | |
| Admin Experience | Profile Switching | ⏳ | |
| Integration | Robots.txt Sync | ⏳ | |
| Integration | Meta Tag Injection | ⏳ | |
| Performance | Detection Speed | ⏳ | |
| Security | Replay Protection | ⏳ | |
| Security | SQL Injection Protection | ⏳ | |

**Status Key**: ✅ Pass | ❌ Fail | ⏳ Pending | ⚠️ Warning

## 🐛 Known Issues & Workarounds

*Document any issues found during testing here*

## 📝 Notes

- Default admin credentials: admin / password
- Site URL: https://copyrightish.ddev.site
- Admin URL: https://copyrightish.ddev.site/wp-admin
- Database accessible via: `ddev exec wp db query "QUERY" --path=/var/www/html/wordpress`

## ✅ Sign-Off

- [ ] All critical tests passing
- [ ] Performance acceptable (<5ms overhead)
- [ ] Security tests passed
- [ ] Documentation updated
- [ ] Ready for deployment

---

**Testing completed by**: _____________
**Date**: _____________
**Approved by**: _____________

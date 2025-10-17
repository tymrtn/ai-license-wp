# Copyright.sh AI License Plugin — v2.0.0 Architecture

*Status: Draft for implementation — October 2025*

This document consolidates the v2 requirements from the PHP Bot Blocking specification, the technical roadmap, and the strategic roadmap. It defines the scope and architecture for the WordPress plugin v2.0.0 release.

## 1. Release Goals
- Graduate from metadata-only protection to active crawler enforcement with monetization pathways.
- Deliver Cloudflare/Tollbit-style 402 parity entirely within WordPress PHP execution.
- Provide a foundation for standards compliance (IETF AIPREF, IPTC/PLUS, C2PA) and future multi-asset protection.
- Preserve zero-friction setup for creators while introducing richer controls and observability.

## 2. High-Level Capabilities
1. **Crawler Detection & Scoring**
   - Pattern-based UA/IP classification (signed pattern feed with local cache).
   - Reverse DNS verification for trusted search bots (Google, Bing, Apple) — never block.
   - Behavioral scoring (rate/rationale) with configurable threshold (default 60).
2. **Graduated Enforcement**
   - Observation Mode (default 24 hours) to observe without blocking.
   - Enforcement outcomes:
     - 200 OK (authenticated/allowed).
     - 200 OK with payment-warning headers (account delinquent, future phase).
     - 402 Payment Required (unknown AI traffic over threshold).
     - 401 Unauthorized (bad/expired license token).
     - 403 Forbidden (explicit block list).
     - 429 Too Many Requests (rate limit exceeded).
   - Machine-readable 402 JSON body + WWW-Authenticate negotiation headers.
3. **Authentication & Monetization Hooks**
   - Accept JWT license tokens (`lt-single`, `lt-bulk`) signed by Copyright.sh Ledger.
   - Offline JWKS cache (24h refresh) with exponential backoff on failure.
   - Replay protection via transient storage of `jti` until expiration.
4. **Asynchronous Usage Logging**
   - Local queue table storing request events (`url`, `purpose`, `token_type`, `tokens`, `ts`, `idempotency_key`, `status`).
   - WP-Cron worker batching POSTs to `/v1/usage/batch-log` with per-record idempotency.
   - Circuit breaker to avoid repeated failures; metrics surfaced in admin Health panel.
5. **Rate Limiting & Caching**
   - Token bucket per UA/IP (default 100 requests / 5 minutes) stored via transients.
   - Authentication/detection caches with layered TTLs (signature cache 15 min, pattern feed 24h).
6. **Robots.txt Management**
   - Continue optional managed robots.txt with curated AI block list and allow-lists.
   - Ensure compatibility with new PHP-level blocking (educational copy in UI).
7. **Admin Experience**
   - New Bot Enforcement settings tab:
     - Profile selector with curated presets (default, strict, audit-only).
     - Observation Mode toggle and remaining duration.
     - Enforcement enable/disable.
     - Allow/Block lists (UA/IP/domain).
     - Threshold & rate limit controls.
     - Health diagnostics (last JWKS sync, pattern feed status, queue depth, last error).
     - One-click adjustments from the health panel to allow, challenge, or block crawlers.
   - Updated copy aligning with product marketing (“Don’t just block AI — get paid”).
   - Nonce-protected AJAX endpoints for status checks and manual sync triggers.
8. **Telemetry & Analytics**
   - Optional local log for blocked/authenticated requests with filters for debugging.
   - Surfaced counts by response code for previous 24h.
9. **Developer Extensibility**
   - Filters/actions for custom bot patterns, scoring modifiers, authentication providers.
   - Modular class structure to support Phase 3 multi-asset expansion.

## 3. Architecture Overview

```
copyright-sh-ai-license/
├── copyright-sh-ai-license.php        # Bootstrap (plugin header, autoloader, service wiring)
├── uninstall.php
├── includes/
│   ├── Service_Provider.php           # Simple container for shared services
│   ├── Admin/
│   │   ├── Settings_Page.php
│   │   ├── Meta_Box.php
│   │   └── Notices.php
│   ├── Api/
│   │   ├── Ajax_Controller.php
│   │   └── Rest_Controller.php        # reserved for dashboard/API sync
│   ├── Blocking/
│   │   ├── Bot_Detector.php
│   │   ├── Bot_Scorer.php
│   │   ├── Rate_Limiter.php
│   │   ├── Enforcement_Manager.php
│   │   └── Response_Builder.php
│   ├── Auth/
│   │   ├── Token_Verifier.php
│   │   └── Jwks_Cache.php
│   ├── Logging/
│   │   ├── Usage_Queue.php
│   │   └── Event_Logger.php
│   ├── Robots/
│   │   ├── Manager.php
│   │   └── Template.php
│   ├── Http/
│   │   ├── Remote_Fetcher.php
│   │   └── Header_Manager.php
│   ├── Settings/
│   │   ├── Options_Repository.php
│   │   └── Defaults.php
│   ├── Utilities/
│   │   ├── Array_Helper.php
│   │   ├── Clock.php
│   │   └── Transient_Helper.php
│   └── Plugin.php                     # Main orchestrator (replaces singleton)
└── docs/
    └── v2-architecture.md
```

*Note:* Some files may be stubs for future phases but establish structure now.

## 4. Key Data Structures
- **Options (`csh_ai_license_settings`)**: General license, enforcement, observation mode, allow/block lists, thresholds, rate limits.
- **Account Status (`csh_ai_license_account_status`)**: Keep existing schema.
- **Transients**:
  - `csh_ai_bot_patterns`: Signed pattern feed cache (JSON).
  - `csh_ai_detect_cache_{hash}`: Short-term detection results (15 min).
  - `csh_ai_auth_cache_{token}`: Cached verification results (15 min).
  - `csh_ai_rate_{fingerprint}`: Token bucket state.
  - `csh_ai_jti_{hash}`: Replay prevention until token expiry.
- **Database Table (`wp_csh_ai_usage_queue`)**:
  - Columns: `id`, `request_url`, `purpose`, `token_type`, `token_claims`, `estimated_tokens`, `status`, `enqueue_ts`, `dispatch_ts`, `idempotency_key`, `response_code`, `error_message`.
  - Installed/updated via dbDelta on activation.

## 5. Detection & Enforcement Flow
1. `init` (priority 1):
   - Skip if CLI/admin/ajax unless front-end hit for admin bar preview.
   - Gather request context (IP, UA, headers).
   - Run Allow list checks first (e.g., search bots, whitelisted IPs).
   - Compute bot score via `Bot_Detector` (patterns) + `Bot_Scorer` (behaviour).
   - Evaluate rate limiting via `Rate_Limiter`.
   - Verify Authorization token if present (JWT → `Token_Verifier`).
   - Cache result in transient keyed by UA/IP slice.
   - If enforcement disabled or Observation Mode active → log & return.
   - Otherwise store enforcement decision in request globals via singleton service.
2. `template_redirect`:
   - Inspect decision; if block required, issue appropriate response using `Response_Builder`.
   - For 402 responses, include machine-readable JSON body and headers per spec.
3. `shutdown` (or custom hook):
   - Dispatch usage queue entry asynchronously (transient-based or `wp_schedule_single_event`).

## 6. HTTP Response Details
- `WWW-Authenticate`: `License realm="copyright.sh", methods="x402 jwt hmac-sha256"`
- `X-License-Terms`: Mirror license string (allow/deny/price/payto/distribution).
- `Link`: `<https://ledger.copyright.sh/register>; rel="license-register"`
- `Cache-Control`: `private, no-store`
- JSON body fields: `version`, `type`, `title`, `detail`, `payment_request_url`, `payment_context_token`, `offers[]`, `terms_url`.
- 429 responses include `Retry-After`.
- 401 responses include renewal instructions if account connected.

## 7. Admin Experience Highlights
- **Observation Mode Toggle**: When enabled, log decisions without blocking. Display countdown to enforcement (with ability to extend or disable).
- **Enforcement Toggle**: Hard on/off switch separate from observation mode.
- **Allow/Block Lists**: Multi-line textarea for UA/IP CIDR. Validate format.
- **Rate Limits**: Numeric inputs for requests per window + window size (seconds).
- **Threshold Slider**: Range input (0-100) with recommended defaults.
- **Health Diagnostics**: Last pattern feed sync timestamp, JWKS status, queue size, recent errors.
- **Manual Actions**: Buttons to refresh JWKS/pattern feed, flush queues.
- **Telemetry Opt-In**: Optional sending of aggregated stats back to Copyright.sh (Phase 2 placeholder).

## 8. Extensibility Contracts
- Filters:
  - `csh_ai_bot_patterns` to modify detection signatures.
  - `csh_ai_score_modifiers` to adjust computed score.
  - `csh_ai_allow_request` to bypass enforcement.
  - `csh_ai_license_offers` to modify 402 JSON offers.
- Actions:
  - `csh_ai_request_blocked` (with context).
  - `csh_ai_request_allowed`.
  - `csh_ai_jwks_updated`.
  - `csh_ai_usage_logged`.

## 9. Versioning & Compatibility
- Bump plugin to `Version: 2.0.0`.
- Requires PHP 7.4+ (matching WP requirements); add guards for 8.x compatibility.
- Activation hook ensures database table and default options.
- Deactivation clears cron jobs; uninstall drops table and options (respect user data choice).
- Backward-compatible meta/option keys retained; migration routine converts old settings to new format.

## 10. Testing Considerations
- PHPUnit coverage for detector, rate limiter, token verifier (JWKS caching).
- Integration tests for HTTP responses using WordPress test framework.
- Manual test plan aligned with spec: detection speed, rate limit accuracy, observation window, JWKS failure fallback.

---

This architecture serves as the implementation blueprint for the v2.0.0 development cycle.

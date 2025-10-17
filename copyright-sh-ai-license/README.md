# Copyright.sh – AI License Plugin for WordPress

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/copyright-sh-ai-license.svg)](https://wordpress.org/plugins/copyright-sh-ai-license/)
[![WordPress Tested](https://img.shields.io/wordpress/plugin/tested/copyright-sh-ai-license.svg)](https://wordpress.org/plugins/copyright-sh-ai-license/)
[![License](https://img.shields.io/badge/license-GPL--3.0%2B-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.html)

**Get paid when AI uses your content.** One plugin protects your entire WordPress site with machine-readable licensing that AI companies respect and pay for.

## 🚀 Quick Start

1. Install the plugin from WordPress.org or upload the ZIP file.
2. Activate and navigate to **Settings → AI License**.
3. Choose a preset profile and review the crawler enforcement section – observation mode is enabled by default so you can observe traffic before blocking.
4. Set your pricing and payment details, then save changes.

Once you flip enforcement on, unlicensed crawlers receive 402 Payment Required responses and licensed bots continue seamlessly.

## 💰 Why You Need This

### The Problem
- AI companies scrape billions of web pages daily for training data
- Your content trains AI models that generate billions in revenue
- You receive nothing in return

### The Solution
Copyright.sh provides the infrastructure for fair compensation:
- **Automatic licensing** via industry-standard meta tags
- **Usage tracking** with cryptographic verification
- **Payment processing** direct to your account
- **Network effect** – more creators = more leverage

## 🎯 Key Features

### For Content Creators
- ✅ **Guided Observation Mode** – Observe traffic safely before flipping the paywall on.
- ✅ **402/401 Enforcement** – Serve payment-required responses with machine-readable offers.
- ✅ **Smart Controls** – Rate limiting, allowlists/denylists, and verified search bot bypass.
- ✅ **Robots & PHP Alignment** – Sync robots.txt management with server-side blocking.
- ✅ **Full Audit Trail** – Every decision is queued for async reporting to the Copyright.sh ledger.

### For Developers
- ✅ **Modular Architecture** – PSR-4 services for detection, auth, logging, and admin UI.
- ✅ **JWKS + JWT Verification** – Cached keysets, RS256 signatures, and replay protection out of the box.
- ✅ **Extensive Hooks** – Filters/actions for bot patterns, scoring, response payloads, and offers.
- ✅ **Async Usage Queue** – Cron-backed dispatcher with retry/backoff logic and health metrics.
- ✅ **Standards-Friendly** – Emits License Grammar v1.5 meta tags, ai-license.txt, and L402-compatible headers.
- ✅ **Curated Profiles & Quick Actions** – Pre-baked presets plus health-panel buttons to promote/downgrade crawlers.

## 📋 How It Works

### 1. Meta Tag Injection
The plugin adds this tag to every page:
```html
<meta name="ai-license" content="allow; distribution:public; price:0.50; payto:your.domain.com">
```

### 2. AI License Endpoint
Generates `/ai-license.txt` at your domain root:
```
# ai-license.txt - AI usage policy
User-agent: *
License: allow; distribution:public; price:0.50; payto:your.domain.com
```

### 3. Crawler Enforcement
- Bot detector scores each request via cached user-agent signatures, behaviour heuristics, and reverse DNS verification for search bots.
- Valid JWT licence tokens return **200 OK** with tracking headers.
- Unknown crawlers above the threshold receive **402 Payment Required** with WWW-Authenticate + JSON offers.
- Blocklisted UAs/IPs return **403**, and aggressive clients hit a **429** token bucket with Retry-After.
- All decisions are queued asynchronously for ledger reporting.

### 4. Optional Robots.txt Management
Robots.txt rules can mirror PHP-level enforcement while keeping SEO bots untouched:
```
User-agent: GPTBot
Disallow: /

User-agent: Googlebot
Allow: /
```

## 🔧 Configuration

### Global Settings
Navigate to **Settings → AI License** to configure:

- **Policy**: Allow or deny AI usage and set pricing metadata.
- **Enforcement**: Toggle the observation window, switch to full enforcement, and monitor the countdown.
- **Detection**: Adjust bot score threshold, rate limits, and optional allow/block lists.
- **Robots.txt**: Let the plugin manage a synchronized robots file or use your own.
- **Health**: Inspect JWKS cache age, pattern sync status, and usage queue depth.

### Per-Post Overrides
Each post/page has an "AI License Override" meta box where you can:
- Set custom pricing for premium content
- Block specific content while allowing others
- Configure different distribution levels

## 💡 Use Cases

### Bloggers & Content Sites
```
Price: $0.10/1K tokens
Distribution: Public
Result: Passive income from your archive
```

### News & Journalism
```
Price: $0.50/1K tokens
Distribution: Public
Result: Fair compensation for investigative work
```

### Technical Documentation
```
Price: $0.25/1K tokens
Distribution: Private
Result: Revenue from knowledge sharing
```

### Premium Content
```
Global: $0.10/1K tokens
Override (premium): $1.00/1K tokens
Result: Tiered pricing for valuable content
```

## 🌐 Compatible AI Systems

The plugin works with all major AI companies:
- **OpenAI** (ChatGPT, GPT-4)
- **Anthropic** (Claude)
- **Google** (Bard, Gemini)
- **Meta** (Llama)
- **Perplexity**
- All MCP-compatible systems

## 📊 Real-World Success

Major publishers are already profiting:
- **News Corp**: $250 million OpenAI deal
- **Reddit**: $60 million annual AI licensing
- **Associated Press**: Multiple AI partnerships
- **Shutterstock**: Ongoing royalties

Now individual creators can access the same opportunity.

## 🛡️ License Grammar Specification

The plugin implements the open Copyright.sh License Grammar v1.5:

```
action ; [distribution:level] ; [price:amount] ; [payto:account]
```

- **action**: `allow` or `deny`
- **distribution**: `private` or `public`
- **price**: USD per 1,000 tokens
- **payto**: Payment account identifier

## 🔄 Updates & Support

### Automatic Updates
The plugin receives regular updates via WordPress.org:
- New AI company support
- License grammar updates
- Security patches
- Performance improvements

### Documentation
- [Installation Guide](https://copyright.sh/docs)
- [API Reference](https://copyright.sh/docs#api)
- [Creator Dashboard](https://dashboard.copyright.sh)
- [Support Forum](https://wordpress.org/support/plugin/copyright-sh-ai-license/)

## 🤝 Contributing

We welcome contributions! Please see our [contributing guidelines](CONTRIBUTING.md).

### Development Setup
```bash
# Clone the repository
git clone https://github.com/tymrtn/ai-license-wp.git

# Install dependencies
composer install

# Run tests
phpunit
```

## 📄 License

This plugin is licensed under GPL-3.0 or later. See [LICENSE](LICENSE) for details.

## 🏢 About Copyright.sh

Copyright.sh is building the infrastructure for fair AI content licensing. We believe creators deserve compensation when their work powers AI systems.

- **Website**: [copyright.sh](https://copyright.sh)
- **Dashboard**: [dashboard.copyright.sh](https://dashboard.copyright.sh)
- **Twitter**: [@copyrightsh](https://twitter.com/copyrightsh)
- **Email**: support@copyright.sh

## ⚡ Quick Links

- [Download from WordPress.org](https://wordpress.org/plugins/copyright-sh-ai-license/)
- [View on GitHub](https://github.com/tymrtn/ai-license-wp)
- [Report Issues](https://github.com/tymrtn/ai-license-wp/issues)
- [Request Features](https://github.com/tymrtn/ai-license-wp/discussions)

---

**Remember**: Every day without protection is money left on the table. AI is training on your content right now. Make it pay.

© 2025 Copyright.sh. All rights reserved.

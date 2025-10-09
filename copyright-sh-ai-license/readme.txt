=== Copyright AI Content Licensing – Block AI Crawlers + Get Paid ===
Contributors:      copyrightsh
Tags:              ai-license, copyright, robots-txt, content-licensing, chatgpt
Requires at least: 6.2
Tested up to:      6.8
Requires PHP:      7.4
Stable tag:        1.6.2
License:           GPLv3 or later
License URI:       https://www.gnu.org/licenses/gpl-3.0.html

Copyright protection and AI content licensing for WordPress. Block AI crawlers via robots.txt OR license your content and get paid. Works with ChatGPT, Claude, Gemini, and 100+ AI systems.

== Description ==

**Block AI crawlers from scraping your site, or license your content and get paid. Your site, your choice.**

AI companies are crawling your WordPress site right now. ChatGPT, Claude, Gemini, Grok - they're all here, training on your content without asking. This plugin gives you two options: either block them with robots.txt, or add machine-readable licensing terms so compliant AI companies can pay you.

Here's what makes this different: we do BOTH blocking AND licensing. Most services only do one or the other. We built the infrastructure to do both properly.

= The Reality of AI Crawler Blocking =

**robots.txt is a voluntary standard** - While OpenAI and Anthropic claim to respect robots.txt, the reality is more complex. Recent investigations by TollBit and Cloudflare found evidence of major AI companies bypassing blocks, and Perplexity was caught using undisclosed IPs to scrape sites that blocked their official crawler. Industry data shows 12.9% of bots now ignore robots.txt (up from 3.3%), with 26 million bypasses in March 2025 alone.

**Why blocking still matters**: Even as a voluntary measure, robots.txt raises the legal and technical barrier. It establishes clear notice of your access terms, strengthening your legal position if you need to enforce your rights. Most legitimate AI companies do respect it - but you need backup enforcement.

= Coming Soon: HTTP 402 "Payment Required" Protocol =

We're implementing the HTTP 402 "Payment Required" status code protocol. When an AI bot hits your site without a valid license, we'll serve a 402 response with your licensing terms in machine-readable format.

**Transparency**: This is also a voluntary protocol. Cloudflare launched their "Pay Per Crawl" implementation on July 1, 2025, and now serves over 1 billion 402 responses daily - but it's currently in private beta limited to select major publications. Like robots.txt, HTTP 402 requires AI companies to cooperate. The difference is it's newer, more standardized, and creates clearer legal standing for enforcement.

= The Stick AND The Carrot =

This plugin already blocks AI crawlers via robots.txt (the stick). Soon we'll add x402 protocol enforcement (bigger stick). But you can also license your content and earn money from AI companies that want to do the right thing (the carrot).

Most creators don't realize: News Corp got $250M+ from OpenAI. Reddit got $60M/year. The EU AI Act now requires licensed training data. The licensing market exists - it's just been locked to big publishers. Until now.

= Why WordPress Creators Need This Now =

**The traffic apocalypse is real.** In 2025, 60% of Google searches end without any click to external sites - up from 44.2% just a year ago. When users do click, only 360 clicks per 1,000 searches go to the open web (374 in EU). The rest? 14.3% go to Google-owned properties like YouTube and Maps. For the first time in a decade, Google now keeps 90% of its ad revenue internally, with only 10% going to network publishers.

This isn't theoretical - it's happening right now. ChatGPT and Gemini answer questions using your content, but users never visit your site. Your ad revenue collapses. Your subscription model breaks when AI gives your insights away for free.

**AI content licensing is the survival strategy.** The big publishers already figured this out. News Corp got over $250M from OpenAI (5-year deal). Reddit: $60M/year. Nine major publishers have signed deals including Financial Times, Associated Press, Le Monde, and Axel Springer. Meanwhile, individual WordPress site owners - the backbone of the open web - get nothing while AI companies scrape freely.

**WordPress is where we make our stand.** Dave Winer, who invented blogging 30 years ago and co-created RSS, is now championing WordPress as the foundation for the independent web's renaissance. In his 2025 writings and WordCamp Canada keynote, he argues that WordPress represents the "read/write web" Tim Berners-Lee originally envisioned - where creators own their content and control their distribution. While social media captured one generation of creativity and tech giants bought up the rest, WordPress remains the last great bastion of independent publishing. It's where 43% of the web lives. It's where creators still own their content.

That changes today.

= EU AI Act Compliance =

The EU AI Act now mandates that AI companies must respect opt-out mechanisms and maintain licensing documentation for training data. This plugin provides:

- **Enforceable crawl terms** via robots.txt and upcoming x402 protocol
- **Opt-in training licenses** with clear pricing and attribution
- **Compliant usage tracking** that meets EU reporting requirements
- **Monetization infrastructure** so you get paid, not just compliance paperwork

For WordPress sites serving EU audiences, this isn't just about money - it's about legal compliance for the AI companies that want to use your content.

= The Technology Behind It =

We've built the first true content licensing infrastructure for AI:

**License Grammar v1.5** - The emerging standard for machine-readable licensing. Our meta tag format works with ChatGPT, Claude, Gemini, Grok, and 100+ AI systems. It's being adopted as the industry standard because it's simple, parseable, and extensible.

**Dual-axis licensing** - Control both the AI use case (training vs inference) and distribution level (private vs public). You can allow free private research while charging for commercial usage. Or block training entirely while allowing inference. Your content, your rules.

**Immutable ledger** - When AI companies use your content, it's logged with cryptographic verification. This creates legal standing if you need to enforce your terms. It's not just "hoping they comply" - it's building an audit trail.

**Layered protection** - robots.txt provides the first barrier (voluntary but creates legal notice). Machine-readable licenses give compliant AI companies clear terms. The upcoming HTTP 402 protocol adds standardized payment signaling. Each layer strengthens your legal position.

= How It Actually Works =

**1. Install the plugin** (literally 1 minute)
Search "Copyright.sh AI License" in your WordPress plugins, install, activate. Done.

**2. Pick your approach**
- Want to block all AI crawlers? Set policy to "Deny" and enable the robots.txt blocker.
- Want to get paid? Set "Allow", pick a price (we suggest $0.10 per 1K tokens), and connect your account.
- Want both? You can block most crawlers and whitelist specific ones you want to license to.

**3. It runs automatically**
The plugin adds machine-readable license declarations to every page:
- HTML meta tags that AI systems check before crawling
- /ai-license.txt file at your domain root
- robots.txt entries that block non-compliant crawlers (optional)
- Coming soon: x402 HTTP responses that enforce payment requirements

**4. Track what's happening** (if you're licensing)
Dashboard at dashboard.copyright.sh shows which AI companies are using your content and what you've earned. Set up PayPal, Venmo, or Stripe for payouts.

= What You Get =

**Blocking capabilities:**
- robots.txt generator that blocks 100+ known AI crawlers (voluntary standard, but creates legal notice)
- Preserves Google, Bing, and other search engines (won't hurt your search rankings)
- Optional - you can block all AI or just the ones that don't pay
- Coming soon: HTTP 402 protocol support for standardized payment signaling

**Licensing capabilities:**
- Set prices per 1,000 tokens (industry standard pricing)
- Different rates for private vs public usage
- Per-post pricing overrides for premium content
- Support for different AI use cases (training, inference, embedding, fine-tuning)
- Works with OpenAI, Anthropic, Google, xAI, Meta, Perplexity, and 100+ other AI systems

**Technical stuff:**
- Uses License Grammar v1.5 specification (the standard AI companies are adopting)
- Magic link authentication - no passwords, no API keys to copy-paste
- Clean code, minimal performance impact
- Compatible with all major caching and SEO plugins

**Works seamlessly with:**
- WP Rocket, W3 Total Cache, WP Super Cache, LiteSpeed Cache
- Yoast SEO, Rank Math, All in One SEO
- Elementor, Divi, Beaver Builder
- WooCommerce, Easy Digital Downloads
- Multisite installations

= Who Should Use This =

Honestly? Anyone with a WordPress site that AI companies might be scraping.

- **Bloggers**: Your old posts are training ChatGPT right now. Either block that or get paid for it.
- **News sites**: The big publishers are all licensing. You should too.
- **Niche experts**: Got specialized knowledge? That's worth more to AI companies.
- **Anyone who wants to block AI**: You don't have to license. You can just block all AI crawlers completely.

= Why This Matters =

The AI training data market is real. News Corp got $250M+. Reddit got $60M/year. The EU AI Act now mandates licensed training data. This isn't hypothetical - it's happening.

The difference is that until now, only massive publishers could negotiate these deals. This plugin gives individual site owners the same tools.

But here's the thing: you don't have to license your content. If you just want to block AI companies from using your stuff, this plugin does that too. It's your site, your content, your choice.

= What Makes This Different: Competitor Comparison =

**Cloudflare Pay Per Crawl**: Launched July 2025, enables website owners to charge AI crawlers per request. While technically sound, it's limited to Cloudflare's network infrastructure and uses a flat-rate pricing model that doesn't account for content value variations. Major limitation: Only works if you're already on Cloudflare's platform and restricted to high value customers.

**TollBit**: AI content licensing platform that requires heavy configuration and subdomain setup (tollbit.example.com) and routes AI traffic through their infrastructure. Good for enterprise publishers but creates technical overhead and dependency on their network for content delivery.

**Perplexity Comet Plus**: The $5/month subscription service that validates the whole business model of content licensing. Perplexity shares 80% of the revenue they charge Comet Plus customers with publishers based on human visits and AI interactions. Revenue-sharing model is innovative, but success depends entirely on user subscription adoption and limited to Perplexity's ecosystem.

**RSL Protocol (Really Simple Licensing)**: Open standard for publishers to set explicit licenses and fees for AI content use. Supported by Reddit, Yahoo, and other major platforms. Technically solid but again requires learning a custom XML schema and lacks customizability on a per page basis. RSL is still in early adoption phase (i.e. not available) and requires AI companies to implement the protocol.

**What we do differently**: We provide both content protection (robots.txt blocking) and monetization (licensing with payment) in a single WordPress plugin. No subdomain required, no network dependency, no platform lock-in. Works with any hosting provider you run Wordpress from. Install, configure (or don't), done. 

If/when RSL Protocol becomes widely adopted, we'll support it too. Until then, we give you the most accessible and comprehensive AI content licensing solution available today.

= AI Systems That Work With This =

The plugin uses the Copyrightish AI-License Grammar v1.5, which is becoming the standard format for AI licensing:

Served via MCP plugin, compatible with OpenAI (ChatGPT, GPT models), Anthropic (Claude), Google (Gemini), xAI (Grok), Meta (Llama), Perplexity, Microsoft (Copilot), DeepSeek, Alibaba (Qwen), and 100+ other AI systems.

As new AI companies launch, they're adopting the same standard. Your protection scales automatically.

== Installation ==

= Automatic Installation (Recommended) =

1. Log in to your WordPress dashboard
2. Navigate to Plugins → Add New
3. Search for "Copyright.sh AI License"
4. Click "Install Now" and then "Activate"
5. Go to Settings → AI License to configure

= Manual Installation =

1. Download the plugin ZIP file
2. Log in to your WordPress dashboard
3. Navigate to Plugins → Add New → Upload Plugin
4. Choose the ZIP file and click "Install Now"
5. Activate the plugin
6. Go to Settings → AI License to configure

= First Time Setup =

After you activate the plugin:

1. Go to Settings → AI License
2. Pick your approach:
   - **Block everything**: Set policy to "Deny" and enable the robots.txt blocker
   - **Get paid**: Set to "Allow", pick a price (try $0.10 per 1K tokens), and connect your account
3. If licensing: Click "Create account & connect" and check your email for the magic link
4. Click the magic link - you'll be logged into the dashboard and sent back to WordPress automatically
5. Save your settings

That's it. The plugin handles everything else automatically.

**Pro tip**: Enable the robots.txt blocker even if you're licensing. Block the crawlers that don't pay, allow the ones that do.

== Frequently Asked Questions ==

= How does this actually protect my content? =

**Honestly? It's a mix of technical barriers and legal standing.**

1. **robots.txt blocking** (available now): Generates rules that block 100+ known AI crawlers. This is a voluntary standard - while OpenAI and Anthropic claim to respect it, industry data shows 12.9% of bots ignore robots.txt (up from 3.3% a year ago). Perplexity was caught bypassing blocks entirely. BUT even as a voluntary measure, it raises the legal and technical barrier and establishes clear notice of your access terms.

2. **Machine-readable licensing** (available now): Adds `<meta name="ai-license">` tags and `/ai-license.txt` file using License Grammar v1.5. AI companies that want to operate legally can check your terms and pay your rates. If they don't, you have clear legal standing to enforce your rights - you explicitly stated your terms in a machine-readable format they can't claim to have missed.

3. **HTTP 402 protocol** (coming soon): When an AI bot hits your site, we'll serve a 402 "Payment Required" response with your licensing terms. Like robots.txt, this is voluntary - but it's newer, more standardized, and creates even clearer legal standing.

The key is we do BOTH technical blocking AND legal licensing. Most services only do one.

= Will this mess up my SEO? =

No - the plugin preserves Google, Bing, and other search engines. It only blocks AI training crawlers.

But let's be real: **traditional SEO is dying anyway.** In 2025, 60% of Google searches end without any click to external sites. When users do click, only 360 clicks per 1,000 searches go to the open web. Google now keeps 90% of its ad revenue internally (first time in a decade), with only 10% going to network publishers.

AI is replacing search - ChatGPT and Gemini answer questions using your content without sending traffic. This plugin helps you adapt: either block AI from using your content, or license it and get paid. Because traffic isn't coming back.

= How do I actually get paid? =

If you're licensing (not just blocking):

1. Click "Create account & connect" in plugin settings
2. Check your email for the magic link
3. Click the link - you're logged into dashboard.copyright.sh and sent back to WordPress
4. In the dashboard, add your payout method (PayPal, Venmo, Stripe)

Earnings accumulate under your domain even before you connect, so you won't lose revenue if you set up the plugin first and connect later.

= What's private vs public distribution? =

**Private**: AI uses your content to answer one person's question (like ChatGPT responding to a user)
**Public**: AI uses your content for commercial purposes or many users (like generating blog posts)

You can charge different rates for each. Most people charge more for public use.

= Can I charge different amounts for different posts? =

Yep. Set a global default in Settings, then override it on specific posts using the "AI License Override" meta box in the post editor. Good for premium content.

= Do AI companies actually respect this? =

**The truth: it's mixed, and improving.**

OpenAI and Anthropic officially respect robots.txt, but investigations by TollBit and Cloudflare have found evidence of bypassing on news sites. Perplexity was definitively caught using undisclosed IPs and spoofed user agents to bypass blocks. Meta's facebookexternalhit doesn't respect robots.txt at all (their position: it's not a "crawler").

BUT the trend is toward compliance because:

1. **Legal risk**: The EU AI Act now mandates opt-out respect and licensing documentation. Nine major publishers have signed licensing deals (News Corp $250M+, Reddit $60M/year). The legal precedent is clear.

2. **Market incentives**: AI companies that want premium content are now paying for it. They're realizing licensed data is legally safer and often higher quality than scraped data.

3. **Technical barriers**: While no blocking is perfect, robots.txt + HTTP 402 + machine-readable licenses create multiple layers that make unauthorized scraping legally riskier and technically harder.

Our approach: give you the tools to block, license, and build legal standing. Perfect enforcement doesn't exist yet, but we're building toward it.

= Can I just block AI completely without licensing? =

Yes. Set policy to "Deny" and enable the robots.txt blocker. You don't have to license anything - blocking is a completely valid choice.

= Will this slow down my site? =

No. The plugin adds one meta tag to your HTML head. Performance impact is basically zero. Works fine with all major caching plugins (WP Rocket, W3 Total Cache, etc).

= What if I already have a robots.txt file? =

The plugin's robots.txt feature is optional. If you manage robots.txt another way, just leave that feature disabled.

= Which AI systems does this work with? =

The plugin uses License Grammar v1.5, which is becoming the industry standard:

OpenAI (ChatGPT), Anthropic (Claude), Google (Gemini), xAI (Grok), Meta (Llama), Microsoft (Copilot), Perplexity, DeepSeek, Alibaba (Qwen), and 100+ other AI systems.

New AI companies are adopting the same standard, so your protection scales automatically.

= Is this actually worth setting up? =

Look, AI companies are scraping your site right now. You have three options:

1. Do nothing (they scrape for free)
2. Block them completely (valid choice)
3. License your content and get paid

This plugin handles options 2 and 3. Takes about 5 minutes to set up. Your call.

== Screenshots ==

1. Dashboard connection - Connect your WordPress site to Copyright.sh for usage tracking and payments
2. Robots.txt editor - Block AI crawlers while preserving search engine access
3. Global settings page - Configure your default AI licensing policy with pricing and payment details
4. Connected state overview - See your active connection status and domain configuration
5. How pricing works - Example showing the meta tag format AI companies read
6. Understanding distribution - Learn the difference between private and public AI usage
7. Understanding AI stages - See how different AI use cases (train, infer, embed, tune) work

== Changelog ==

= 1.6.2 =
* Preserves the full WordPress settings URL (with query string) when sending magic links
* WordPress plugin polls `/auth/wordpress-status` immediately after you return for faster "Connected" state
* Dashboard verified page keeps the return link intact so users land back on `options-general.php?page=csh-ai-license`
* Readme and metadata updates to satisfy WordPress.org plugin checks
* Fixed fatal `railingslashit()` typo that caused 500 errors during connection status polling

= 1.5.0 =
* Feature: One-click dashboard connection with magic link authentication
* Added AJAX registration/status polling, token storage, and scheduled refresh
* Disconnect/reset controls and improved onboarding copy

= 1.4.2 =
* Enhanced readme with comprehensive feature descriptions and FAQs
* Added detailed installation instructions and setup guide
* Improved marketing copy to highlight creator benefits
* Updated compatibility: Tested with WordPress 6.8.2

= 1.4.1 =
* Compatibility: Tested with WordPress 6.8.1
* Updated compatibility information for WordPress.org requirements

= 1.4.0 =
* Feature: Optional robots.txt generator with curated AI crawler controls
* Added toggle + editable template in settings, including warnings for existing server-managed robots.txt
* Updated documentation, readme and screenshots for robots.txt guidance

= 1.3.0 =
* Removed account creation features for WordPress.org compliance
* Maintained core AI licensing functionality
* Updated JavaScript and CSS enqueuing to use WordPress standards
* Simplified settings interface while preserving all licensing features

= 1.2.0 =
* Major: Updated to License Grammar v1.5 (distribution: private/public)
* Change: Renamed "visibility" parameter to "distribution" per spec
* Improvement: Updated all UI labels to use "distribution" terminology
* Compatibility: Aligned with Copyright.sh License Grammar Specification v1.5

= 1.1.0 =
* Major: Updated to License Grammar (visibility: private/public)
* Fix: Proper JavaScript and CSS enqueuing using WordPress standards
* Improvement: Better UI labels and descriptions for visibility settings
* Compatibility: Updated to work with latest Copyright.sh platform

= 1.0.1 =
* Tweak: version bump and minor compatibility fixes.

= 1.0.0 =
* First public stable release.

== Upgrade Notice ==

1.2.0 — Critical update to License Grammar v1.5 with distribution parameter. Required for compatibility with latest Copyright.sh platform.

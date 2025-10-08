=== Copyright.sh – AI License ===
Contributors:      copyrightsh
Tags:              ai, chatgpt, openai, anthropic, google, perplexity, ai-licensing, content-protection, monetization
Requires at least: 6.2
Tested up to:      6.8
Requires PHP:      7.4
Stable tag:        1.6.2
License:           GPLv3 or later
License URI:       https://www.gnu.org/licenses/gpl-3.0.html

Block AI crawlers OR get paid for your content. You choose. Works with OpenAI, Anthropic, Google, xAI, Meta, and 100+ AI systems. Dead simple setup.

== Description ==

**Block AI crawlers from scraping your site, or license your content and get paid. Your site, your choice.**

Look, AI companies are crawling your WordPress site right now. ChatGPT, Claude, Gemini, Grok - they're all here, training on your content without asking. This plugin gives you two options: either block them completely, or let them use your content if they pay you.

Here's what makes this different from every other solution: we do BOTH blocking AND licensing. Most services only do one or the other. We built the infrastructure to do both properly.

= Coming Soon: x402 Protocol Enforcement =

We're rolling out automatic crawler blocking via the x402 HTTP status code protocol. When an AI bot hits your site without paying, we'll serve them a 402 Payment Required response with your licensing terms. They either pay up or get nothing.

Only two systems can do this properly: Cloudflare at the network level, and us at the application level. Everyone else is just adding meta tags and hoping AI companies play nice. We're building enforcement that actually works.

= The Stick AND The Carrot =

This plugin already blocks AI crawlers via robots.txt (the stick). Soon we'll add x402 protocol enforcement (bigger stick). But you can also license your content and earn money from AI companies that want to do the right thing (the carrot).

Most creators don't realize: News Corp got $250M+ from OpenAI. Reddit got $60M/year. The EU AI Act now requires licensed training data. The licensing market exists - it's just been locked to big publishers. Until now.

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
- robots.txt generator that blocks 100+ known AI crawlers
- Preserves Google, Bing, and other search engines (this won't hurt your SEO)
- Optional - you can block all AI or just the ones that don't pay
- Coming soon: x402 protocol enforcement that blocks non-payers at the HTTP level

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
- Compatible with all major caching plugins

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

= What Makes This Different =

Most AI licensing services are just adding meta tags and hoping AI companies respect them. We're building actual enforcement:

1. **robots.txt blocking** (available now) - Blocks 100+ known AI crawlers
2. **x402 protocol enforcement** (coming soon) - HTTP-level blocking that forces payment
3. **Network-level protection** - Working with Cloudflare for enterprise customers

Only two systems can properly enforce AI crawler blocking: Cloudflare at the network edge, and us at the application level. Everyone else is just asking nicely.

= AI Systems That Work With This =

The plugin uses License Grammar v1.5, which is becoming the standard format for AI licensing:

OpenAI (ChatGPT, GPT models), Anthropic (Claude), Google (Gemini), xAI (Grok), Meta (Llama), Perplexity, Microsoft (Copilot), DeepSeek, Alibaba (Qwen), and 100+ other AI systems.

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

Two ways:

1. **Blocking** (available now): The plugin generates robots.txt rules that block 100+ known AI crawlers. Coming soon: x402 HTTP responses that enforce payment requirements at the protocol level.

2. **Licensing** (available now): Adds machine-readable license declarations (`<meta name="ai-license">` tags and `/ai-license.txt` file) that AI companies check before using your content. If they respect the license, they pay your rate. If they don't, you have legal standing.

The key is we do BOTH. Most services only do one.

= Will this mess up my SEO? =

No. The robots.txt blocker specifically preserves Google, Bing, and other search engines. It only blocks AI training crawlers. Your search rankings won't be affected.

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

Some do, some don't - that's why we're building enforcement layers:

1. **Right now**: Legal standing. If they use your content without respecting your license, you have grounds to sue.
2. **Coming soon**: x402 protocol enforcement that blocks non-payers at the HTTP level.
3. **Market pressure**: News Corp got $250M+, Reddit got $60M/year, EU AI Act mandates licensing. The trend is clear.

The blocking features work regardless of whether AI companies "respect" anything. Block them in robots.txt, they can't easily scrape you.

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

1. Global settings page - Configure your default AI licensing policy
2. Per-post override - Set custom licensing for individual content
3. AI-license meta tag in action - See exactly what AI companies see
4. Dashboard preview - Track your earnings at dashboard.copyright.sh
5. Robots.txt editor - Block AI crawlers while preserving SEO

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

# Copyright.sh – AI License WordPress Plugin

A WordPress plugin that enables websites to declare AI usage permissions and monetization terms through machine-readable licenses.

## Features

- 🤖 Generate `<meta name="ai-license">` tags for AI crawlers
- 📄 Automatic `/ai-license.txt` endpoint for domain-wide policies  
- ⚙️ Global settings with per-page/post overrides
- 💰 Monetization support with payment routing
- 🔒 Privacy controls (public vs private distribution)
- 🛡️ Optional robots.txt generator to block AI crawlers while preserving SEO
- 🔗 One-click dashboard connection (magic link account setup from WordPress)

## License Grammar v1.5

The plugin implements the Copyright.sh License Grammar Specification v1.5:

```html
<meta name="ai-license" content="allow;distribution:public;price:0.15;payto:cs-8f4a2b9c1d5e6f7a">
```

### Parameters:
- **action**: `allow` or `deny`
- **distribution**: `private` (individual use) or `public` (can be shared)
- **price**: Cost in USD per 1,000 tokens
- **payto**: Payment destination account

## Installation

1. Upload the `copyright-sh-ai-license` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure settings under Settings → AI License

## Requirements

- WordPress 6.2 or higher
- PHP 7.4 or higher

## Changelog

### 1.5.0
- Added in-dashboard account creation and connection flow (magic link powered)
- AJAX status polling, token storage, and automatic refresh scheduling
- Disconnect/reset controls and new onboarding copy

### 1.4.0
- Added optional robots.txt management with curated AI crawler template
- New settings toggle and editor with preview; warns when server-managed robots.txt is detected
- Updated documentation and screenshots for robots.txt guidance

### 1.2.0
- Updated to License Grammar v1.5 (distribution parameter)
- Replaced "visibility" with "distribution" terminology
- Improved WordPress compliance

### 1.1.0  
- License Grammar v1.4 support
- Fixed JavaScript/CSS enqueuing for WordPress standards
- Enhanced UI labels and descriptions

### 1.0.1
- Minor compatibility fixes

### 1.0.0
- Initial release

## Support

Visit [Copyright.sh](https://copyright.sh) for more information about AI content licensing.

## License

GPL-3.0-or-later
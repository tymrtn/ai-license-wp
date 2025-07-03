# Copyright.sh — AI Licensing for WordPress

**Version&nbsp;0.1.0** • GPL-2.0-or-later  
<https://copyright.sh>

Easily declare, customise and serve a *machine-readable* AI-usage licence for your WordPress site.

The plugin adds:

* A global **Settings → AI License** screen to configure your preferred policy (`allow` or `deny`) together with optional *payto*, *price* and *scope* parameters.
* A **per-post meta-box** to override the global policy when needed.
* Automatic output of the `<meta name="ai-license">` tag on the front-end.
* A dynamic `/ai-license.txt` endpoint that mirrors the same policy for crawler consumption.

---

## Table of Contents

1. [Installation](#installation)
2. [Usage](#usage)
3. [Screenshots](#screenshots)
4. [Filters & Actions](#filters--actions)
5. [Contributing](#contributing)
6. [Changelog](#changelog)

---

## Installation

1. Download the latest release ZIP from GitHub.
2. Inside your WordPress admin go to **Plugins → Add New → Upload Plugin** and upload the ZIP.
3. Click **Activate**.

Alternatively with Composer:

```bash
composer require copyrightsh/ai-licensing-wp
```

---

## Usage

1. Navigate to **Settings → AI License**.
2. Choose whether to *Allow* or *Deny* AI usage.
3. Optionally set:
   * **Pay To** – defaults to your domain (e.g. `example.com`). Payments accrue under that domain until you sign in to Copyright.sh and link a PayPal, Venmo, Stripe Link or USDC wallet (coming soon).
   * **Price** – suggested price in USD.
   * **Scope** – `snippet` (≤100 tokens) or `full` (whole article).
4. Save changes.

Per-post overrides are available in the post sidebar under **AI License Override**.

### Resulting Mark-up

```html
<meta name="ai-license" content="allow; payto:creator@paypal; price:0.0025; scope:snippet">
```

The generated `/ai-license.txt` will contain:

```text
# ai-license.txt – AI usage policy
User-agent: *
License: allow; payto:creator@paypal; price:0.0025; scope:snippet
```

---

## Screenshots

| | |
|---|---|
| **1. Global settings** | *(placeholder – add in `/assets/` before publishing)* |
| **2. Post override meta-box** | *(placeholder – add in `/assets/` before publishing)* |

> Screenshots are optional but recommended for the WordPress.org repository. Add them to an `assets/` folder (not committed in the build ZIP).

---

## Filters & Actions

The plugin is intentionally minimal. Future filters/hooks will be exposed once the specification and community best-practices stabilise.

---

## Contributing

Pull requests are welcome! Please follow the WordPress Coding Standards (`composer run lint`) and ensure that unit tests (`composer run test`) continue to pass.

1. `git clone https://github.com/copyrightsh/ai-licensing-wp.git`
2. `composer install`
3. `npm install` (if you plan to build JS/CSS)

---

## Changelog

### 0.1.0 – 2025-07-02

* Initial public release:
  * Global settings page and per-post overrides.
  * `<meta name="ai-license">` front-end injection.
  * Dynamic `/ai-license.txt` endpoint.

---

© 2025 Copyright.sh — Released under the GPL-2.0-or-later.

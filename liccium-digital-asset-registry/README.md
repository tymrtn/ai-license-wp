=== Liccium – Digital Asset Registry (Mock) ===
Contributors: copyrightsh
Tags: liccium, declaration, registry, automation, mock
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mock Liccium declaration and registry flows with opt-out metadata and WordPress automations.

== Description ==

Liccium – Digital Asset Registry (Mock) mirrors the Liccium Declaration API using a built-in dummy service so you can demo enhanced declarations, a dedicated opt-out registry, and automation triggers without external dependencies. The plugin stores data locally via a custom post type and exposes matching REST routes for quick prototyping.

Highlights:

- Create declarations enriched with publisher, opt-out, and payload metadata.
- Publish to a mock registry for improved discovery of opt-out signals.
- Automate declarations on media upload or post publish, plus simulate Bluesky blob events.
- Auto-backfill existing uploads and posts on activation (or via `wp liccium backfill`).
- Optional OpenRouter enrichment to add AI-generated summaries, keywords, and opt-out guidance.

== Installation ==

1. Upload the `liccium-digital-asset-registry` folder to `/wp-content/plugins/` or install via zip.
2. Activate the plugin through the “Plugins” menu in WordPress. The first admin page load will backfill declarations for existing uploads and posts.
3. Visit Liccium → Settings to configure publisher details, default opt-out status, automation toggles, and (optionally) OpenRouter credentials.
4. Open Liccium → Automations to review toggles and run the mock Bluesky trigger.

== Frequently Asked Questions ==

= Does the plugin call external Liccium services? =

No. All declarations, registry data, and REST responses are generated inside WordPress for demo purposes.

= Can I enrich declarations with AI-generated metadata? =

Yes. Provide an OpenRouter API key and model slug in Liccium → Settings, then check “Enrich declarations via OpenRouter.” Automations, activation backfill, and the CLI backfill will request summaries, keywords, and opt-out guidance. Leave the key blank to disable external calls.

= How do I trigger automations? =

Enable the relevant toggles under Liccium → Automations. Uploading media or publishing a post will then create mock declarations automatically. You can also simulate a Bluesky blob event from the same screen or via `POST /wp-json/liccium/v1/automations/bluesky/mock`.

= Is there a way to re-run the backfill later? =

Yes. From the command line run `wp liccium backfill --types=attachment,post --limit=100 --force-ai` to process assets on demand (flags optional). The command respects your settings and supports AI enrichment.

== Screenshots ==

1. Liccium dashboard showing declaration counts and quick actions.
2. Automations screen with toggles and Bluesky mock controls.
3. Registry view listing opted-out declarations and publish timestamps.

== Changelog ==

= 0.1.0 =
* Initial release with mock declarations API, registry publishing, admin UI, automation triggers, activation backfill, WP-CLI support, and optional OpenRouter enrichment.

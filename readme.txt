=== Signed Webhooks ===
Contributors: atiqah89
Tags: webhooks, integration, hmac, automation, api
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send HMAC-signed webhooks when posts are published, updated or unpublished, with automatic retries and a delivery log.

== Description ==

Signed Webhooks notifies another system (a CRM, a search index, a Slack bot, your own API) whenever content changes.

* HMAC-SHA256 signatures with timestamps, to protect against forgery and replays
* Secret rotation without downtime
* Background delivery with exponential backoff and Retry-After support
* Delivery log with one-click redelivery and a test event button
* HTTPS-only endpoints and SSRF protection

== Installation ==

1. Upload the plugin and activate it.
2. Go to Settings → Signed Webhooks, enter your endpoint and choose the events.
3. Click "Send test event" and check the delivery log.

== Frequently Asked Questions ==

= My receiver says the signature is invalid =

Verify the raw request body, not JSON you parsed and re-encoded. See docs/verifying-signatures.md on GitHub.

= Deliveries stay in "retrying" =

WP-Cron runs on page views. On low-traffic sites, trigger it with a real cron job (wp cron event run --due-now).

== Changelog ==

= 1.0.0 =
* First release.

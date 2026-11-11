# Signed Webhooks for WordPress

[![CI](https://github.com/atiqah89/signed-webhooks/actions/workflows/ci.yml/badge.svg)](https://github.com/atiqah89/signed-webhooks/actions/workflows/ci.yml)

A WordPress plugin that notifies another system when content changes: it sends a **signed** HTTP request when a post or page is published, updated or unpublished, **retries** when the receiver is down, and keeps a **delivery log** so site owners can see what happened without asking a developer.

## Features

- **HMAC-SHA256 signatures with a timestamp** (`t=…,v1=…`), so receivers can reject forged and replayed requests
- **Secret rotation without downtime**: during rotation, requests are signed with both secrets
- **Asynchronous delivery via WP-Cron**: publishing a post never waits on a slow receiver
- **Retries with exponential backoff** (1, 2, 4, 8, 16 minutes, capped at 6 hours), honouring `Retry-After`. 4xx responses aren't retried, because resending the same payload won't fix them.
- **Idempotency key** (`X-Webhook-Delivery`), stable across retries
- **Delivery log** with status, attempts and the last response, plus one-click **Redeliver** and **Send test event**
- **Secure by default**: HTTPS-only endpoints, `wp_safe_remote_post` (blocks private IPs to prevent SSRF), no redirect following, capability and nonce checks on every action, escaped output
- Cleans up after itself on uninstall

## Install

1. Download the repository as a zip, or clone it into `wp-content/plugins/signed-webhooks`.
2. Activate **Signed Webhooks**. A signing secret is generated on activation.
3. Go to **Settings → Signed Webhooks**, enter your endpoint, choose events, and click **Send test event**.

## Payload

```json
{
  "id": "5f0c2c1e-1111-4222-8333-444455556666",
  "type": "post.published",
  "created_at": "2026-11-11T05:46:40+00:00",
  "site": "https://example.com",
  "data": {
    "post": {
      "id": 42,
      "title": "Hello world",
      "url": "https://example.com/hello-world/",
      "status": "publish",
      "author": "Atiqah",
      "modified_gmt": "2026-11-11 05:46:38"
    }
  },
  "api_version": "2026-11-01"
}
```

To verify signatures on the receiving side (Node.js and PHP examples), and for a troubleshooting table that maps log entries to causes, see **[docs/verifying-signatures.md](docs/verifying-signatures.md)**.

## Extending

```php
// Send webhooks for a custom post type too.
add_filter( 'signed_webhooks_post_types', fn( $types ) => [ ...$types, 'product' ] );

// Alert someone when a delivery fails for good.
add_action( 'signed_webhooks_delivery_failed', function ( $delivery_id, $status ) {
	wp_mail( get_option( 'admin_email' ), 'Webhook failed', "Delivery #$delivery_id failed with status $status" );
}, 10, 2 );
```

## Architecture

```
signed-webhooks.php          bootstrap
includes/
  class-signer.php           sign / verify               (pure PHP, unit tested)
  class-retry-policy.php     retry rules and backoff     (pure PHP, unit tested)
  class-payload-builder.php  events and JSON envelope    (pure PHP, unit tested)
  class-settings.php         one autoload=no option, secret rotation
  class-dispatcher.php       transition_post_status → private CPT → WP-Cron → wp_safe_remote_post
  class-admin-page.php       settings, log, redeliver (admin-post + nonces)
uninstall.php                removes the option, scheduled events and delivery log
```

Business rules live in classes without WordPress dependencies, so they can be tested in milliseconds. WordPress-specific code is a thin layer around them.

## Development

```bash
composer install
composer test   # PHPUnit
composer lint   # WordPress Coding Standards
```

CI runs the tests on PHP 8.1 to 8.4, plus PHPCS.

## License

GPL-2.0-or-later

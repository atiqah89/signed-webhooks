# Verifying webhook signatures

Every request carries:

| Header | Example | Purpose |
|---|---|---|
| `X-Webhook-Signature` | `t=1762840000,v1=5257a8...` | HMAC-SHA256 of `{t}.{raw body}` |
| `X-Webhook-Event` | `post.published` | Event type |
| `X-Webhook-Delivery` | `5f0c2c1e-...` | Same on every retry of one event: use it to deduplicate |
| `X-Webhook-Attempt` | `3` | Attempt number, handy when debugging |

Checklist for receivers:

1. **Use the raw body.** Parsing and re-encoding JSON changes whitespace and key order, and the signature stops matching. This is the most common support issue with signed webhooks.
2. **Compare in constant time** (`hash_equals`, `crypto.timingSafeEqual`).
3. **Reject old timestamps** (5 minutes is typical) to block replays.
4. **Accept any `v1`** in the header. During secret rotation there are two.
5. **Return 2xx quickly**, then do the work in a queue. Anything else, or taking longer than 10 seconds, counts as a failure and is retried.
6. **Deduplicate on `X-Webhook-Delivery`.** If your 200 response is lost, the same event is sent again.

## Node.js (Express)

```js
import crypto from 'node:crypto';
import express from 'express';

const app = express();
const SECRET = process.env.WEBHOOK_SECRET;

app.post('/webhooks/wordpress', express.raw({ type: 'application/json' }), (req, res) => {
  const header = req.get('X-Webhook-Signature') ?? '';
  const parts = header.split(',').map((p) => p.split('='));
  const t = Number(parts.find(([k]) => k === 't')?.[1]);
  const signatures = parts.filter(([k]) => k === 'v1').map(([, v]) => v);

  if (!t || Math.abs(Date.now() / 1000 - t) > 300) return res.status(400).send('stale');

  const expected = crypto.createHmac('sha256', SECRET).update(`${t}.${req.body}`).digest('hex');
  const ok = signatures.some(
    (s) => s.length === expected.length && crypto.timingSafeEqual(Buffer.from(s), Buffer.from(expected)),
  );
  if (!ok) return res.status(401).send('bad signature');

  const event = JSON.parse(req.body);
  // queue.add(event) ... then:
  res.sendStatus(204);
});
```

## PHP

```php
$body   = file_get_contents( 'php://input' );
$header = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';

if ( ! \SignedWebhooks\Signer::verify( $header, $body, getenv( 'WEBHOOK_SECRET' ), time() ) ) {
	http_response_code( 401 );
	exit;
}
$event = json_decode( $body, true );
http_response_code( 204 );
```

`Signer` has no WordPress dependencies, so you can copy `includes/class-signer.php` into any PHP app.

## Troubleshooting

| Symptom in the delivery log | Likely cause |
|---|---|
| Status `0`, "cURL error 28" | Receiver too slow (more than 10 s). Respond first, process later. |
| Status `0`, "A valid URL was not provided" | The endpoint resolves to a private or loopback IP. `wp_safe_remote_post` blocks these to prevent SSRF. |
| `401` on every attempt | Wrong secret, or the receiver verifies re-encoded JSON instead of the raw body. 4xx responses are not retried. |
| `301`/`302` | The endpoint redirects (for example http to https, or a trailing slash). Redirects are not followed, so the signed body isn't forwarded. Use the final URL. |
| Stuck on `retrying` | WP-Cron only runs when the site gets traffic. On quiet sites, use a real cron job: `wp cron event run --due-now`. |

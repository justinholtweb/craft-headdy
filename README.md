# Headdy

**A token-authenticated headless storefront API for Craft Commerce.**

Craft Commerce has exposed products through GraphQL since version 2. It has never exposed a single
cart or order *mutation* — [craftcms/commerce discussion #2350][2350] has been open for years, and
the standing advice is to give up on GraphQL for the cart and POST form-encoded requests to
`actions/commerce/cart/update-cart` with a PHP session cookie and a CSRF token.

That works for a Twig site posting to itself. For a Next, Nuxt or Astro front end on another origin
it means `credentials: 'include'`, a `SameSite` policy that survives your CDN, a round trip to fetch
a CSRF token, responses shaped like Craft's internal element attributes, and a payment step that is
simply unreachable because Craft hashes the return URL into a Twig form your JavaScript cannot
produce.

WooCommerce has the Store API and CoCart. Craft has had nothing. Headdy is that layer.

```bash
# One request: create a cart with an item in it.
curl -X POST https://example.com/api/storefront/v1/carts \
  -H 'Content-Type: application/json' \
  -H 'X-Headdy-Key: hd_pk_…' \
  -d '{"items":[{"purchasableId":123,"qty":2}]}'
```

```json
{
  "success": true,
  "cart": {
    "token": "hdc_…",
    "totalQty": 2,
    "lineItems": [{ "id": 456, "sku": "TSHIRT-L", "qty": 2, "total": { "amount": "49.98", "minorUnits": 4998, "currency": "USD", "formatted": "$49.98" } }],
    "totals": { "total": { "amount": "49.98", "minorUnits": 4998, "currency": "USD", "formatted": "$49.98" } }
  }
}
```

No cookie. No CSRF token. Hold `cart.token`, send it back as `X-Headdy-Cart`, and you have a cart.

## What you get

- **A REST storefront API** — carts, line items, addresses, coupons, shipping methods, checkout,
  payment, catalog, customer accounts. Versioned at `/v1`, with a documented JSON contract and
  stable machine-readable error codes.
- **GraphQL cart mutations** — `headdyCartCreate`, `headdyCartAddItem`, `headdyCartApplyCoupon` and
  the rest, registered on Craft's own GraphQL endpoint. *(Pro)*
- **Payment that actually works headlessly** — including off-site gateways, whose redirect comes
  back as JSON for your front end to act on rather than as a 302 it cannot follow.
- **Bearer tokens instead of sessions** — issued once, stored only as a SHA-256 hash, revocable,
  and rotated on checkout.
- **Money you can trust** — every amount is `{amount, minorUnits, currency, formatted}`, never a
  bare float.
- **A configuration check** that finds the misconfiguration before your front-end team does.

## The one thing worth knowing about the design

**`services\Carts::update()` is the only place a cart changes.** Every REST endpoint, every GraphQL
mutation and every console command builds a parameter array and hands it to that method. The
granular verbs (`POST /carts/current/items`, `PATCH …/items/{id}`) exist because they read well —
each is a few lines that assembles params and calls `update()`.

So REST and GraphQL cannot drift apart, and there is exactly one place where stock checks, option
signatures, recalculation order and validation happen.

Mutations are also applied in a fixed order regardless of the order you wrote them: clear → items →
addresses → email → coupon → shipping → gateway → fields. Setting a shipping method before an
address exists would pick from an empty option list; applying a coupon before the items are in would
not meet the discount's minimum. One big patch and six small ones give the same answer.

## Install

```bash
composer require justinholtweb/craft-headdy
php craft plugin/install headdy
```

Then, in **Settings → Plugins → Headdy**, or from the command line:

```bash
php craft headdy/keys/create --name="Next.js storefront"
```

That prints a public key and a secret. **The secret is shown once** — only its hash is stored.

Point your front end at `https://your-site.com/api/storefront/v1` and send the public key in
`X-Headdy-Key`.

Check the setup at any time:

```bash
php craft headdy/maintenance/check   # exits non-zero on a problem, so it can gate a deploy
php craft headdy/maintenance/routes  # the whole route table
```

## Authentication, and why there is no CSRF token

Three modes:

| Mode | Header | For |
| --- | --- | --- |
| **Public key** *(default)* | `X-Headdy-Key` | Browser JavaScript. Identifies and scopes a caller; does not authenticate one. |
| **Public key + secret** | `+ X-Headdy-Secret` | Server-side callers. A secret in a bundled JS file is not a secret. |
| **None** | — | A private network, and nowhere else. |

A CSRF token defends a credential the browser attaches on its own. Headdy has no cookie: the cart
token is only ever sent deliberately, which is the same defence a CSRF token provides. Requiring one
would only add a round trip — exactly the friction that makes headless Commerce painful today.

Signing a customer in **never logs them into Craft**. `Craft::$app->getUser()` stays anonymous for
every API request. A customer token identifies a shopper to Headdy's own endpoints and nothing else;
it cannot be replayed against the control panel, and control panel accounts are refused outright.

## Payment, and the return-URL problem

Craft's `PaymentsController` reads its return and cancel URLs with `getValidatedBodyParam()`, which
only accepts a value Craft itself hashed into a Twig form. There is no endpoint that will mint that
hash, so a JSON client cannot pay.

Headdy validates those URLs against an explicit allow-list instead. The hash exists to stop an
attacker redirecting a shopper somewhere of their choosing after payment; an allow-list stops the
same thing, and unlike the hash it can be satisfied from JavaScript.

**The list is empty by default**, so off-site gateways cannot be driven from the API until you say
where returns may land. Relative paths are always accepted.

For an off-site gateway the response carries a redirect object rather than a 302:

```json
{ "redirect": { "url": "https://gateway.example/pay/…", "method": "POST", "data": { "sig": "…" } } }
```

Honour `method`: `GET` means navigate, `POST` means submit `data` as a form. Gateways that sign a
POST body reject a customer who arrives by navigation.

## CORS

An empty allowed-origins list reflects whatever origin asks. That is deliberate: CORS is not what
protects this API — the bearer token is, and no cookie is ever sent — so a locked-down default would
mean every new install starts broken for no security gain. Naming your front ends is still one less
way for a leaked key to be used, and the Overview screen says so.

`Access-Control-Allow-Credentials` is off by default and cannot be combined with a `*` origin;
browsers reject that pair, so Headdy refuses to save it.

## Editions

| | Lite | Pro |
| --- | --- | --- |
| Carts, line items, addresses, coupons, shipping | ✓ | ✓ |
| Checkout state and payment | ✓ | ✓ |
| Catalog endpoints | ✓ | ✓ |
| Multi-store, multi-site | ✓ | ✓ |
| API keys, scopes, rate limiting | ✓ | ✓ |
| Customer accounts, order history, address book | | ✓ |
| GraphQL cart mutations | | ✓ |
| Outbound webhooks | | ✓ |
| Request log | | ✓ |

**Lite $99 · Pro $199.**

## Documentation

The full API contract — every endpoint, every error code, the GraphQL schema, webhook signing — is
in [`docs/api.md`](docs/api.md).

## Console commands

```
headdy/keys                       list API keys
headdy/keys/create                create one and print both halves
headdy/keys/rotate <publicKey>    rotate a secret
headdy/keys/delete <publicKey>    delete a key

headdy/maintenance                run every housekeeping task (put this on a schedule)
headdy/maintenance/purge-tokens   delete expired cart and customer tokens
headdy/maintenance/prune-log      delete log rows past the retention window
headdy/maintenance/check          the configuration check; non-zero exit on a problem
headdy/maintenance/routes         print the route table
```

## Requirements

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+.

Tested against Craft 5.10 and Commerce 5.7.

## Testing

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-headdy/tests/integration/checks.php   # 133 checks
```

Static analysis runs in the shared phpstan runner:
`vendor/bin/phpstan analyse --memory-limit=1G && vendor/bin/ecs check` (level 4, clean).

The suite includes live HTTP round-trips against the real endpoint — key rejection, CORS preflight,
the whole cart lifecycle and a real gateway payment — so a green run means the wire protocol works,
not just the units.

## Licence

The Craft License. See [LICENSE.md](LICENSE.md).

[2350]: https://github.com/craftcms/commerce/discussions/2350

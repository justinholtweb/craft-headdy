---
title: Configuration
slug: configuration
order: 20
summary: Settings, API keys and scopes, CORS, payment redirects, customer accounts, GraphQL schemas, webhooks and permissions.
---

## Settings

Everything lives under **Settings → Plugins → Headdy**. Every setting can also go in
`config/headdy.php`, which overrides the settings screen. Use that file for anything that differs
between environments:

```php
<?php

return [
    'basePath' => '$HEADDY_BASE_PATH',
    'allowedOrigins' => ['https://shop.example.com'],
    'allowedRedirectOrigins' => ['https://shop.example.com'],
    'rateLimit' => 120,
];
```

| Setting | Default | What it does |
| --- | --- | --- |
| `enabled` | `true` | Switch the API off. Every endpoint returns `503 api_disabled`. |
| `basePath` | `api/storefront` | Where the API is mounted. Accepts an environment variable. |
| `authMode` | `publicKey` | `publicKey`, `secret` (public key plus secret) or `open` (no key). |
| `allowedOrigins` | `[]` | Browser origins allowed by CORS. Empty means any origin. |
| `allowCredentials` | `false` | Send `Access-Control-Allow-Credentials`. Can't be combined with `*`. |
| `allowedRedirectOrigins` | `[]` | Where a payment may return to. Empty disables off-site gateways. |
| `cartTokenDuration` | 30 days | Cart token lifetime, in seconds. |
| `slidingCartTokens` | `true` | Each use pushes a cart token's expiry back out to the full duration. |
| `customerTokenDuration` | 1 hour | Customer access token lifetime, in seconds. *(Pro)* |
| `customerRefreshDuration` | 30 days | Customer refresh token lifetime, in seconds. *(Pro)* |
| `allowCustomerLogin` | `true` | Whether shoppers may sign in. *(Pro)* |
| `allowCustomerRegistration` | `false` | Whether shoppers may create accounts. *(Pro)* |
| `registrationFields` | `[]` | Custom field handles registration may set. *(Pro)* |
| `rateLimit` | `0` | Requests per minute per key. `0` means no limit. |
| `catalogEnabled` | `true` | Serve the product, variant and product type endpoints. |
| `graphqlEnabled` | `true` | Register the GraphQL cart mutations. *(Pro)* |
| `defaultPageSize` / `maxPageSize` | `24` / `100` | Paging for collection endpoints. |
| `verboseErrors` | `true` | Include field-level messages in `error.errors`. |
| `logRequests` / `logBodies` | `true` / `false` | What the request log records. *(Pro)* |
| `logRetentionDays` | `30` | Days of log to keep. `0` keeps everything. *(Pro)* |

## API keys and scopes

Each key has a public half and a secret half. Only a hash of the secret is stored, so a lost secret
can't be recovered. Rotate it instead. Each key also has its own scopes, an optional origin list
(which can only narrow the plugin-wide list, never widen it) and an optional rate limit of its own.

| Scope | Grants |
| --- | --- |
| `catalog:read` | Products, variants, product types |
| `cart:read` | Reading a cart and the shipping methods available to it |
| `cart:write` | Creating and changing carts |
| `checkout` | Checkout state and zero-balance completion |
| `payment` | Paying for an order |
| `customer:read` | Profile, orders, addresses, saved payment sources *(Pro)* |
| `customer:write` | Sign-in, registration, address book changes *(Pro)* |

A request outside a key's scopes gets `403 forbidden`. Give each front end its own key, so you can
revoke one without breaking the others.

```bash
php craft headdy/keys                        # list
php craft headdy/keys/create --name="Kiosk" --scopes=catalog:read,cart:read,cart:write
php craft headdy/keys/rotate hd_pk_…         # new secret, same public key
php craft headdy/keys/delete hd_pk_…
```

## CORS

If you leave **Allowed origins** empty, Headdy echoes back whatever origin asks. That's deliberate.
The bearer token is what protects the API, and no cookie is ever sent, so a locked-down default would
just mean every new install starts out broken. Listing your front ends is still worthwhile, because it
limits where a leaked public key can be used from, and the Overview screen will remind you.

Origins are compared as scheme + host + port. `https://shop.example.com` and
`https://shop.example.com/` are the same entry.

## Payment redirects

Craft's own checkout signs the return and cancel URLs inside a Twig form. A JavaScript client can't
produce that signature, so Headdy checks those URLs against **Allowed redirect origins** instead.

The list starts **empty**, so off-site gateways (PayPal, Mollie, hosted checkout pages) can't be used
through the API until you add the origins customers should come back to. Gateways that charge in
place, like Stripe with a payment method token, don't need it. Relative paths are always accepted.

## Customer accounts *(Pro)*

Signing in issues an access token (short-lived) and a refresh token (long-lived, rotated on each
use, and a used one can't be replayed). Neither token logs the shopper into Craft, and **control
panel accounts are refused at sign-in**, so a storefront credential can never become a control
panel session.

Registration is off by default. When you switch it on:

- It follows Craft's **Verify email addresses** user setting. With verification on, a new account
  stays pending until the shopper clicks Craft's activation email, and no tokens are issued until
  then.
- Registration can only fill in the custom fields you list under **Fields registration may set**.
  Anything else in the request is ignored.

Saving a customer's address book, viewing their orders and using a saved card all need a customer
token, not just a cart token. Otherwise anyone holding a cart token could charge someone else's card.

Changing a customer's password signs out every session issued before the change, whether the change
came from the control panel, a reset email or your front end. To sign someone out without changing
their password:

```bash
php craft headdy/maintenance/revoke-customer ada@example.com
```

## Rate limits behind a proxy

Sign-in, registration, token refresh and order lookup are always limited per client address. Craft
reads that address from `X-Forwarded-For` only when the request comes from a trusted proxy. Set
Craft's `trustedHosts` to your load balancer or CDN, so a client can't send its own header and get a
new address on every request.

## GraphQL *(Pro)*

The cart mutations are added to Craft's own GraphQL endpoint, but only for schemas you grant them to.
Under **Settings → GraphQL → Schemas**, edit the schema your front end uses and tick:

- **Headdy storefront → Read carts and checkout state by cart token** for `headdyCart` and
  `headdyCheckout`
- **Headdy storefront → Create, change and complete carts by cart token** for the mutations

A schema without them, including the public schema, has no `headdyCart*` fields at all.

## Webhooks *(Pro)*

Add endpoints under **Headdy → Webhooks**. Each endpoint picks its topics and gets a signing secret.
Deliveries go through Craft's queue, so a slow receiver never holds a checkout open. Every attempt
is listed in the endpoint's delivery history. A failed delivery fails its queue job, so it shows up
in **Utilities → Queue Manager** and can be retried from there.

Topics: `cart.created`, `cart.updated`, `cart.completed`, `order.paid`, `order.statusChanged`,
`payment.failed`. See the [API reference](api) for how to verify a signature.

Outside dev mode, an endpoint has to resolve to a public address. Private, loopback and cloud
metadata addresses are refused, so whoever can edit webhooks can't use them to reach internal
services. In dev mode a receiver on `localhost` works as you'd expect.

## Permissions

| Permission | Allows |
| --- | --- |
| **Manage API keys** | Creating, rotating and deleting keys |
| **Manage webhooks** | Adding and editing webhook endpoints *(Pro)* |
| **View the request log** | Reading the request log *(Pro)* |

The Overview screen is visible to anyone with access to the plugin. Only admins can change settings,
and only where `allowAdminChanges` is on.

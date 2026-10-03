---
title: Installation
slug: installation
order: 10
summary: Requirements, install, your first API key, your first cart, and what Lite and Pro each cover.
---

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later

Tested against Craft 5.10 and Commerce 5.7.

## Install

From the Plugin Store, search for **Headdy** and choose the edition you want. Or with Composer:

```bash
composer require justinholtweb/craft-headdy
php craft plugin/install headdy
```

As soon as it's installed, the API is mounted at `/api/storefront/v1`. To mount it somewhere else,
change **Base path** under **Settings → Plugins → Headdy**.

## Create an API key

Every request needs an API key unless you've chosen open authentication, which only makes sense on a
private network. Create a key under **Headdy → API keys**, or from the command line:

```bash
php craft headdy/keys/create --name="Next.js storefront"
```

```
Public key: hd_pk_…
Secret:     hd_sk_…   (shown once — only its hash is stored)
```

The **public key** is safe to ship in browser JavaScript. It identifies the caller and limits what it
may do; it does not authenticate anyone. You only need the **secret** for server-side callers, and
only if you switch **Authentication** to *Public key + secret*.

A new key gets the storefront scopes (`catalog:read`, `cart:read`, `cart:write`, `checkout`,
`payment`) and no customer scopes. Add `customer:read` and `customer:write` if the front end signs
shoppers in.

## Your first cart

```bash
API=https://your-site.test/api/storefront/v1
KEY=hd_pk_…

curl -X POST "$API/carts" \
  -H 'Content-Type: application/json' \
  -H "X-Headdy-Key: $KEY" \
  -d '{"items":[{"purchasableId":123,"qty":1}]}'
```

The response carries `cart.token`. Send it back as `X-Headdy-Cart` on every later request:

```bash
curl "$API/carts/current" -H "X-Headdy-Key: $KEY" -H "X-Headdy-Cart: hdc_…"
```

That's the whole authentication story for a guest shopper: no session cookie, no CSRF token, no
`credentials: 'include'`.

## Check the setup

**Headdy → Overview** lists anything that will stop a front end working: a missing base path, no
usable key, no customer-enabled gateway, an empty redirect allow-list, and so on. The same check
runs from the command line and exits non-zero on an error, so it can gate a deploy:

```bash
php craft headdy/maintenance/check
```

## Schedule the housekeeping

Expired tokens and old log rows are cleared by one command. Run it daily from cron:

```bash
php craft headdy/maintenance
```

## Editions

| | Lite | Pro |
| --- | --- | --- |
| Carts, line items, addresses, coupons, shipping | ✓ | ✓ |
| Checkout state and payment, including off-site gateways | ✓ | ✓ |
| Catalog endpoints | ✓ | ✓ |
| Multi-store, multi-site | ✓ | ✓ |
| API keys, scopes, rate limiting | ✓ | ✓ |
| Customer accounts, order history, address book, saved cards | | ✓ |
| GraphQL cart mutations | | ✓ |
| Outbound webhooks | | ✓ |
| Request log | | ✓ |

**Lite $99 · Pro $199.** Moving from Lite to Pro keeps every key, token and setting. Pro endpoints on
a Lite install answer `403` with the code `pro_required`.

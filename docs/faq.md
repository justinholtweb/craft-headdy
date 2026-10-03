---
title: FAQ
slug: faq
order: 60
summary: The questions worth answering before putting a headless front end on Craft Commerce.
---

## Doesn't Commerce already have a GraphQL API?

For reading products, yes. It has never had cart or order *mutations*. That's
[craftcms/commerce discussion #2350](https://github.com/craftcms/commerce/discussions/2350), open
for years. Without Headdy, a headless cart means form-encoded POSTs to
`actions/commerce/cart/update-cart` with a session cookie and a CSRF token.

## Why not just use Commerce's form actions from JavaScript?

From another origin, they need `credentials: 'include'`, a `SameSite` cookie policy that survives
your CDN, an extra round trip for a CSRF token, and response shapes taken from Craft's internal
element attributes. Payment is the hard stop: Commerce only accepts a return URL that Craft itself
hashed into a Twig form, so a JavaScript client can't complete an off-site payment at all.

## Is it safe without a CSRF token?

Yes. CSRF works because the browser attaches a cookie on its own. Headdy uses no cookie. The cart
token travels in a header your code sets on purpose, which is the same protection a CSRF token gives.

## Is the public key a secret?

No, and that's fine. It identifies which front end is calling and limits what it can do (scopes,
origins, rate limit). It doesn't authenticate a shopper. What a shopper holds is a cart token or a
customer token, and those are stored only as SHA-256 hashes.

## Can a customer token get someone into the control panel?

No. Signing in through Headdy never logs the user into Craft, and control panel accounts are refused
at sign-in.

## Which payment gateways work?

Any Commerce gateway. Gateways that charge in place, like Stripe, work out of the box. Off-site
gateways that redirect the shopper, like PayPal or Mollie, work once you add your storefront's origin
to **Allowed redirect origins**. The redirect comes back as JSON with a method and form data, so your
front end can follow it correctly.

## Does it work with multiple stores and sites?

Yes, on both editions. Send `X-Headdy-Store` or `X-Headdy-Site` to choose one. `GET /stores` lists
them.

## Next.js, Nuxt, Astro, SvelteKit, a mobile app?

All of them. It's JSON over HTTPS with headers. There's no SDK to install and nothing
framework-specific.

## Will REST and GraphQL ever disagree?

No. Every REST endpoint and every GraphQL mutation hands its changes to the same service method, and
every response is built by the same serializer.

## Will the API change under me?

Not inside `v1`. Response shapes and error codes are a contract. A breaking change means a `/v2`
alongside it, not an edit to `v1`. Branch on `error.code`, never on `error.message`, because
messages are translated and may be reworded.

## Does it store anything in project config?

Only the plugin settings. API keys, tokens and webhook endpoints are secrets or
environment-specific, so they live in the database. A production key never ends up in a developer's
checkout.

## Lite or Pro?

Lite covers a complete guest checkout: carts, shipping, coupons, checkout and payment. Pro adds
customer accounts, GraphQL mutations, webhooks and the request log. You can upgrade later without
losing keys or settings.

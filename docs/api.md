---
title: API reference
slug: api
order: 40
summary: The v1 contract — every endpoint, the response envelope, money, error codes, GraphQL and webhook signing.
---

# Headdy Storefront API — v1

Everything below is mounted under `<base path>/v1`, which defaults to:

```
https://example.com/api/storefront/v1
```

## The shape of every response

Success:

```json
{ "success": true, "cart": { ... } }
```

Failure:

```json
{
  "success": false,
  "error": {
    "code": "purchasable_unavailable",
    "message": "“Blue T-shirt, Large” is not available.",
    "errors": { "qty": ["Not enough stock."] }
  },
  "cart": { ... }
}
```

**Branch on `error.code`, never on `error.message`.** Codes are contract and will not change inside
`v1`. Messages are translated and may be reworded. `error.errors` only appears when there are
field-level validation messages and *Verbose errors* is on.

A failed cart mutation still returns the cart as it stands, so a client can re-render without a
second request.

## Money

Every amount is an object, never a bare number:

```json
{ "amount": "24.99", "minorUnits": 2499, "currency": "USD", "formatted": "$24.99" }
```

- `amount` — a decimal **string**. JSON numbers are IEEE 754 doubles and `24.99` is not one of them.
- `minorUnits` — the integer a payment processor wants.
- `formatted` — for display. Localised. Never parse it.

## Authentication

| Header | What it is |
| --- | --- |
| `X-Headdy-Key` | The API key's public half. Safe in browser JavaScript. |
| `X-Headdy-Secret` | The secret half. Server-side callers only, and only in `secret` auth mode. |
| `X-Headdy-Cart` | A cart token. Also accepted as `Authorization: Bearer hdc_…`. |
| `X-Headdy-Customer` | A customer access token. Also accepted as `Authorization: Bearer hda_…`. |
| `X-Headdy-Store` | A store ID, for a multi-store build. |
| `X-Headdy-Site` | A site ID or handle. |

There is **no CSRF token and no cookie**. A CSRF token defends a credential the browser attaches on
its own; a bearer token is only ever sent deliberately, which is the same defence.

## Carts

### `POST /carts`

Creates a cart. Accepts the same body as an update, so the common "add to cart with no cart yet"
path is one request rather than two.

```bash
curl -X POST "$API/carts" \
  -H 'Content-Type: application/json' \
  -H "X-Headdy-Key: $KEY" \
  -d '{"items":[{"purchasableId":123,"qty":2,"options":{"engraving":"Ada"}}]}'
```

`201`. The response's `cart.token` is the credential for every later call — hold it in memory (and
in a cookie or `localStorage` if the basket should survive a reload).

### `GET /carts/current`

### `PATCH /carts/current`

Applies any combination of changes in one request. Every key is optional. **A key present with a
`null` value clears that thing; an absent key leaves it alone** — which is exactly why the API takes
JSON and not a form body.

```json
{
  "addItems":    [{ "purchasableId": 123, "qty": 1, "options": {}, "note": "" }],
  "updateItems": { "456": { "qty": 3 } },
  "removeItems": [789],
  "clearLineItems": false,
  "shippingAddress": { "addressLine1": "1 High St", "locality": "Charlotte", "administrativeArea": "NC", "postalCode": "28202", "countryCode": "US" },
  "billingSameAsShipping": true,
  "email": "ada@example.com",
  "couponCode": "SPRING10",
  "shippingMethodHandle": "standard",
  "gatewayId": 1,
  "message": "Leave it with the neighbour",
  "fields": { "giftWrap": true }
}
```

Changes are applied in a fixed order regardless of the order you wrote them: clear → items →
addresses → email → coupon → shipping → gateway → fields. So one big patch and six small ones give
the same result.

### Line item verbs

| Method | Path |
| --- | --- |
| `POST` | `/carts/current/items` |
| `PATCH` | `/carts/current/items/{lineItemId}` |
| `DELETE` | `/carts/current/items/{lineItemId}` |
| `DELETE` | `/carts/current/items` (empties the cart, keeps the token) |

`{lineItemId}` accepts a line item's `id` or its `uid`.

### Other cart verbs

| Method | Path | Body |
| --- | --- | --- |
| `PUT` | `/carts/current/addresses` | `shippingAddress`, `billingAddress`, `billingSameAsShipping`, `shippingSameAsBilling` |
| `PUT` | `/carts/current/email` | `email` |
| `PUT` | `/carts/current/coupon` | `couponCode` (`null` removes it) |
| `PUT` | `/carts/current/shipping-method` | `shippingMethodHandle` |
| `GET` | `/carts/current/shipping-methods` | — |
| `POST` | `/carts/current/attach` | claims a guest cart for the signed-in customer |
| `DELETE` | `/carts/current` | throws the cart away |

Address fields are the CLDR names Craft itself stores — `addressLine1`, `locality`,
`administrativeArea`, `postalCode`, `countryCode` and the rest — so an address you read can be
written straight back.

## Checkout

### `GET /checkout`

The whole picture in one request, so a checkout UI never has to discover the store's rules by
trial and error:

```json
{
  "checkout": {
    "ready": false,
    "missing": ["shippingAddress", "shippingMethod"],
    "requiresPayment": true,
    "amountDue": { "amount": "49.98", "minorUnits": 4998, "currency": "USD", "formatted": "$49.98" },
    "availableShippingMethods": [ ... ],
    "availableGateways": [ { "id": 1, "handle": "stripe", "paymentFormParamName": "paymentForm[stripe]", ... } ],
    "store": { "requiresShippingAddress": true, "allowsPartialPayment": false, ... }
  }
}
```

`missing` values: `items`, `email`, `shippingAddress`, `billingAddress`, `shippingMethod`,
`paymentMethod`.

`paymentFormParamName` matters: Commerce derives it from the gateway handle and a client has no
other way to know it, so it ships in the payload.

### `POST /checkout/pay`

```json
{
  "gatewayId": 1,
  "paymentForm": { "stripeToken": "tok_visa" },
  "returnUrl": "https://shop.example.com/thanks",
  "cancelUrl": "https://shop.example.com/cart"
}
```

Craft's own checkout hashes return URLs into a Twig form, which a JSON client cannot reproduce.
Headdy validates them against **Allowed redirect origins** in the plugin settings instead. That list
is empty by default, so off-site gateways cannot be driven from the API until you say where returns
may land. Relative paths are always accepted. `*` allows any `http` or `https` origin; a custom app scheme has to be
listed by name.

On success:

```json
{
  "success": true,
  "order": { "isCompleted": true, "isPaid": true, ... },
  "transaction": { "hash": "…", "reference": "…", "status": "success", ... },
  "amountPaid": { ... },
  "outstandingBalance": { ... },
  "redirect": null
}
```

For an off-site gateway, `redirect` is populated instead of null:

```json
{ "redirect": { "url": "https://gateway.example/pay/…", "method": "POST", "data": { "sig": "…" } } }
```

**Honour `method`.** `GET` means navigate. `POST` means submit `data` as a form — gateways that sign
a POST body will reject a customer who arrives by navigation.

When the customer comes back, hand the gateway's `commerceTransactionHash` to:

### `POST /checkout/complete-payment`

Takes no cart token: by then the cart is an order and its token has been revoked.

### `POST /checkout/complete`

Completes an order that owes nothing — a fully discounted cart, or a store configured to allow
checkout without payment. Anything with a balance must go through `/checkout/pay`.

## Catalog

| Method | Path |
| --- | --- |
| `GET` | `/products?search=&type=&ids=&slug=&orderBy=&page=&pageSize=&availableOnly=` |
| `GET` | `/products/{idOrSlug}` |
| `GET` | `/variants/{idOrSku}` |
| `GET` | `/product-types` |

`orderBy` accepts `title`, `-title`, `postDate`, `-postDate`, `id`, `-id`. Anything else falls back
to `-postDate` — the value reaches SQL, so it is a fixed list rather than a passthrough.

## Customers — Pro

| Method | Path |
| --- | --- |
| `POST` | `/customers/sessions` — sign in, returns `token` + `refreshToken` |
| `POST` | `/customers/sessions/refresh` — rotate the pair |
| `DELETE` | `/customers/sessions` — sign out |
| `POST` | `/customers` — register (off by default) |
| `GET` | `/customers/me` |
| `GET` | `/customers/me/orders`, `/customers/me/orders/{number}` |
| `GET`/`POST` | `/customers/me/addresses` |
| `PATCH`/`DELETE` | `/customers/me/addresses/{id}` |
| `GET` | `/customers/me/payment-sources` |
| `GET` | `/orders/lookup?number=&email=` — a guest checking a purchase |

Signing in **never logs the user into Craft**. A customer token identifies a shopper to Headdy's own
endpoints and nothing else; it cannot be replayed against the control panel. Control panel accounts
are refused outright.

If you send a cart token alongside a sign-in, the cart is attached to the customer and returned in
the response — so a shopper who logs in mid-checkout keeps their basket.

**Sign-in uses Craft's lockout.** Wrong passwords count towards `maxInvalidLogins`, exactly as a
control panel sign-in does, so a locked account stays locked until Craft's cooldown passes. A
control panel account is refused without its password being checked at all — it gets the same
`401 customer_login_failed` as a wrong guess — so the storefront API can neither lock an admin out
nor be used to guess an admin's password. A password change signs out every customer token issued
before it.

**`cart.customer` names the account only to that account.** On a request without the matching
customer token it is `null`, even when the cart's email belongs to a registered customer — a cart
token proves you hold a cart, not who you are.

**Credential endpoints are rate limited per address**, whatever the API key's own limit: sign-in 10
a minute (and 5 per account name), registration 5, refresh 30, order lookup 20. Over the limit is a
`429 rate_limited` with `Retry-After`.

**Registration follows Craft's "Verify email addresses" user setting.** With it on (Craft's
default), `POST /customers` creates a pending account, sends Craft's activation email, and answers
`202` with `{"verificationRequired": true}` and **no tokens** — sign in once the address is
confirmed. With it off, the account is active at once and the response is `201` with
`verificationRequired: false` plus the token pair. `password` is optional only when verification is
on (the activation email then lets the customer set one). `fields` may only set the custom fields
listed in the **Fields registration may set** setting; anything else is ignored.

## Store

| Method | Path |
| --- | --- |
| `GET` | `/` — discovery: version, edition, which features are on |
| `GET` | `/store` — currency, countries, gateways, sites, checkout rules |
| `GET` | `/stores` — every store, for multi-store |

## Error codes

| Code | Status | Means |
| --- | --- | --- |
| `api_disabled` | 503 | The API is switched off in settings. |
| `commerce_unavailable` | 503 | Commerce is missing or disabled. |
| `unauthorized` | 401 | No key, or a bad one. |
| `forbidden` | 403 | Key lacks a scope, or the origin is not allowed. |
| `pro_required` | 403 | Lite install, Pro endpoint. |
| `rate_limited` | 429 | Over the per-minute limit. `Retry-After` is set. |
| `invalid_json` | 400 | The body did not parse. |
| `invalid_request` | 422 | Malformed parameters. |
| `not_found` | 404 | No such product, variant, store, address or order, or no such route. |
| `cart_not_found` | 404 | No cart token, or an unknown one. |
| `cart_token_expired` | 401 | The token existed but has expired — start a new cart. |
| `cart_completed` | 409 | The cart is already an order. |
| `cart_locked` | 409 | Another request holds this cart. Retry. |
| `cart_invalid` | 422 | The cart failed validation. `error.errors` names the fields. |
| `line_item_not_found` | 404 | No such line item in this cart. |
| `purchasable_not_found` | 404 | No such purchasable. |
| `purchasable_unavailable` | 422 | Out of stock, disabled, or not for sale here. |
| `shipping_method_unavailable` | 422 | Not an option for this cart. `availableShippingMethods` lists what is. |
| `checkout_incomplete` | 422 | `missing` lists what to collect. |
| `payment_gateway_unavailable` | 422 | No usable gateway. |
| `payment_amount_changed` | 409 | The total moved between quote and payment. `changed` says which. |
| `payment_failed` | 402 | The gateway declined. |
| `redirect_not_allowed` | 422 | Return URL is not an allowed origin. |
| `transaction_not_found` | 404 | Unknown transaction hash. |
| `customer_login_failed` | 401 | Bad credentials, or login is off. |
| `customer_token_expired` | 401 | Access or refresh token is expired or revoked. |
| `customer_exists` | 409 | An account already exists for that email. |
| `customer_registration_disabled` | 403 | Registration is turned off in settings. |
| `server_error` | 500 | Logged. The message is only echoed back in dev mode. |

## GraphQL — Pro

Registered on Craft's own GraphQL endpoint. Authentication is a `cartToken` argument, not a cookie.

**A schema has to be granted Headdy's carts.** Under *Settings → GraphQL → Schemas*, tick
**Headdy storefront → Read carts and checkout state** (`headdyCarts:read`) for the queries and
**Create, change and complete carts** (`headdyCarts:edit`) for the mutations. A schema without them
— the public schema included — has no `headdyCart*` fields at all, and a resolver reached anyway
refuses. The plugin's per-minute rate limit applies per address.

```graphql
mutation {
  headdyCartCreate(items: [{ purchasableId: 123, qty: 1 }]) {
    token
    totalQty
    totals { total { formatted } }
  }
}

mutation {
  headdyCartAddItem(cartToken: "hdc_…", purchasableId: 456, qty: 2) {
    lineItems { id description qty total { formatted } }
  }
}

query {
  headdyCheckout(cartToken: "hdc_…") { ready missing amountDue { formatted } }
}
```

Full list: `headdyCartCreate`, `headdyCartAddItem`, `headdyCartUpdateItem`, `headdyCartRemoveItem`,
`headdyCartClear`, `headdyCartSetEmail`, `headdyCartSetAddresses`, `headdyCartApplyCoupon`,
`headdyCartSetShippingMethod`, `headdyCartSetGateway`, `headdyCheckoutComplete`; queries
`headdyCart` and `headdyCheckout`.

Payment stays on REST. A gateway redirect needs a real HTTP response to hand back, and squeezing
one through a GraphQL mutation makes for a worse client than a single `POST`.

Line item `options` are a JSON **string** in GraphQL — GraphQL has no map type, and a plugin cannot
invent one input type per store's option keys.

## Webhooks — Pro

Deliveries are queued, so a slow receiver never holds a checkout open. Each carries:

```
X-Headdy-Topic: order.paid
X-Headdy-Delivery: 5d1c…  (a UUID, the same on every retry of one delivery)
X-Headdy-Signature: t=1717171717,v1=<hmac-sha256 of "<t>.<body>">
```

Verify the HMAC with the endpoint's signing secret, and reject anything more than a few minutes old
— the timestamp is inside the signed material precisely so replay is detectable. De-duplicate on
`X-Headdy-Delivery`: a retried delivery carries the same ID.

Outside dev mode, an endpoint must resolve to a public address — private, loopback, link-local and
reserved ranges are refused — and redirects from it are not followed.

Topics: `cart.created`, `cart.updated`, `cart.completed`, `order.paid`, `order.statusChanged`,
`payment.failed`.

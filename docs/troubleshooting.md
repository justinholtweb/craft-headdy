---
title: Troubleshooting
slug: troubleshooting
order: 50
summary: What to check when a request is refused, a browser reports a CORS error, a payment won't redirect, or a token stops working.
---

Start with **Headdy → Overview** or `php craft headdy/maintenance/check`. Most problems below show up
there first.

## Every request returns 404

- The base path in the URL doesn't match **Base path** in the settings. `php craft
  headdy/maintenance/routes` prints the real route table.
- The URL is missing `/v1`. The API lives at `<base path>/v1`, not at the base path itself.
- **Base path** points at an environment variable that isn't set, which leaves no routes
  registered. The Overview screen reports that as an error.

## Every request returns 503

- `api_disabled`: the **Enabled** switch is off.
- `commerce_unavailable`: Commerce is uninstalled or disabled.

## `401 unauthorized`

- No `X-Headdy-Key` header, or a key that's been deleted or disabled.
- In *Public key + secret* mode, a missing or wrong `X-Headdy-Secret`. A rotated secret replaces the
  old one immediately.

## `403 forbidden`

- The key lacks the scope the endpoint needs. Customer endpoints need `customer:read` or
  `customer:write`, which new keys don't have.
- The key has its own origin list and the request came from an origin not on it.
- `pro_required` is a different code: the endpoint is Pro-only and the install is Lite.

## The browser says "CORS error" or "network error"

- Check the request in the browser's network tab. If the response is a 4xx or 5xx, the CORS error
  is a symptom. Fix the underlying error first.
- The origin isn't in **Allowed origins**. Compare scheme, host and port exactly:
  `http://localhost:3000` and `http://127.0.0.1:3000` are different origins.
- You're sending `credentials: 'include'`. Headdy doesn't use cookies, so leave it out. If you really
  need it, switch on **Allow credentials** and name your origins, because browsers reject credentials
  alongside `*`.
- A CDN or reverse proxy in front of Craft is caching responses without varying on `Origin`, or is
  stripping `OPTIONS` requests.

## The JSON body seems to be ignored

Send `Content-Type: application/json`. A form-encoded body is read as form fields, and a `null`
can't be expressed in one, which is how the API tells "clear this" apart from "leave it alone".

## `redirect_not_allowed` when paying

The `returnUrl` or `cancelUrl` origin isn't in **Allowed redirect origins**. The list is empty by
default, so this is expected on a fresh install with an off-site gateway. Add your storefront's
origin, or pass a relative path.

## `payment_amount_changed`

The cart's total changed between the checkout quote and the payment: a price changed, a coupon
expired, or stock ran out. Fetch `GET /checkout`, show the shopper the new total, and pay again.

## `cart_locked`

Two requests changed the same cart at the same moment. Retry the second one. If it happens often, the
front end is probably firing an update on every keystroke. Debounce it.

## A cart token stops working

- `cart_token_expired`: it outlived **Cart token lifetime**. With sliding expiry on, that only
  happens to carts left idle for the whole duration.
- `cart_completed`: the cart became an order. Its token is revoked at checkout. Start a new cart.
- `cart_not_found`: the token was never valid here. A token is tied to the install that issued it, so
  staging tokens don't work on production.

## Customers can't sign in

- **Allow customer sign-in** is off, or the key lacks `customer:write`.
- The account is a control panel account. Those are refused on purpose, with the same
  `customer_login_failed` as a wrong password, so a storefront credential can never become a control
  panel session. Use a separate shopper account.
- The account is locked by Craft's own `maxInvalidLogins` and stays locked until the cooldown passes.
  Unlock it from the user's edit screen.
- Every one of these returns the same `customer_login_failed`, so it can't be used to discover which
  accounts exist. Check the user's status on their edit screen.

## Registration returns 202 and no tokens

That's Craft's **Verify email addresses** setting working as intended. The account is pending until
the shopper clicks the activation email. Then they sign in normally.

## GraphQL says `headdyCartCreate` doesn't exist

The schema your token uses hasn't been granted **Headdy storefront** under **Settings → GraphQL →
Schemas**. The public schema never has it by default. Also check you're on Pro and that **GraphQL
cart mutations** is on in the settings.

## Webhooks don't arrive

- Webhooks are Pro-only.
- Deliveries run on Craft's queue. If the queue isn't running, nothing is sent. Check
  **Utilities → Queue Manager**.
- The endpoint's delivery history shows each attempt's status code and error.
- "Webhooks cannot be sent to a private or reserved address": outside dev mode, the endpoint must
  resolve to a public address. Use the receiver's public hostname.
- Verify signatures against the raw request body, not a re-serialized one. Re-encoding changes the
  bytes and the HMAC won't match.

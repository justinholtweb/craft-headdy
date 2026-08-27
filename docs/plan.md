# Headdy — design notes

## The problem, precisely

Craft Commerce's cart lives behind `CartController`, which:

- identifies the cart by a cookie holding the order number (and will also accept that number as a
  POST param — so the number *is* the credential, while also being printed on the order, quoted in
  emails, and shown in the control panel);
- requires a CSRF token, which a JSON client has to fetch in a separate round trip;
- returns `$order->toArray()` — internal element attributes, changing between releases, with bare
  floats for money;
- and, at the payment step, reads its return URL with `getValidatedBodyParam()`, which only accepts
  a value Craft hashed into a Twig form. No endpoint mints that hash. A JSON client simply cannot
  drive an off-site gateway.

There are no GraphQL mutations at all for carts or orders (craftcms/commerce#2350).

## The shape of the answer

**One service layer, two transports.**

```
        REST controllers          GraphQL mutations
                 \                      /
                  \                    /
                   services\Carts::update()
                            |
                    Craft Commerce
```

Everything else follows from that. The granular REST verbs are param assembly. The GraphQL
resolvers are param assembly. Neither contains business logic, so they cannot disagree.

A second single-source rule covers output: `services\Serializer` shapes every payload, so the cart
in a checkout response is the same object as the cart in a cart response.

## Decisions

**Cart identity is an opaque token, not the order number.** The number is not secret and cannot be
rotated. A token can be revoked, expires, slides forward while a shopper is active, and dies when
the cart becomes an order. Stored as a SHA-256 hash against a unique index — one indexed read, and a
database dump yields nothing usable.

**No CSRF, and no login.** CSRF protects a credential the browser attaches by itself; a bearer token
is only sent deliberately. And a customer token is deliberately *not* a Craft session: nothing in
this plugin calls `$userSession->login()`, so a leaked storefront token can never escalate.

**Return URLs are allow-listed rather than hashed.** The hash's purpose is to stop an attacker
choosing where a shopper lands after payment. An allow-list achieves that and can be satisfied from
JavaScript. Empty by default, so nothing is loosened until the merchant opts in.

**CORS defaults open, deliberately.** Since no cookie is ever sent, CORS is not the control that
protects this API. A locked-down default would make every fresh install appear broken while adding
no security. The Overview screen recommends narrowing it.

**Money is an object everywhere.** `{amount: "24.99", minorUnits: 2499, currency, formatted}`.
`amount` is a string because JSON numbers are doubles; `minorUnits` is what a processor wants;
`formatted` is display-only.

**Errors carry stable codes.** Messages are translated and may be reworded; the code is contract,
and a failed cart mutation still returns the cart so a client can re-render.

**`GET /checkout` answers the whole question at once** — what is missing, what can satisfy it, and
the store's own rules. Store configuration lives in the control panel and changes; a front end that
hard-codes it drifts the day someone edits it.

## Editions

**Lite ($99)** is a complete anonymous storefront: carts, checkout, payment, catalog, multi-store,
keys and scopes. That is the whole shop for a guest-checkout store.

**Pro ($199)** adds what a larger build needs: customer accounts and order history, the GraphQL
mutations, outbound webhooks and the request log.

## Deliberately out of scope

- **Subscriptions.** Commerce's subscription flow is gateway-specific in ways a generic API cannot
  paper over honestly.
- **Payment inside GraphQL.** A gateway redirect needs a real HTTP response to hand back. Forcing it
  through a mutation makes a worse client than a single `POST`.
- **Replacing Craft's product GraphQL.** The catalog endpoints exist so a front end need not run two
  clients against two auth schemes; a site already happy with Craft's GraphQL should keep it.

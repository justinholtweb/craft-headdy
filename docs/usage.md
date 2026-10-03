---
title: Building a storefront
slug: usage
order: 30
summary: A cart, a checkout and a payment from a JavaScript front end, end to end, plus customer accounts and GraphQL.
---

This page walks through one shopper's journey from a browser. Every call works the same from a
server. The [API reference](api) has the full contract.

## A tiny client

```js
const API = 'https://shop-admin.example.com/api/storefront/v1';
const KEY = 'hd_pk_…';

async function headdy(path, { method = 'GET', body, cart, customer } = {}) {
  const res = await fetch(API + path, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'X-Headdy-Key': KEY,
      ...(cart && { 'X-Headdy-Cart': cart }),
      ...(customer && { 'X-Headdy-Customer': customer }),
    },
    body: body && JSON.stringify(body),
  });
  const json = await res.json();
  if (!json.success) throw Object.assign(new Error(json.error.message), json.error, { cart: json.cart });
  return json;
}
```

Notice what isn't there: no `credentials: 'include'`, no CSRF token request, no form encoding.

## Add to cart

The first add creates the cart:

```js
let { cart } = await headdy('/carts', {
  method: 'POST',
  body: { items: [{ purchasableId: 123, qty: 1 }] },
});
localStorage.setItem('cart', cart.token);
```

Every later add goes to the same cart:

```js
({ cart } = await headdy('/carts/current/items', {
  method: 'POST',
  cart: localStorage.getItem('cart'),
  body: { purchasableId: 456, qty: 2, options: { engraving: 'Ada' } },
}));
```

If the token has expired you get `cart_token_expired`. Drop it and start a new cart.

## Render it

Every amount comes as an object, so you never do arithmetic on floats:

```js
cart.lineItems.map(li => `${li.qty} × ${li.description} — ${li.total.formatted}`);
cart.totals.total.formatted;     // "$49.98"
cart.totals.total.minorUnits;    // 4998
```

## Checkout

Collect what checkout needs in one `PATCH`. Headdy applies the changes in a fixed order (items,
then addresses, then email, coupon and shipping), so it doesn't matter what order you write them in:

```js
await headdy('/carts/current', {
  method: 'PATCH',
  cart: token,
  body: {
    email: 'ada@example.com',
    shippingAddress: { addressLine1: '1 High St', locality: 'Charlotte', administrativeArea: 'NC', postalCode: '28202', countryCode: 'US' },
    billingSameAsShipping: true,
    couponCode: 'SPRING10',
  },
});
```

Then ask what's still missing, rather than guessing the store's rules:

```js
const { checkout } = await headdy('/checkout', { cart: token });
checkout.ready;                     // false
checkout.missing;                   // ["shippingMethod"]
checkout.availableShippingMethods;  // pick one, then PUT /carts/current/shipping-method
checkout.availableGateways;         // each carries the paymentFormParamName Commerce expects
```

## Pay

For a gateway that charges in place, such as Stripe with a payment method created by Stripe.js:

```js
const result = await headdy('/checkout/pay', {
  method: 'POST',
  cart: token,
  body: { gatewayId: 1, paymentForm: { paymentMethodId: pm.id } },
});
result.order.isPaid;   // true
localStorage.removeItem('cart');   // the cart is an order now; its token is revoked
```

For an off-site gateway, add the origin you return to under **Allowed redirect origins** first, then:

```js
const { redirect } = await headdy('/checkout/pay', {
  method: 'POST',
  cart: token,
  body: { gatewayId: 2, returnUrl: 'https://shop.example.com/thanks', cancelUrl: 'https://shop.example.com/cart' },
});

if (redirect.method === 'GET') {
  location.href = redirect.url;
} else {
  // Gateways that sign a POST body reject a shopper who arrives by navigation.
  const form = Object.assign(document.createElement('form'), { method: 'POST', action: redirect.url });
  for (const [name, value] of Object.entries(redirect.data)) {
    form.append(Object.assign(document.createElement('input'), { type: 'hidden', name, value }));
  }
  document.body.append(form);
  form.submit();
}
```

When the shopper lands on your return page, pass the gateway's `commerceTransactionHash` to
`POST /checkout/complete-payment`.

An order that owes nothing, such as a fully discounted cart, completes with
`POST /checkout/complete` instead.

## Customer accounts *(Pro)*

```js
const session = await headdy('/customers/sessions', {
  method: 'POST',
  cart: token,          // optional: attaches the guest cart to the account
  body: { loginName: 'ada@example.com', password: '…' },
});
// session.token (short-lived), session.refreshToken (long-lived, single use)

const { orders } = await headdy('/customers/me/orders', { customer: session.token });
```

When the access token expires (`customer_token_expired`), swap the refresh token for a new pair at
`POST /customers/sessions/refresh`. A refresh token works once. Keep the new one it hands back.

Every failed sign-in returns the same `customer_login_failed`, whether the account doesn't exist, is
suspended or has the wrong password. Show one message for all of them.

## GraphQL *(Pro)*

Once your schema has the **Headdy storefront** permissions (see [Configuration](configuration)):

```graphql
mutation Add($token: String!) {
  headdyCartAddItem(cartToken: $token, purchasableId: 456, qty: 2) {
    totalQty
    totals { total { formatted } }
  }
}
```

The mutations go through the same service as REST, so a cart changed one way reads identically the
other way. Payment stays on REST, because a gateway redirect needs a real HTTP response.

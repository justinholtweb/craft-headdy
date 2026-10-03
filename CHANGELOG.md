# Release Notes for Headdy

## 5.0.0

Initial release.

### The API

- A versioned REST storefront API for Craft Commerce, mounted at `/<base path>/v1`.
- Cart endpoints: create, read, patch, delete; line item add/update/remove/clear; addresses, email,
  coupon and shipping method; the shipping methods available to a given cart.
- Checkout: a single `GET /checkout` that reports what is still missing, what can satisfy it, and
  the store's own rules — so a front end never discovers them by trial and error.
- Payment, including off-site gateways, whose redirect is returned as JSON rather than a 302.
- Catalog: products, variants by ID or SKU, and product types.
- Store discovery: currency, countries, gateways, sites, and checkout requirements.
- Customer accounts, order history, address book and saved payment sources. *(Pro)*
- A guest order lookup by number and email.

### GraphQL *(Pro)*

- Eleven cart mutations and two queries registered on Craft's own GraphQL endpoint, closing the gap
  left by craftcms/commerce#2350. Every one resolves through the same service method the REST
  endpoints use.
- They exist only on schemas granted the **Headdy storefront** component (`headdyCarts:read`,
  `headdyCarts:edit`) — never on the public schema by default — and resolvers refuse a schema
  without it. The plugin's rate limit applies per address.

### Authentication

- API keys with a public half and a hashed secret half, per-key scopes, per-key origin restrictions,
  per-key rate limits and optional expiry.
- Cart tokens and customer tokens, stored only as SHA-256 hashes, with sliding expiry, refresh token
  rotation and explicit revocation.
- No session, no cookie and no CSRF token on any API request.
- Customer sign-in goes through Craft's own authentication, so wrong passwords count towards Craft's
  lockout; control panel accounts are refused before anything is counted, so the API can't lock an
  admin out.
- Credential endpoints (sign-in, registration, refresh, order lookup) are rate limited per address,
  and sign-in per account name, independently of the API key's limit.
- Registration follows Craft's "Verify email addresses" setting: a new account is pending and gets
  no tokens until its address is confirmed. Only the custom fields listed in **Fields registration
  may set** can be filled in.
- A zero-balance checkout is re-checked after the cart is recalculated, so a total that changed in
  between can't be completed unpaid.

### Control panel

- An Overview screen that checks the configuration and prints the full route table.
- API key management, with the secret shown exactly once.
- Webhook endpoints with signed, queued delivery and a delivery history. *(Pro)*
- A request log with automatic redaction of passwords, card details and tokens. *(Pro)*

### Console

- `headdy/keys` to create, rotate, list and delete keys.
- `headdy/maintenance` for token purging, log pruning, the configuration check and the route table.

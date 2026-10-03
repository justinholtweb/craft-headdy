# Headdy — Craft CMS 5 Plugin

## Project Overview

Headdy is a **headless storefront API for Craft Commerce**: a versioned REST API plus GraphQL cart
mutations, authenticated by bearer tokens rather than session cookies. Distributed as
`justinholtweb/craft-headdy`. **Lite ($99) + Pro ($199).**

## Why it exists

Commerce has never shipped GraphQL mutations for carts or orders — craftcms/commerce discussion
#2350 has been open for years — so a headless build drops to form-encoded POSTs at
`actions/commerce/cart/update-cart` with a PHP session cookie and a CSRF token. Cross-origin that
means `credentials: 'include'`, a `SameSite` policy, an extra round trip for the token, responses
shaped like Craft's internal element attributes, and a payment step that is *unreachable* because
Craft hashes the return URL into a Twig form JavaScript cannot produce.

Woo has the Store API and CoCart. Craft had nothing. That is the gap.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step: no asset bundles, no JS beyond inline `{% js %}` blocks

## Architecture

### Namespace & package

- Namespace: `justinholtweb\headdy`
- Package: `justinholtweb/craft-headdy`
- Handle: `headdy`

### The three invariants

1. **`services\Carts::update()` is the only place a cart changes.** Every REST endpoint, every
   GraphQL mutation and every console command builds a parameter array and hands it there. The
   granular verbs are each a few lines of param assembly. REST and GraphQL therefore cannot drift.
2. **`services\Serializer` is the only place a payload is shaped.** No controller builds its own
   response body, so a cart inside a checkout response can never disagree with a cart inside a cart
   response. Money is always an object, never a bare float.
3. **A token is stored only as a SHA-256 hash.** Lookup is by hash against a unique index, so a
   database dump yields no usable tokens and the lookup stays one indexed read.

### Mutation ordering

`Carts::update()` applies changes in a fixed order regardless of the order the caller wrote them:
clear → items → addresses → email → coupon → shipping → gateway → fields. Setting a shipping method
before an address exists picks from an empty option list; a coupon applied before the items are in
does not meet the discount's minimum. One big patch and six small ones must agree.

### Data model

Nothing lives in project config except the plugin settings. API keys, cart tokens and webhook URLs
are secrets or environment-specific — syncing them through YAML would leak a production key into a
developer's checkout and overwrite it on the next apply.

- `{{%headdy_apikeys}}` — public half plus a `password_hash`ed secret half
- `{{%headdy_carttokens}}` — unique on `tokenHash`; FK to `commerce_orders` with `CASCADE`
- `{{%headdy_customertokens}}` — access and refresh hashes, rotation burns the old refresh row
- `{{%headdy_log}}`, `{{%headdy_webhooks}}`, `{{%headdy_webhookdeliveries}}`

JSON-ish columns are plain `text` and encoded by hand — Craft's query builder double-encodes real
`json` columns.

### Security decisions worth not re-litigating

- **No CSRF token.** CSRF defends a credential the browser attaches automatically. A bearer token is
  only ever sent deliberately, which is the same defence. Requiring one would add a round trip and
  no security.
- **No login.** `Craft::$app->getUser()` stays anonymous even when a customer token is supplied;
  `services\RequestContext` holds the identity instead. A leaked storefront token can never become a
  control panel session, and control panel accounts are refused at sign-in.
- **CORS defaults open.** The bearer token protects the API and no cookie is ever sent, so a
  restrictive default would only mean every install starts broken. The Overview screen flags it.
- **Return URLs are allow-listed, not hashed** — see `services\Payments`. The list is empty by
  default, so off-site gateways are unusable until the merchant opts in. That is the safe default,
  not an oversight.
- **A saved payment source needs a customer token**, not just a cart token. Otherwise anyone holding
  a cart token could charge someone else's card. That includes a source *already saved on the cart*
  from an earlier, failed attempt — `Payments::pay()` re-checks it, and drops it, every time.
- **A cart token proves a cart, not an account.** `cart.customer` is `null` unless the caller's
  customer token is that customer (`Serializer::_customerForCaller()`); webhooks pass
  `trusted: true` and get the full stub. Likewise the `save*AddressOnOrderComplete` flags are
  ignored on a registered customer's cart without their token.
- **A password change kills every customer token issued before it** — checked against
  `lastPasswordChangeDate` at resolution, so every route to a new password is covered without an
  event listener. `headdy/maintenance/revoke-customer` is for a lost device.
- **Webhook URLs must resolve to public addresses** outside dev mode, the checked IP is pinned with
  `CURLOPT_RESOLVE` (DNS rebinding), redirects are not followed, and transport errors are shown
  generically — otherwise "Send test" is an internal port scanner.
- **Login failures are indistinguishable** — same code, same message, whether the account is
  missing, suspended, or the password is wrong. Otherwise the endpoint enumerates customers.
- **Sign-in goes through `User::authenticate()`** so Craft's lockout counts it — but a control panel
  account is refused *before* that, or the API becomes a way to lock admins out. (The test suite did
  exactly that to the harness admin before the order was fixed.) Its password is **never checked**:
  an explanatory 403 for the right password was a lockout-free admin password oracle, so a CP
  account fails exactly like a wrong guess. Unknown accounts pay a hash's cost too, so timing
  doesn't enumerate. The per-account throttle is keyed on the resolved user ID, not the string.
- **Registration honours Craft's `users.requireEmailVerification`**: pending account, activation
  email, `202 {verificationRequired: true}`, no tokens. `fields` is filtered by the
  `registrationFields` setting.
- **Registration still answers `409 customer_exists`.** That does reveal an address is registered —
  but so does Craft's own registration form, and a shopper told "check your email" for an account
  they forgot they had is a support ticket. It is off by default and limited to 5 a minute per
  address. A deliberate trade, flagged by the pre-release audit; don't "fix" it silently.
- **GraphQL is a schema component** (`headdyCarts:read` / `:edit`), checked when the schema is built
  *and* in every resolver (`CartMutations::guard()`).

## Traps found while building this

- **`yii\base\Event` already declares an untyped `$data`.** A subclass that types its own `$data`
  is a *compile-time* fatal — "Type of X::$data must not be defined (as in class yii\base\Event)".
  Headdy's serializer events use `$cartInfo` / `$productInfo`, matching Commerce's own
  `ModifyCartInfoEvent`.
- **`DateTimeHelper::toDateTime($value, true)` assumes the *system* time zone, not UTC.** Craft's
  datetime columns hold bare UTC strings, so passing `true` shifts every timestamp by the site's
  offset — west of UTC that keeps expired tokens working for hours. Pass `false`.
- **`Plugins::savePluginSettings()` writes `toArray(array_keys($settings))`** — only the keys it was
  handed — and that array *replaces* the whole settings node. A partial save silently wipes every
  other setting. Merge before saving.
- **Craft memoizes the config version it booted with** and refuses a project config write once the
  stored one has moved on (`StaleResourceException`). A long-running console script that saves twice
  has to re-sync `Craft::$app->getInfo()->configVersion` from the `info` table between writes.
- **Commerce gateways have no `getName()`.** `name` is a plain property on `GatewayTrait`; calling
  the getter fatals with "Calling unknown method".
- **`Store::getCurrency()` returns a `\Money\Currency` object, not a string.** Dropped into a
  payload it serializes as `{}`. `Store::getCountries()` is deprecated in Commerce 5 and logs to the
  deprecator on every call — go through `getSettings()`.
- **`User::getAddresses()` returns an `ElementCollection`**, so `array_map()` over it silently
  returns nothing. And `AddressQuery` has no owner param — fetch by ID and check `ownerId` yourself,
  because a query that ignores an unknown condition returns every address on the site.
- **`ensureUserByEmail()` looks the user up through `Db::parseParam()`**, where `*` and `%` are LIKE
  wildcards — and `FILTER_VALIDATE_EMAIL` accepts both in a local part. `*@gmail.com` bound a cart
  to the first matching account. `Carts::_applyEmail` refuses `* % , \` and a leading `= < > !`.
- **`completePayment()` returns true for a transaction that already succeeded**, so a replayed
  `complete-payment` re-ran the completion side effects. Webhooks fire on the *transition* only.
- **A customer token row holds both halves.** Purging it when the 1-hour access token expired
  deleted the 30-day refresh token with it and signed everyone out on each maintenance run.
- **Craft registers no JSON body parser by default**, so `getBodyParams()` cannot see a JSON body
  unless the site edits `config/app.php`. Headdy parses the raw body itself.
- **Primary billing/shipping address IDs live in a Commerce table**, not on the user element —
  assigning the behavior property and saving the user writes nothing. Use
  `Commerce::getCustomers()->savePrimary*AddressId()`.
- **Commerce 5 stores a variant's price as `basePrice`**; `price` is computed from it and the
  catalog-pricing table. A fixture that sets `price` saves at 0, and every test cart was free until
  `makeProduct()` was fixed — so no test had ever proved a balance was charged.
- **Craft keeps built GraphQL types in static registries** (`TypeLoader`, `GqlEntityRegistry`) for
  the life of the process. Building a second schema in the same script reuses the first one's
  `Mutation` type unless you call `Gql::flushCaches()` between them.
- **An install that half-fails leaves its tables behind**, and the retry then dies on "table already
  exists". `Install::safeUp()` returns early when the whole schema is already present.
- **Craft's URL rules support `'POST pattern' => 'route'`**, and `array_filter()` runs over the
  final rule list — a falsy route value is dropped silently.
- **A 404 under a base path with no route gets no CORS headers**, which a browser reports as a
  network error rather than a 404. Hence the catch-all `OPTIONS` rule.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-headdy/tests/integration/checks.php   # 133 checks
ddev exec bash -c 'find /var/www/craft-headdy/src -name "*.php" -print0 | xargs -0 -n1 php -l'
ddev exec bash -c 'cd /var/www/craft-headdy && vendor/bin/phpstan --memory-limit=1G && vendor/bin/ecs check'
```

`composer.json` pins `config.platform.php` to 8.2 because the container runs 8.2. Without it a
`composer update` on a newer host PHP writes a lock whose platform check fatals every vendor binary
in the container.

The suite switches to Pro for the bulk of the run, exercises Lite gating in its own section, and
restores the edition, the settings and every fixture in a `finally`. It includes **live HTTP
round-trips** against the real endpoint — key rejection, scope refusal, CORS preflight, the whole
cart lifecycle, a real Dummy-gateway payment, rate limiting and the error envelope — so a green run
means the wire protocol works, not just the units.

**Harness notes.** Two sibling plugins in the shared harness break unrelated things and the suite
works around both, printing a line when it does:

- `craft-penny` registers an `Elements::EVENT_BEFORE_SAVE_ELEMENT` handler typed `ModelEvent` while
  Craft passes an `ElementEvent`, so **every element save fatals** while it is enabled. Detached
  in-process (never persisted).
- `craft-lyfe`'s `EVENT_AFTER_COMPLETE_ORDER` handler calls `$address->getFieldValue('phone')` with
  no guard, so **completing any order that has an address fatals**. That fires in the web process,
  so it cannot be detached in-process — the suite disables the plugin for the run and re-enables it
  in the `finally`.
- The harness's `config/redirects.php` (a `craft-passer` test artifact) contains entries with a
  `template` key that `craft\web\RedirectRule` rejects, which turns **every 404 in the harness into
  a fatal** and hides the real error. Move it aside when debugging a 404.

## Coding conventions

- `Craft::t('headdy', '…')` for user-facing strings; `src/translations/en/headdy.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Every API error carries a stable `code`; clients branch on the code, never the message
- `docs/api.md` is the published contract. A change to a response shape means a `v2`, not an edit

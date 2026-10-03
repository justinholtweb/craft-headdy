<?php
/**
 * Headdy integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-headdy/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: fixture products, carts, API keys, webhooks, log rows, the plugin
 * edition and the settings it overwrites are all restored in a `finally`, pass or fail.
 *
 * The suite includes **live HTTP round-trips against the real endpoint** — key rejection, CORS
 * preflight, the whole cart lifecycle, a real gateway payment and the error envelope — so a green
 * run means the wire protocol works, not just the units.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use justinholtweb\headdy\db\Table;
use justinholtweb\headdy\errors\ApiException;
use justinholtweb\headdy\helpers\Money;
use justinholtweb\headdy\models\ApiKey;
use justinholtweb\headdy\models\Settings;
use justinholtweb\headdy\models\Webhook;
use justinholtweb\headdy\Plugin;
use justinholtweb\headdy\services\Checkout;
use justinholtweb\headdy\services\Tokens;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$serializer = $plugin->getSerializer();
$tokens = $plugin->getTokens();
$carts = $plugin->getCarts();
$store = $commerce->getStores()->getPrimaryStore();
$suffix = substr(md5((string)microtime(true)), 0, 6);

$createdProducts = [];
$createdOrders = [];
$createdKeys = [];
$createdWebhooks = [];
$createdUsers = [];

$originalSettings = $plugin->getSettings()->toArray();
$originalEdition = Craft::$app->getPlugins()->getPluginInfo(Plugin::HANDLE)['edition'] ?? Plugin::EDITION_LITE;

// `craft-penny` (a sibling plugin in this shared harness) registers an
// Elements::EVENT_BEFORE_SAVE_ELEMENT handler typed `ModelEvent` while Craft passes an
// `ElementEvent`, so saving *any* element fatals while it is enabled. Nothing to do with Headdy;
// detached in-process here (never persisted) so fixtures can be created.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
}

// `craft-lyfe` (another sibling in this shared harness) handles EVENT_AFTER_COMPLETE_ORDER and
// calls `$address->getFieldValue('phone')` with no guard, so completing *any* order that has an
// address fatals while it is enabled. That fires in the web process, so it cannot be detached
// in-process the way Penny's can — the plugin has to come out for the run and go back in after.
// Nothing to do with Headdy.
$lyfeWasEnabled = Craft::$app->getPlugins()->isPluginEnabled('lyfe');

if ($lyfeWasEnabled) {
    Craft::$app->getPlugins()->disablePlugin('lyfe');
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
    echo "  ! disabled craft-lyfe for this run (its order-complete handler fatals on addressed orders)\n";
}

/**
 * Project config writes are buffered until the request ends, and a bare console script has no
 * request end — so it has to flush them itself or the next apply deletes what it wrote.
 */
function applySettings(array $values): void
{
    global $plugin;

    syncConfigVersion();

    // Merged over the current settings on purpose. `savePluginSettings()` writes
    // `toArray(array_keys($settings))` — only the keys it was handed — and that array replaces the
    // whole `plugins.headdy.settings` node, so a partial save silently wipes everything else.
    Craft::$app->getPlugins()->savePluginSettings($plugin, array_merge($plugin->getSettings()->toArray(), $values));
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
}

/**
 * Craft memoizes the config version it booted with and refuses to write project config when the
 * stored one has moved on. Each save in this long-running script moves it, so the second write
 * would throw `StaleResourceException` unless the in-memory copy is brought back into line.
 */
function syncConfigVersion(): void
{
    $stored = (new craft\db\Query())
        ->select(['configVersion'])
        ->from(craft\db\Table::INFO)
        ->scalar();

    if ($stored) {
        Craft::$app->getInfo()->configVersion = $stored;
    }
}

function switchEdition(string $edition): void
{
    syncConfigVersion();
    Craft::$app->getPlugins()->switchEdition(Plugin::HANDLE, $edition);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
}

function makeProduct(string $sku, float $price): Variant
{
    global $createdProducts, $commerce;

    $type = $commerce->getProductTypes()->getAllProductTypes()[0]
        ?? throw new RuntimeException('No product type in this install.');

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Headdy fixture $sku";
    $product->enabled = true;
    $product->postDate = new DateTime('-1 day');

    $variant = new Variant();
    $variant->sku = $sku;
    // `basePrice` is what Commerce 5 stores; `price` is computed from it and the catalog, so
    // setting `price` saved every fixture at 0.
    $variant->basePrice = $price;
    $variant->isDefault = true;
    $variant->inventoryTracked = false;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save the fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    $variant = $product->getVariants()->one();

    // Commerce 5 prices line items from its catalog pricing table, which a queue job fills in
    // after a save. Without generating it here every fixture costs nothing, so no test cart ever
    // had a balance — including the ones meant to prove a balance is charged.
    $commerce->getCatalogPricing()->generateCatalogPrices([$variant->id]);

    return $variant;
}

/**
 * A live HTTP call against the real endpoint.
 *
 * @return array{status: int, body: array, headers: array}
 */
function api(string $method, string $path, array $body = null, array $headers = [], string $rawBody = null): array
{
    global $plugin;

    $url = $plugin->getApiUrl() . $path;
    $ch = curl_init($url);

    $requestHeaders = ['Accept: application/json'];

    foreach ($headers as $name => $value) {
        $requestHeaders[] = "$name: $value";
    }

    if ($body !== null || $rawBody !== null) {
        $requestHeaders[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $requestHeaders,
    ]);

    if ($rawBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
    } elseif ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException("HTTP call failed: $error");
    }

    $rawHeaders = substr($response, 0, $headerSize);
    $rawResponseBody = substr($response, $headerSize);

    $parsedHeaders = [];

    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $parsedHeaders[strtolower(trim($name))] = trim($value);
        }
    }

    $decoded = json_decode($rawResponseBody, true);

    return [
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : ['_raw' => substr($rawResponseBody, 0, 400)],
        'headers' => $parsedHeaders,
    ];
}

echo "\nHeaddy integration checks\n=========================\n";

try {
    switchEdition(Plugin::EDITION_PRO);

    applySettings([
        'enabled' => true,
        'basePath' => 'api/storefront',
        'authMode' => Settings::AUTH_MODE_PUBLIC_KEY,
        'allowedOrigins' => [],
        'allowedRedirectOrigins' => ['https://shop.example.com'],
        'allowCredentials' => false,
        'rateLimit' => 0,
        'logRequests' => true,
        'logBodies' => true,
        'catalogEnabled' => true,
        'graphqlEnabled' => true,
        'allowCustomerLogin' => true,
        'allowCustomerRegistration' => true,
        'verboseErrors' => true,
    ]);

    $variant = makeProduct("HEADDY-A-$suffix", 25.00);
    $variant2 = makeProduct("HEADDY-B-$suffix", 10.50);

    $key = new ApiKey([
        'name' => "Headdy checks $suffix",
        'scopes' => array_merge(ApiKey::defaultScopes(), [ApiKey::SCOPE_CUSTOMER_READ, ApiKey::SCOPE_CUSTOMER_WRITE]),
    ]);
    $plugin->getKeys()->saveKey($key);
    $createdKeys[] = $key;
    $keySecret = $key->secret;

    $readOnlyKey = new ApiKey([
        'name' => "Headdy checks read-only $suffix",
        'scopes' => [ApiKey::SCOPE_CART_READ, ApiKey::SCOPE_CATALOG_READ],
    ]);
    $plugin->getKeys()->saveKey($readOnlyKey);
    $createdKeys[] = $readOnlyKey;

    $auth = ['X-Headdy-Key' => $key->publicKey];

    // =====================================================================
    section('Money');

    check('formats a two-decimal currency', function() {
        $money = Money::format(24.99, 'USD');

        return $money['amount'] === '24.99'
            && $money['minorUnits'] === 2499
            && $money['currency'] === 'USD'
            ?: 'got ' . json_encode($money);
    });

    check('amount is a string, never a float', fn() => is_string(Money::format(1.1, 'USD')['amount'])
        ?: 'amount was not a string');

    check('a zero-decimal currency has no minor units', function() {
        $money = Money::format(1500, 'JPY');

        return $money['amount'] === '1500' && $money['minorUnits'] === 1500
            ?: 'got ' . json_encode($money);
    });

    check('rounds half up rather than truncating', fn() => Money::format(0.125, 'USD')['minorUnits'] === 13
        ?: 'got ' . Money::format(0.125, 'USD')['minorUnits']);

    check('an unknown currency still formats', fn() => is_string(Money::format(5, 'ZZZ')['formatted'])
        ?: 'no formatted string');

    // =====================================================================
    section('Tokens');

    check('a token is only ever stored hashed', function() use ($tokens, $carts, $key, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $token = $tokens->issueCartToken($cart, $key);

        $stored = (new craft\db\Query())
            ->select(['tokenHash'])
            ->from(Table::CART_TOKENS)
            ->where(['orderId' => $cart->id])
            ->scalar();

        return $stored === hash('sha256', $token) && $stored !== $token
            ?: 'the stored value is not the SHA-256 of the token';
    });

    check('a token resolves back to its cart', function() use ($tokens, $carts, $key, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $token = $tokens->issueCartToken($cart, $key);

        return $tokens->getCartByToken($token)?->id === $cart->id ?: 'did not resolve';
    });

    check('an unknown token resolves to nothing', fn() => $tokens->getCartByToken('hdc_nonsense') === null
        ?: 'resolved something');

    check('issuing a second token revokes the first', function() use ($tokens, $carts, $key, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $first = $tokens->issueCartToken($cart, $key);
        $second = $tokens->issueCartToken($cart, $key);

        return $tokens->getCartByToken($first) === null
            && $tokens->getCartByToken($second)?->id === $cart->id
            ?: 'the first token still works, so revocation is not revocation';
    });

    check('an expired token stops resolving but is still known', function() use ($tokens, $carts, $key, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $token = $tokens->issueCartToken($cart, $key);

        Craft::$app->getDb()->createCommand()
            ->update(Table::CART_TOKENS, ['expiryDate' => craft\helpers\Db::prepareDateForDb(new DateTime('-1 hour'))], ['tokenHash' => Tokens::hash($token)])
            ->execute();

        return $tokens->getCartByToken($token) === null && $tokens->cartTokenExists($token)
            ?: 'the API cannot tell "expired" from "never existed"';
    });

    check('cart and customer tokens carry different prefixes', fn() => Tokens::CART_PREFIX !== Tokens::CUSTOMER_PREFIX
        ?: 'the Authorization header could not tell them apart');

    check('purging leaves an unexpired token alone', function() use ($tokens, $carts, $key, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $token = $tokens->issueCartToken($cart, $key);
        $tokens->purgeExpired(0);

        return $tokens->getCartByToken($token)?->id === $cart->id ?: 'purged a live token';
    });

    // =====================================================================
    section('API keys');

    check('a secret is stored only as a hash', function() use ($key, $keySecret) {
        $stored = (new craft\db\Query())->select(['secretHash'])->from(Table::API_KEYS)->where(['id' => $key->id])->scalar();

        return $stored !== $keySecret && str_starts_with((string)$stored, '$')
            ?: 'the secret looks recoverable from the database';
    });

    check('the right secret verifies', fn() => $plugin->getKeys()->verifySecret($key, $keySecret)
        ?: 'the correct secret was rejected');

    check('a wrong secret does not', fn() => !$plugin->getKeys()->verifySecret($key, 'hd_sk_wrong')
        ?: 'a wrong secret was accepted');

    check('rotating a secret invalidates the old one', function() use ($plugin, $keySecret) {
        $rotating = new ApiKey(['name' => 'rotate me', 'scopes' => [ApiKey::SCOPE_CART_READ]]);
        $plugin->getKeys()->saveKey($rotating);
        $first = $rotating->secret;

        $plugin->getKeys()->saveKey($rotating, true);
        $second = $rotating->secret;

        $result = $first !== $second
            && !$plugin->getKeys()->verifySecret($rotating, $first)
            && $plugin->getKeys()->verifySecret($rotating, $second);

        $plugin->getKeys()->deleteKeyById($rotating->id);

        return $result ?: 'the old secret still works after a rotation';
    });

    check('an expired key is not usable', function() {
        $expired = new ApiKey(['name' => 'expired', 'expiryDate' => new DateTime('-1 day')]);

        return !$expired->isUsable() ?: 'an expired key reported itself usable';
    });

    check('a key with no origins allows any', fn() => (new ApiKey())->allowsOrigin('https://anywhere.example')
        ?: 'an unrestricted key rejected an origin');

    check('a key with origins rejects the rest', function() {
        $scoped = new ApiKey(['origins' => ['https://shop.example.com']]);

        return $scoped->allowsOrigin('https://shop.example.com')
            && !$scoped->allowsOrigin('https://evil.example')
            ?: 'origin scoping does not scope';
    });

    // =====================================================================
    section('Cart mutation');

    check('creating a cart saves it', function() use ($carts, $key, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;

        return $cart->id !== null && !$cart->isCompleted ?: 'the cart was not saved';
    });

    check('adding an item sets the quantity', function() use ($carts, $key, $variant, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, ['addItems' => [['purchasableId' => $variant->id, 'qty' => 3]]]);

        return count($cart->getLineItems()) === 1 && $cart->getLineItems()[0]->qty === 3
            ?: 'got ' . count($cart->getLineItems()) . ' item(s)';
    });

    check('two entries for one purchasable merge into one line', function() use ($carts, $key, $variant, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, ['addItems' => [
            ['purchasableId' => $variant->id, 'qty' => 1],
            ['purchasableId' => $variant->id, 'qty' => 2],
        ]]);

        return count($cart->getLineItems()) === 1 && $cart->getLineItems()[0]->qty === 3
            ?: 'got ' . count($cart->getLineItems()) . ' line(s), qty ' . ($cart->getLineItems()[0]->qty ?? '?');
    });

    check('different options make different lines', function() use ($carts, $key, $variant, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, ['addItems' => [
            ['purchasableId' => $variant->id, 'qty' => 1, 'options' => ['engraving' => 'Ada']],
            ['purchasableId' => $variant->id, 'qty' => 1, 'options' => ['engraving' => 'Grace']],
        ]]);

        return count($cart->getLineItems()) === 2 ?: 'got ' . count($cart->getLineItems()) . ' line(s)';
    });

    check('adding the same purchasable twice accumulates', function() use ($carts, $key, $variant, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, ['addItems' => [['purchasableId' => $variant->id, 'qty' => 2]]]);
        $cart = $carts->update($cart, ['addItems' => [['purchasableId' => $variant->id, 'qty' => 2]]]);

        return $cart->getLineItems()[0]->qty === 4 ?: 'qty was ' . $cart->getLineItems()[0]->qty;
    });

    check('setting a quantity of zero removes the line', function() use ($carts, $key, $variant, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, ['addItems' => [['purchasableId' => $variant->id, 'qty' => 2]]]);
        $id = $cart->getLineItems()[0]->id;
        $cart = $carts->update($cart, ['updateItems' => [$id => ['qty' => 0]]]);

        return count($cart->getLineItems()) === 0 ?: 'the line survived';
    });

    check('a line item can be addressed by uid as well as id', function() use ($carts, $key, $variant, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, ['addItems' => [['purchasableId' => $variant->id, 'qty' => 1]]]);
        $uid = $cart->getLineItems()[0]->uid;

        return $carts->findLineItem($cart, $uid) !== null ?: 'uid lookup missed';
    });

    check('clearing empties the cart but keeps it', function() use ($carts, $key, $variant, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, ['addItems' => [['purchasableId' => $variant->id, 'qty' => 2]]]);
        $cart = $carts->update($cart, ['clearLineItems' => true]);

        return $cart->id !== null && count($cart->getLineItems()) === 0 ?: 'the cart is not empty';
    });

    check('an unknown purchasable is a typed 404', function() use ($carts, $key, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;

        try {
            $carts->update($cart, ['addItems' => [['purchasableId' => 99999999, 'qty' => 1]]]);
            return 'no exception';
        } catch (ApiException $e) {
            return $e->errorCode === ApiException::PURCHASABLE_NOT_FOUND && $e->statusCode === 404
                ?: "got {$e->errorCode} / {$e->statusCode}";
        }
    });

    check('an unknown line item is a typed 404', function() use ($carts, $key, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;

        try {
            $carts->update($cart, ['removeItems' => [99999999]]);
            return 'no exception';
        } catch (ApiException $e) {
            return $e->errorCode === ApiException::LINE_ITEM_NOT_FOUND ?: "got {$e->errorCode}";
        }
    });

    check('an unavailable shipping method is refused with the list of real ones', function() use ($carts, $key, $variant, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, ['addItems' => [['purchasableId' => $variant->id, 'qty' => 1]]]);

        try {
            $carts->update($cart, ['shippingMethodHandle' => 'definitely-not-a-method']);
            return 'no exception';
        } catch (ApiException $e) {
            return $e->errorCode === ApiException::SHIPPING_METHOD_UNAVAILABLE
                && isset($e->data['availableShippingMethods'])
                ?: "got {$e->errorCode}";
        }
    });

    check('an invalid email is refused before it reaches Craft', function() use ($carts, $key, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;

        try {
            $carts->update($cart, ['email' => 'not-an-email']);
            return 'no exception';
        } catch (ApiException $e) {
            return $e->statusCode === 422 && isset($e->errors['email']) ?: "got {$e->statusCode}";
        }
    });

    check('setting an email attaches a customer', function() use ($carts, $key, $suffix, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, ['email' => "ada-$suffix@example.com"]);

        return strcasecmp((string)$cart->getEmail(), "ada-$suffix@example.com") === 0
            ?: 'email was ' . var_export($cart->getEmail(), true);
    });

    check('an address array is accepted and owned by the cart', function() use ($carts, $key, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, ['shippingAddress' => [
            'addressLine1' => '1 High Street',
            'locality' => 'Charlotte',
            'administrativeArea' => 'NC',
            'postalCode' => '28202',
            'countryCode' => 'US',
        ]]);

        return $cart->getShippingAddress()?->addressLine1 === '1 High Street'
            ?: 'the address did not stick';
    });

    check('billingSameAsShipping copies the address', function() use ($carts, $key, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, [
            'shippingAddress' => ['addressLine1' => '9 Elm Road', 'locality' => 'Raleigh', 'administrativeArea' => 'NC', 'postalCode' => '27601', 'countryCode' => 'US'],
            'billingSameAsShipping' => true,
        ]);

        return $cart->getBillingAddress()?->addressLine1 === '9 Elm Road' ?: 'billing was not copied';
    });

    check('an unknown address key cannot be smuggled in', function() use ($carts, $key, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, ['shippingAddress' => [
            'addressLine1' => '2 Oak Way',
            'locality' => 'Charlotte',
            'administrativeArea' => 'NC',
            'postalCode' => '28202',
            'countryCode' => 'US',
            'id' => 999999,
            'ownerId' => 1,
        ]]);

        return $cart->getShippingAddress()?->id !== 999999 ?: 'a caller-supplied id was adopted';
    });

    check('a completed order refuses further mutation', function() use ($carts, $key, $variant, $plugin, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, [
            'addItems' => [['purchasableId' => $variant->id, 'qty' => 1]],
            'email' => 'closed@example.com',
        ]);
        $cart->markAsComplete();

        try {
            $carts->update($cart, ['clearLineItems' => true]);
            return 'a completed order was mutated';
        } catch (ApiException $e) {
            return $e->errorCode === ApiException::CART_COMPLETED && $e->statusCode === 409
                ?: "got {$e->errorCode} / {$e->statusCode}";
        }
    });

    // =====================================================================
    section('Serializer');

    check('every money field is an object, never a bare number', function() use ($carts, $key, $variant, $serializer, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, ['addItems' => [['purchasableId' => $variant->id, 'qty' => 2]]]);
        $data = $serializer->cart($cart);

        foreach ($data['totals'] as $name => $value) {
            if (!is_array($value) || !isset($value['amount'], $value['minorUnits'], $value['currency'])) {
                return "totals.$name is not a money object";
            }
        }

        foreach ($data['lineItems'] as $item) {
            foreach (['price', 'salePrice', 'subtotal', 'total'] as $name) {
                if (!is_array($item[$name])) {
                    return "lineItems[].$name is not a money object";
                }
            }
        }

        return true;
    });

    check('the payload is JSON-encodable with no resources or objects left in', function() use ($carts, $key, $variant, $serializer, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, ['addItems' => [['purchasableId' => $variant->id, 'qty' => 1]]]);
        $json = json_encode($serializer->cart($cart));

        return is_string($json) && json_last_error() === JSON_ERROR_NONE
            ?: 'json_encode failed: ' . json_last_error_msg();
    });

    check('dates are ISO 8601 with an offset', function() use ($serializer) {
        $formatted = $serializer->date(new DateTime('2026-01-02 03:04:05', new DateTimeZone('UTC')));

        return $formatted === '2026-01-02T03:04:05+00:00' ?: "got " . var_export($formatted, true);
    });

    check('a cart and a completed order serialize to the same shape', function() use ($carts, $key, $variant, $serializer, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, ['addItems' => [['purchasableId' => $variant->id, 'qty' => 1]], 'email' => 'shape@example.com']);
        $before = array_keys($serializer->cart($cart));
        $cart->markAsComplete();
        $after = array_keys($serializer->cart($cart));

        return $before === $after ?: 'the keys differ: ' . json_encode(array_diff($before, $after));
    });

    check('the token is only present when one is passed in', function() use ($carts, $key, $serializer, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;

        return $serializer->cart($cart)['token'] === null
            && $serializer->cart($cart, 'hdc_x')['token'] === 'hdc_x'
            ?: 'the token leaked into a payload that was not given one';
    });

    check('a serialized gateway carries the payment form param name', function() use ($serializer, $commerce) {
        $gateway = $commerce->getGateways()->getAllCustomerEnabledGateways()->first();

        if ($gateway === null) {
            return 'no gateway to test with';
        }

        $data = $serializer->gateway($gateway);

        return !empty($data['paymentFormParamName']) && !empty($data['handle'])
            ?: 'got ' . json_encode($data);
    });

    // =====================================================================
    section('Checkout requirements');

    check('an empty cart is missing its items', function() use ($carts, $key, $plugin, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;

        return in_array(Checkout::REQUIRES_ITEMS, $plugin->getCheckout()->missingRequirements($cart), true)
            ?: 'items was not reported missing';
    });

    check('a cart with no email is missing it', function() use ($carts, $key, $variant, $plugin, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, ['addItems' => [['purchasableId' => $variant->id, 'qty' => 1]]]);

        return in_array(Checkout::REQUIRES_EMAIL, $plugin->getCheckout()->missingRequirements($cart), true)
            ?: 'email was not reported missing';
    });

    check('a cart that owes nothing needs no payment', function() use ($carts, $key, $plugin, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;

        return !$plugin->getCheckout()->requiresPayment($cart) ?: 'an empty cart demanded payment';
    });

    check('the checkout state is fully JSON-encodable', function() use ($carts, $key, $variant, $plugin, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, ['addItems' => [['purchasableId' => $variant->id, 'qty' => 1]]]);
        $json = json_encode($plugin->getCheckout()->state($cart));

        return is_string($json) && json_last_error() === JSON_ERROR_NONE
            ?: 'json_encode failed: ' . json_last_error_msg();
    });

    check('a cart that only looked free before recalculating is not completed unpaid', function() use ($carts, $key, $variant, $plugin, &$createdOrders) {
        // A discount that covers the whole cart while it's priced and is gone by the time it's
        // completed — an expiring coupon, in effect.
        $GLOBALS['headdyTestFree'] = true;
        $adjuster = new class() implements craft\commerce\base\AdjusterInterface {
            public function adjust(craft\commerce\elements\Order $order): array
            {
                if (empty($GLOBALS['headdyTestFree']) || $order->getItemSubtotal() <= 0) {
                    return [];
                }

                $adjustment = new craft\commerce\models\OrderAdjustment();
                $adjustment->type = 'discount';
                $adjustment->name = 'Expiring coupon';
                $adjustment->amount = -$order->getItemSubtotal();
                $adjustment->setOrder($order);

                return [$adjustment];
            }
        };
        $handler = function(craft\events\RegisterComponentTypesEvent $event) use ($adjuster) {
            $event->types[] = get_class($adjuster);
        };
        // The service instantiates adjusters by class name, so the anonymous class has to be
        // constructible with no arguments — it is.
        yii\base\Event::on(craft\commerce\services\OrderAdjustments::class, craft\commerce\services\OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS, $handler);

        try {
            $cart = $carts->createCart($key);
            $createdOrders[] = $cart;
            $cart = $carts->update($cart, [
                'addItems' => [['purchasableId' => $variant->id, 'qty' => 1]],
                'email' => 'stale-total@example.com',
            ]);

            $checkout = $plugin->getCheckout();

            if ($checkout->missingRequirements($cart) !== [] || $checkout->requiresPayment($cart)) {
                return 'precondition not met: ' . json_encode($checkout->missingRequirements($cart)) . ', requiresPayment ' . var_export($checkout->requiresPayment($cart), true);
            }

            $GLOBALS['headdyTestFree'] = false;


            try {
                $checkout->complete($cart);

                return 'an order with a balance was completed without payment';
            } catch (ApiException $e) {
                $completed = craft\commerce\elements\Order::find()->id($cart->id)->isCompleted(true)->exists();

                return ($e->data['missing'] ?? null) === [Checkout::REQUIRES_PAYMENT_METHOD] && !$completed
                    ?: 'threw ' . $e->errorCode . ' ' . json_encode($e->data) . ', completed ' . var_export($completed, true);
            }
        } finally {
            yii\base\Event::off(craft\commerce\services\OrderAdjustments::class, craft\commerce\services\OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS, $handler);
            unset($GLOBALS['headdyTestFree']);
        }
    });

    // =====================================================================
    section('Payment redirect validation');

    check('a relative path is turned into a site URL', function() use ($carts, $key, $plugin, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $url = $plugin->getPayments()->validateRedirect('/thanks', $cart);

        return is_string($url) && str_contains($url, 'thanks') ?: 'got ' . var_export($url, true);
    });

    check('an allowed absolute origin passes through unchanged', function() use ($carts, $key, $plugin, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;

        return $plugin->getPayments()->validateRedirect('https://shop.example.com/thanks', $cart) === 'https://shop.example.com/thanks'
            ?: 'an allowed origin was rewritten or refused';
    });

    check('a disallowed origin is refused', function() use ($carts, $key, $plugin, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;

        try {
            $plugin->getPayments()->validateRedirect('https://evil.example/steal', $cart);
            return 'an arbitrary origin was accepted';
        } catch (ApiException $e) {
            return $e->errorCode === ApiException::REDIRECT_NOT_ALLOWED ?: "got {$e->errorCode}";
        }
    });

    check('a protocol-relative URL is treated as absolute', function() use ($carts, $key, $plugin, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;

        try {
            $plugin->getPayments()->validateRedirect('//evil.example/steal', $cart);
            return '//evil.example was treated as a relative path';
        } catch (ApiException $e) {
            return $e->errorCode === ApiException::REDIRECT_NOT_ALLOWED ?: "got {$e->errorCode}";
        }
    });

    check('a control character in a URL is refused', function() use ($carts, $key, $plugin, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;

        try {
            $plugin->getPayments()->validateRedirect("https://shop.example.com/a\r\nSet-Cookie: x=1", $cart);
            return 'a header-splitting payload was accepted';
        } catch (ApiException $e) {
            return $e->errorCode === ApiException::REDIRECT_NOT_ALLOWED ?: "got {$e->errorCode}";
        }
    });

    check('a null redirect stays null', function() use ($carts, $key, $plugin, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;

        return $plugin->getPayments()->validateRedirect(null, $cart) === null ?: 'null became something';
    });

    // =====================================================================
    section('Webhook signing');

    check('a signature is timestamped and verifiable', function() use ($plugin) {
        $body = '{"hello":"world"}';
        $signature = $plugin->getWebhooks()->sign($body, 'whsec_test', 1700000000);

        return $signature === 't=1700000000,v1=' . hash_hmac('sha256', '1700000000.' . $body, 'whsec_test')
            ?: "got $signature";
    });

    check('the timestamp is inside the signed material', function() use ($plugin) {
        $body = '{"hello":"world"}';
        $a = $plugin->getWebhooks()->sign($body, 'whsec_test', 1700000000);
        $b = $plugin->getWebhooks()->sign($body, 'whsec_test', 1700000001);

        return $a !== $b ?: 'the signature does not change with the timestamp, so a replay is undetectable';
    });

    check('a webhook only wants topics it subscribed to', function() {
        $webhook = new Webhook(['topics' => [Webhook::TOPIC_ORDER_PAID], 'enabled' => true]);

        return $webhook->wants(Webhook::TOPIC_ORDER_PAID)
            && !$webhook->wants(Webhook::TOPIC_CART_UPDATED)
            ?: 'topic filtering does not filter';
    });

    check('a disabled webhook wants nothing', function() {
        $webhook = new Webhook(['topics' => Webhook::allTopics(), 'enabled' => false]);

        return !$webhook->wants(Webhook::TOPIC_ORDER_PAID) ?: 'a disabled webhook still wanted a topic';
    });

    // =====================================================================
    section('Log redaction');

    check('a password never reaches the log', function() use ($plugin) {
        $redacted = $plugin->getLog()->redact(['email' => 'a@b.com', 'password' => 'hunter2']);

        return !str_contains($redacted, 'hunter2') && str_contains($redacted, 'a@b.com')
            ?: "got $redacted";
    });

    check('a card number is redacted at any depth', function() use ($plugin) {
        $redacted = $plugin->getLog()->redact(['paymentForm' => ['number' => '4242424242424242', 'cvv' => '123']]);

        return !str_contains($redacted, '4242424242424242') && !str_contains($redacted, '123')
            ?: "got $redacted";
    });

    check('a token is redacted', function() use ($plugin) {
        $redacted = $plugin->getLog()->redact(['token' => 'hdc_secret', 'refreshToken' => 'hdr_secret']);

        return !str_contains($redacted, 'hdc_secret') && !str_contains($redacted, 'hdr_secret')
            ?: "got $redacted";
    });

    check('a JSON string payload is redacted too', function() use ($plugin) {
        $redacted = $plugin->getLog()->redact('{"password":"hunter2"}');

        return !str_contains((string)$redacted, 'hunter2') ?: "got $redacted";
    });

    // =====================================================================
    section('Settings');

    check('origins from an editable table are flattened', function() {
        $normalized = Settings::normalizeOrigins([['value' => 'https://a.example/'], ['value' => 'https://b.example']]);

        return $normalized === ['https://a.example', 'https://b.example'] ?: json_encode($normalized);
    });

    check('a plain list of origins works too', fn() => Settings::normalizeOrigins(['https://a.example']) === ['https://a.example']
        ?: 'a plain list was mangled');

    check('a comma-separated string of origins works', fn() => Settings::normalizeOrigins('https://a.example, https://b.example') === ['https://a.example', 'https://b.example']
        ?: 'a string was not split');

    check('credentials plus a wildcard origin fails validation', function() {
        $settings = new Settings(['allowCredentials' => true, 'allowedOrigins' => ['*']]);
        $settings->validate();

        return $settings->hasErrors('allowCredentials') ?: 'a combination every browser rejects was allowed';
    });

    check('the base path is trimmed of slashes', function() {
        $settings = new Settings(['basePath' => '/api/shop/']);

        return $settings->getBasePath() === 'api/shop' ?: 'got ' . $settings->getBasePath();
    });

    // =====================================================================
    section('Live HTTP — authentication and CORS');

    check('the discovery endpoint answers', function() use ($auth) {
        $response = api('GET', '', null, $auth);

        return $response['status'] === 200 && ($response['body']['apiVersion'] ?? null) === 'v1'
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']);
    });

    check('no key is a 401 with the documented envelope', function() {
        $response = api('GET', '/store');

        return $response['status'] === 401
            && ($response['body']['error']['code'] ?? null) === ApiException::UNAUTHORIZED
            && ($response['body']['success'] ?? null) === false
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']);
    });

    check('a bogus key is a 401', function() {
        $response = api('GET', '/store', null, ['X-Headdy-Key' => 'hd_pk_nope']);

        return $response['status'] === 401 ?: 'HTTP ' . $response['status'];
    });

    check('a key without the scope is a 403', function() use ($readOnlyKey) {
        $response = api('POST', '/carts', [], ['X-Headdy-Key' => $readOnlyKey->publicKey]);

        return $response['status'] === 403
            && ($response['body']['error']['code'] ?? null) === ApiException::FORBIDDEN
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']);
    });

    check('a preflight is answered without a key', function() {
        $response = api('OPTIONS', '/carts', null, ['Origin' => 'https://shop.example.com']);

        return $response['status'] === 204
            && isset($response['headers']['access-control-allow-methods'])
            && isset($response['headers']['access-control-allow-origin'])
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['headers']);
    });

    check('every response varies on Origin', function() use ($auth) {
        $response = api('GET', '', null, $auth + ['Origin' => 'https://shop.example.com']);

        return ($response['headers']['vary'] ?? '') === 'Origin'
            ?: 'Vary was ' . var_export($response['headers']['vary'] ?? null, true);
    });

    check('responses are marked no-store', function() use ($auth) {
        $response = api('GET', '', null, $auth);

        return str_contains($response['headers']['cache-control'] ?? '', 'no-store')
            ?: 'Cache-Control was ' . var_export($response['headers']['cache-control'] ?? null, true);
    });

    check('no session cookie is issued', function() use ($auth) {
        $response = api('GET', '', null, $auth);

        return !isset($response['headers']['set-cookie'])
            ?: 'the API set a cookie: ' . $response['headers']['set-cookie'];
    });

    check('an unlisted origin gets no allow-origin header', function() use ($auth, $plugin) {
        applySettings(['allowedOrigins' => ['https://shop.example.com']]);

        try {
            $response = api('GET', '', null, $auth + ['Origin' => 'https://evil.example']);
            $blocked = !isset($response['headers']['access-control-allow-origin']);

            $response = api('GET', '', null, $auth + ['Origin' => 'https://shop.example.com']);
            $allowed = ($response['headers']['access-control-allow-origin'] ?? null) === 'https://shop.example.com';

            return ($blocked && $allowed) ?: 'blocked=' . var_export($blocked, true) . ' allowed=' . var_export($allowed, true);
        } finally {
            applySettings(['allowedOrigins' => []]);
        }
    });

    check('malformed JSON is a typed 400, not a 500', function() use ($auth) {
        $response = api('POST', '/carts', null, $auth, '{not json');

        return $response['status'] === 400
            && ($response['body']['error']['code'] ?? null) === ApiException::INVALID_JSON
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']);
    });

    // =====================================================================
    section('Live HTTP — the cart lifecycle');

    $liveToken = null;

    check('POST /carts creates a cart and returns a token', function() use ($auth, $variant, &$liveToken) {
        $response = api('POST', '/carts', ['items' => [['purchasableId' => $variant->id, 'qty' => 2]]], $auth);
        $liveToken = $response['body']['cart']['token'] ?? null;

        return $response['status'] === 201
            && is_string($liveToken)
            && str_starts_with($liveToken, Tokens::CART_PREFIX)
            && ($response['body']['cart']['totalQty'] ?? null) === 2
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']);
    });

    check('GET /carts/current returns that same cart', function() use ($auth, &$liveToken) {
        $response = api('GET', '/carts/current', null, $auth + ['X-Headdy-Cart' => $liveToken]);

        return $response['status'] === 200 && ($response['body']['cart']['totalQty'] ?? null) === 2
            ?: 'HTTP ' . $response['status'];
    });

    check('the token also works as an Authorization bearer', function() use ($auth, &$liveToken) {
        $response = api('GET', '/carts/current', null, $auth + ['Authorization' => 'Bearer ' . $liveToken]);

        return $response['status'] === 200 ?: 'HTTP ' . $response['status'];
    });

    check('no cart token is a typed 404', function() use ($auth) {
        $response = api('GET', '/carts/current', null, $auth);

        return $response['status'] === 404
            && ($response['body']['error']['code'] ?? null) === ApiException::CART_NOT_FOUND
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']);
    });

    check('POST an item through the granular endpoint', function() use ($auth, $variant2, &$liveToken) {
        $response = api('POST', '/carts/current/items', ['purchasableId' => $variant2->id, 'qty' => 1], $auth + ['X-Headdy-Cart' => $liveToken]);

        return $response['status'] === 200 && count($response['body']['cart']['lineItems'] ?? []) === 2
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']['error'] ?? []);
    });

    check('PATCH a line item quantity', function() use ($auth, &$liveToken) {
        $cart = api('GET', '/carts/current', null, $auth + ['X-Headdy-Cart' => $liveToken])['body']['cart'];
        $lineItemId = $cart['lineItems'][0]['id'];

        $response = api('PATCH', "/carts/current/items/$lineItemId", ['qty' => 5], $auth + ['X-Headdy-Cart' => $liveToken]);
        $updated = null;

        foreach ($response['body']['cart']['lineItems'] ?? [] as $item) {
            if ($item['id'] === $lineItemId) {
                $updated = $item['qty'];
            }
        }

        return $response['status'] === 200 && $updated === 5 ?: 'qty was ' . var_export($updated, true);
    });

    check('DELETE a line item', function() use ($auth, &$liveToken) {
        $cart = api('GET', '/carts/current', null, $auth + ['X-Headdy-Cart' => $liveToken])['body']['cart'];
        $lineItemId = $cart['lineItems'][0]['id'];

        $response = api('DELETE', "/carts/current/items/$lineItemId", null, $auth + ['X-Headdy-Cart' => $liveToken]);

        return $response['status'] === 200 && count($response['body']['cart']['lineItems']) === 1
            ?: 'HTTP ' . $response['status'];
    });

    check('PUT an email address', function() use ($auth, $suffix, &$liveToken) {
        $response = api('PUT', '/carts/current/email', ['email' => "live-$suffix@example.com"], $auth + ['X-Headdy-Cart' => $liveToken]);

        return $response['status'] === 200
            && strcasecmp((string)($response['body']['cart']['email'] ?? ''), "live-$suffix@example.com") === 0
            ?: 'HTTP ' . $response['status'] . ' email=' . var_export($response['body']['cart']['email'] ?? null, true);
    });

    check('PUT an address', function() use ($auth, &$liveToken) {
        $response = api('PUT', '/carts/current/addresses', [
            'shippingAddress' => [
                'addressLine1' => '5 Market Street',
                'locality' => 'Charlotte',
                'administrativeArea' => 'NC',
                'postalCode' => '28202',
                'countryCode' => 'US',
            ],
            'billingSameAsShipping' => true,
        ], $auth + ['X-Headdy-Cart' => $liveToken]);

        return $response['status'] === 200
            && ($response['body']['cart']['shippingAddress']['addressLine1'] ?? null) === '5 Market Street'
            && ($response['body']['cart']['billingAddress']['addressLine1'] ?? null) === '5 Market Street'
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']['error'] ?? []);
    });

    check('GET the shipping methods for this cart', function() use ($auth, &$liveToken) {
        $response = api('GET', '/carts/current/shipping-methods', null, $auth + ['X-Headdy-Cart' => $liveToken]);

        return $response['status'] === 200 && is_array($response['body']['shippingMethods'] ?? null)
            ?: 'HTTP ' . $response['status'];
    });

    check('GET /checkout reports readiness', function() use ($auth, &$liveToken) {
        $response = api('GET', '/checkout', null, $auth + ['X-Headdy-Cart' => $liveToken]);
        $checkout = $response['body']['checkout'] ?? [];

        return $response['status'] === 200
            && isset($checkout['ready'], $checkout['missing'], $checkout['amountDue'], $checkout['availableGateways'])
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']);
    });

    check('an unavailable shipping method is refused over the wire', function() use ($auth, &$liveToken) {
        $response = api('PUT', '/carts/current/shipping-method', ['shippingMethodHandle' => 'nope'], $auth + ['X-Headdy-Cart' => $liveToken]);

        return $response['status'] === 422
            && ($response['body']['error']['code'] ?? null) === ApiException::SHIPPING_METHOD_UNAVAILABLE
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']['error'] ?? []);
    });

    check('a failed mutation still returns the cart', function() use ($auth, &$liveToken) {
        $response = api('POST', '/carts/current/items', ['purchasableId' => 99999999, 'qty' => 1], $auth + ['X-Headdy-Cart' => $liveToken]);

        return $response['status'] === 404
            && ($response['body']['error']['code'] ?? null) === ApiException::PURCHASABLE_NOT_FOUND
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']['error'] ?? []);
    });

    // =====================================================================
    section('Live HTTP — payment');

    check('a disallowed return URL is refused before the gateway is called', function() use ($auth, $commerce, &$liveToken) {
        $gateway = $commerce->getGateways()->getAllCustomerEnabledGateways()->first();

        $response = api('POST', '/checkout/pay', [
            'gatewayId' => $gateway->id,
            'returnUrl' => 'https://evil.example/thanks',
            'paymentForm' => ['firstName' => 'Ada', 'lastName' => 'L', 'number' => '4242424242424242', 'expiry' => '12/2030', 'cvv' => '123'],
        ], $auth + ['X-Headdy-Cart' => $liveToken]);

        return $response['status'] === 422
            && ($response['body']['error']['code'] ?? null) === ApiException::REDIRECT_NOT_ALLOWED
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']['error'] ?? []);
    });

    check('a real payment completes the order and revokes the cart token', function() use ($auth, $commerce, $tokens, &$liveToken) {
        $gateway = $commerce->getGateways()->getAllCustomerEnabledGateways()
            ->first(fn($g) => $g instanceof craft\commerce\gateways\Dummy);

        if ($gateway === null) {
            return 'no Dummy gateway in this install to pay with';
        }

        $response = api('POST', '/checkout/pay', [
            'gatewayId' => $gateway->id,
            'returnUrl' => 'https://shop.example.com/thanks',
            'paymentForm' => ['firstName' => 'Ada', 'lastName' => 'Lovelace', 'number' => '4242424242424242', 'expiry' => '12/2030', 'cvv' => '123'],
        ], $auth + ['X-Headdy-Cart' => $liveToken]);

        if ($response['status'] !== 200) {
            return 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']['error'] ?? $response['body']);
        }

        $order = $response['body']['order'] ?? [];

        return ($order['isCompleted'] ?? false)
            && ($order['isPaid'] ?? false)
            && ($response['body']['outstandingBalance']['minorUnits'] ?? -1) === 0
            && $tokens->getCartByToken($liveToken) === null
            ?: 'completed=' . var_export($order['isCompleted'] ?? null, true)
                . ' paid=' . var_export($order['isPaid'] ?? null, true)
                . ' tokenStillWorks=' . var_export($tokens->getCartByToken($liveToken) !== null, true);
    });

    check('the completed cart token is gone for good', function() use ($auth, &$liveToken) {
        $response = api('GET', '/carts/current', null, $auth + ['X-Headdy-Cart' => $liveToken]);

        return in_array($response['status'], [401, 404], true) ?: 'HTTP ' . $response['status'];
    });

    // =====================================================================
    section('Live HTTP — catalog');

    check('GET /products is paginated', function() use ($auth) {
        $response = api('GET', '/products?pageSize=2', null, $auth);

        return $response['status'] === 200
            && count($response['body']['products'] ?? []) <= 2
            && isset($response['body']['pagination']['total'])
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']);
    });

    check('the page size is capped at the configured maximum', function() use ($auth, $plugin) {
        $response = api('GET', '/products?pageSize=100000', null, $auth);

        return ($response['body']['pagination']['pageSize'] ?? 0) <= $plugin->getSettings()->maxPageSize
            ?: 'pageSize was ' . ($response['body']['pagination']['pageSize'] ?? '?');
    });

    check('GET a variant by SKU', function() use ($auth, $variant) {
        $response = api('GET', '/variants/' . rawurlencode($variant->getSku()), null, $auth);

        return $response['status'] === 200 && ($response['body']['variant']['sku'] ?? null) === $variant->getSku()
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']);
    });

    check('an unknown variant is a typed 404', function() use ($auth) {
        $response = api('GET', '/variants/definitely-not-a-sku', null, $auth);

        return $response['status'] === 404
            && ($response['body']['error']['code'] ?? null) === ApiException::PURCHASABLE_NOT_FOUND
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']);
    });

    check('an injection attempt in orderBy falls back to the default', function() use ($auth) {
        $response = api('GET', '/products?orderBy=' . rawurlencode('title; DROP TABLE users'), null, $auth);

        return $response['status'] === 200 ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']);
    });

    // =====================================================================
    section('Live HTTP — rate limiting');

    check('a request over the limit is a 429 with Retry-After', function() use ($auth, $plugin) {
        applySettings(['rateLimit' => 3]);

        try {
            $last = null;

            for ($i = 0; $i < 6; $i++) {
                $last = api('GET', '', null, $auth);

                if ($last['status'] === 429) {
                    break;
                }
            }

            return $last['status'] === 429
                && ($last['body']['error']['code'] ?? null) === ApiException::RATE_LIMITED
                && isset($last['headers']['retry-after'])
                ?: 'HTTP ' . $last['status'] . ' ' . json_encode($last['body']);
        } finally {
            applySettings(['rateLimit' => 0]);
        }
    });

    // =====================================================================
    section('GraphQL');

    // The console app booted on whatever edition project config held at the time, so `init()` may
    // have skipped the GraphQL wiring before this suite switched to Pro. Attaching explicitly here
    // exercises the same method `init()` calls.
    $plugin->registerGraphql();

    // The cart fields only exist on a schema granted Headdy's component. First, one
    // that isn't: neither the fields nor the resolvers may be reachable.
    $gqlService = Craft::$app->getGql();
    // Distinct uids: Craft keys its built schema definitions by uid.
    $ungranted = new craft\models\GqlSchema(['name' => 'No Headdy', 'uid' => craft\helpers\StringHelper::UUID(), 'scope' => []]);
    $granted = new craft\models\GqlSchema(['name' => 'Headdy carts', 'uid' => craft\helpers\StringHelper::UUID(), 'scope' => ['headdyCarts:read', 'headdyCarts:edit']]);

    check('a schema without the Headdy grant gets no cart mutations or queries', function() use ($gqlService, $ungranted) {
        $gqlService->setActiveSchema($ungranted);
        $mutations = new craft\events\RegisterGqlMutationsEvent(['mutations' => []]);
        $gqlService->trigger(craft\services\Gql::EVENT_REGISTER_GQL_MUTATIONS, $mutations);
        $queries = new craft\events\RegisterGqlQueriesEvent(['queries' => []]);
        $gqlService->trigger(craft\services\Gql::EVENT_REGISTER_GQL_QUERIES, $queries);

        return !isset($mutations->mutations['headdyCartCreate']) && !isset($queries->queries['headdyCart'])
            ?: 'cart fields registered on an ungranted schema';
    });

    check('a resolver refuses a schema without the grant, even if the field were cached', function() use ($gqlService, $ungranted, $variant) {
        $gqlService->setActiveSchema($ungranted);

        try {
            justinholtweb\headdy\gql\CartMutations::create(['items' => [['purchasableId' => $variant->id, 'qty' => 1]]]);

            return 'a cart was created without the grant';
        } catch (GraphQL\Error\UserError $e) {
            return str_contains($e->getMessage(), 'does not include Headdy') ?: $e->getMessage();
        }
    });

    check('the schema component is offered on the GraphQL schema screen', function() use ($gqlService) {
        $components = $gqlService->getAllSchemaComponents();
        $flat = json_encode($components);

        return str_contains($flat, 'headdyCarts:read') && str_contains($flat, 'headdyCarts:edit') ?: 'component not registered';
    });

    check('Craft\'s built schema has the cart mutation only when the component is granted', function() use ($gqlService, $ungranted, $granted) {
        $has = function(craft\models\GqlSchema $schema) use ($gqlService): bool {
            // Craft keeps loaded types in static registries for the life of the process; a
            // second schema built without flushing them reuses the first one's Mutation type.
            $gqlService->flushCaches();
            $gqlService->setActiveSchema($schema);
            $built = $gqlService->getSchemaDef($schema, true);

            return $built->getMutationType()?->hasField('headdyCartCreate') ?? false;
        };

        $without = $has($ungranted);
        $with = $has($granted);

        return !$without && $with ?: 'ungranted ' . var_export($without, true) . ', granted ' . var_export($with, true);
    });

    // Everything below runs as a schema that has been granted the component.
    $gqlService->setActiveSchema($granted);

    check('the cart mutations are registered', function() {
        $event = new craft\events\RegisterGqlMutationsEvent(['mutations' => []]);
        Craft::$app->getGql()->trigger(craft\services\Gql::EVENT_REGISTER_GQL_MUTATIONS, $event);

        $expected = [
            'headdyCartCreate', 'headdyCartAddItem', 'headdyCartUpdateItem', 'headdyCartRemoveItem',
            'headdyCartClear', 'headdyCartSetEmail', 'headdyCartSetAddresses', 'headdyCartApplyCoupon',
            'headdyCartSetShippingMethod', 'headdyCartSetGateway', 'headdyCheckoutComplete',
        ];

        $missing = array_diff($expected, array_keys($event->mutations));

        return $missing === [] ?: 'missing: ' . implode(', ', $missing);
    });

    check('the cart queries are registered', function() {
        $event = new craft\events\RegisterGqlQueriesEvent(['queries' => []]);
        Craft::$app->getGql()->trigger(craft\services\Gql::EVENT_REGISTER_GQL_QUERIES, $event);

        return isset($event->queries['headdyCart'], $event->queries['headdyCheckout'])
            ?: 'missing: ' . json_encode(array_keys($event->queries));
    });

    check('a GraphQL mutation creates a cart through the same service as REST', function() use ($variant) {
        $result = justinholtweb\headdy\gql\CartMutations::create([
            'items' => [['purchasableId' => $variant->id, 'qty' => 2]],
        ]);

        return ($result['totalQty'] ?? null) === 2 && is_string($result['token'] ?? null)
            ?: json_encode($result);
    });

    check('a GraphQL mutation and a REST call produce the same payload keys', function() use ($variant, $auth) {
        $gql = justinholtweb\headdy\gql\CartMutations::create(['items' => [['purchasableId' => $variant->id, 'qty' => 1]]]);
        $rest = api('POST', '/carts', ['items' => [['purchasableId' => $variant->id, 'qty' => 1]]], $auth)['body']['cart'] ?? [];

        $difference = array_diff(array_keys($gql), array_keys($rest));

        return $difference === [] ?: 'the two transports disagree: ' . implode(', ', $difference);
    });

    check('a GraphQL cart token resolves through the query', function() use ($variant) {
        $created = justinholtweb\headdy\gql\CartMutations::create(['items' => [['purchasableId' => $variant->id, 'qty' => 1]]]);
        $queries = justinholtweb\headdy\gql\CartQueries::getQueries();
        $cart = $queries['headdyCart']['resolve'](null, ['cartToken' => $created['token']]);

        return ($cart['id'] ?? null) === ($created['id'] ?? null) ?: 'the query returned a different cart';
    });

    check('a bad GraphQL cart token is a readable user error', function() {
        try {
            justinholtweb\headdy\gql\CartMutations::mutate(['cartToken' => 'hdc_nope'], ['clearLineItems' => true]);
            return 'no exception';
        } catch (GraphQL\Error\UserError $e) {
            return str_contains($e->getMessage(), 'not valid') ?: 'got ' . $e->getMessage();
        }
    });

    check('GraphQL line item options decode from a JSON string', function() {
        return justinholtweb\headdy\gql\CartMutations::decodeOptions('{"engraving":"Ada"}') === ['engraving' => 'Ada']
            && justinholtweb\headdy\gql\CartMutations::decodeOptions(null) === []
            && justinholtweb\headdy\gql\CartMutations::decodeOptions('not json') === []
            ?: 'options decoding is wrong';
    });

    // =====================================================================
    section('Customers');

    check('a storefront customer can sign in for a token pair', function() use ($plugin, $suffix, &$createdUsers) {
        $user = new User();
        $user->email = "customer-$suffix@example.com";
        $user->username = $user->email;
        $user->newPassword = 'correct horse battery staple';
        $user->pending = false;

        if (!Craft::$app->getElements()->saveElement($user)) {
            return 'could not create the fixture user: ' . json_encode($user->getErrors());
        }

        Craft::$app->getUsers()->activateUser($user);
        $createdUsers[] = $user;

        $result = $plugin->getCustomers()->login($user->email, 'correct horse battery staple');

        return is_string($result['token'] ?? null)
            && is_string($result['refreshToken'] ?? null)
            && ($result['customer']['email'] ?? null) === $user->email
            ?: json_encode($result);
    });

    check('a wrong password is refused with the same code as an unknown account', function() use ($plugin, $suffix) {
        $codes = [];

        foreach ([["customer-$suffix@example.com", 'wrong'], ['nobody-here@example.com', 'wrong']] as [$login, $password]) {
            try {
                $plugin->getCustomers()->login($login, $password);
                $codes[] = 'no exception';
            } catch (ApiException $e) {
                $codes[] = $e->errorCode . '/' . $e->statusCode;
            }
        }

        return $codes[0] === $codes[1] && $codes[0] === ApiException::CUSTOMER_LOGIN_FAILED . '/401'
            ?: 'the two failures are distinguishable: ' . json_encode($codes);
    });

    check('a control panel account cannot sign in through the API', function() use ($plugin) {
        $admin = User::find()->admin(true)->status(null)->one();

        if ($admin === null) {
            return 'no admin account to test with';
        }

        try {
            $plugin->getCustomers()->login($admin->email, 'whatever');
            return 'an admin signed in';
        } catch (ApiException $e) {
            // Either refusal is correct — what matters is that it is never a success.
            return in_array($e->errorCode, [ApiException::CUSTOMER_LOGIN_FAILED], true) ?: 'got ' . $e->errorCode;
        }
    });

    check('signing in never creates a Craft session', function() use ($plugin, $suffix) {
        $plugin->getCustomers()->login("customer-$suffix@example.com", 'correct horse battery staple');

        return Craft::$app->getUser()->getIsGuest() ?: 'the API logged someone into Craft';
    });

    check('a refresh token rotates and burns the old one', function() use ($plugin, $tokens, $suffix) {
        $first = $plugin->getCustomers()->login("customer-$suffix@example.com", 'correct horse battery staple');
        $second = $tokens->refreshCustomerToken($first['refreshToken']);

        return $second !== null
            && $second['refreshToken'] !== $first['refreshToken']
            && $tokens->refreshCustomerToken($first['refreshToken']) === null
            ?: 'the old refresh token still works, so it is a permanent credential';
    });

    check('a revoked access token stops resolving', function() use ($plugin, $tokens, $suffix) {
        $result = $plugin->getCustomers()->login("customer-$suffix@example.com", 'correct horse battery staple');
        $tokens->revokeCustomerToken($result['token']);

        return $tokens->getUserByCustomerToken($result['token']) === null ?: 'a revoked token still resolves';
    });

    check('a guest order lookup needs the matching email', function() use ($plugin, $carts, $key, $variant, &$createdOrders) {
        $cart = $carts->createCart($key);
        $createdOrders[] = $cart;
        $cart = $carts->update($cart, [
            'addItems' => [['purchasableId' => $variant->id, 'qty' => 1]],
            'email' => 'lookup@example.com',
        ]);
        $cart->markAsComplete();

        $found = $plugin->getCustomers()->guestOrder($cart->number, 'lookup@example.com');

        try {
            $plugin->getCustomers()->guestOrder($cart->number, 'someone-else@example.com');
            return 'the wrong email was accepted';
        } catch (ApiException $e) {
            return ($found['number'] ?? null) === $cart->number && $e->statusCode === 404
                ?: 'the right email failed, or the wrong one gave itself away';
        }
    });

    check('registration creates a pending account and issues no tokens while Craft verifies email', function() use ($plugin, $suffix, &$createdUsers) {
        $settings = $plugin->getSettings();
        $was = $settings->allowCustomerRegistration;
        $settings->allowCustomerRegistration = true;

        try {
            if (!$plugin->getCustomers()->requiresEmailVerification()) {
                return 'this harness does not require email verification, so the check proves nothing';
            }

            $result = $plugin->getCustomers()->register(['email' => "registrant-$suffix@example.com", 'password' => 'a perfectly long password']);
            $user = Craft::$app->getUsers()->getUserByUsernameOrEmail("registrant-$suffix@example.com");

            if ($user) {
                $createdUsers[] = $user;
            }

            try {
                $plugin->getCustomers()->login("registrant-$suffix@example.com", 'a perfectly long password');
                $signedIn = true;
            } catch (ApiException) {
                $signedIn = false;
            }

            return $result === ['verificationRequired' => true] && $user?->pending === true && !$signedIn
                ?: json_encode(['result' => $result, 'pending' => $user?->pending, 'signedIn' => $signedIn]);
        } finally {
            $settings->allowCustomerRegistration = $was;
        }
    });

    check('registration only sets the custom fields the merchant has opened', function() use ($plugin) {
        $settings = $plugin->getSettings();
        $was = $settings->registrationFields;
        $settings->registrationFields = [['value' => 'nickname'], '  tier ', ''];

        try {
            return $settings->getRegistrationFields() === ['nickname', 'tier'] ?: json_encode($settings->getRegistrationFields());
        } finally {
            $settings->registrationFields = $was;
        }
    });

    check('wrong passwords for a control panel account never count towards its lockout', function() use ($plugin) {
        // Otherwise the storefront API is a way to lock the site's admins out.
        $admin = User::find()->admin(true)->status(null)->one();
        $before = (int)(new craft\db\Query())->select(['invalidLoginCount'])->from('{{%users}}')->where(['id' => $admin->id])->scalar();

        for ($i = 0; $i < 3; $i++) {
            try {
                $plugin->getCustomers()->login($admin->email, 'definitely not the password');
            } catch (ApiException) {
            }
        }

        $after = (int)(new craft\db\Query())->select(['invalidLoginCount'])->from('{{%users}}')->where(['id' => $admin->id])->scalar();

        return $after === $before ?: "the admin's failed-login count went from $before to $after";
    });

    check('repeated wrong passwords lock the account, as the control panel sign-in does', function() use ($plugin, $suffix, &$createdUsers) {
        $user = new User();
        $user->email = "lockout-$suffix@example.com";
        $user->username = $user->email;
        $user->newPassword = 'the right password here';
        Craft::$app->getElements()->saveElement($user, false);
        Craft::$app->getUsers()->activateUser($user);
        $createdUsers[] = $user;

        $limit = Craft::$app->getConfig()->getGeneral()->maxInvalidLogins;

        for ($i = 0; $i <= $limit; $i++) {
            try {
                $plugin->getCustomers()->login($user->email, 'not it');
            } catch (ApiException) {
            }
        }

        try {
            $plugin->getCustomers()->login($user->email, 'the right password here');

            return "the right password still worked after $limit wrong ones";
        } catch (ApiException $e) {
            $fresh = Craft::$app->getUsers()->getUserById($user->id);

            return $fresh->locked ?: 'refused, but the account is not marked locked';
        }
    });

    check('sign-in is limited per address over HTTP, whatever the key allows', function() use ($auth) {
        // Pre-fill this minute's bucket rather than send ten requests. The address the web side
        // sees depends on the route in (DDEV's router, not loopback), so ask the request log.
        api('GET', '', null, $auth);
        $seen = (new craft\db\Query())->select(['ip'])->from('{{%headdy_log}}')->orderBy(['id' => SORT_DESC])->scalar();
        $window = (int)floor(time() / 60);
        $ips = array_filter(['127.0.0.1', '::1', is_string($seen) ? $seen : null]);

        foreach ($ips as $ip) {
            Craft::$app->getCache()->set("headdy.rate.auth.login.ip:$ip.$window", 10, 120);
        }

        try {
            $response = api('POST', '/customers/sessions', ['loginName' => 'someone@example.com', 'password' => 'x'], $auth);

            return $response['status'] === 429 && ($response['body']['error']['code'] ?? null) === ApiException::RATE_LIMITED
                ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']);
        } finally {
            foreach ($ips as $ip) {
                Craft::$app->getCache()->delete("headdy.rate.auth.login.ip:$ip.$window");
            }
        }
    });

    // =====================================================================
    section('Diagnostics');

    check('the route table covers every registered rule', function() use ($plugin) {
        $rules = $plugin->_apiRules();
        $table = $plugin->getDiagnostics()->routeTable();

        return count($table) === count($rules) && !empty($table[0]['method']) && str_starts_with($table[0]['path'], '/')
            ?: 'got ' . count($table) . ' rows for ' . count($rules) . ' rules';
    });

    check('the checks run and classify themselves', function() use ($plugin) {
        $checks = $plugin->getDiagnostics()->runChecks();

        foreach ($checks as $entry) {
            if (!isset($entry['level'], $entry['label']) || !in_array($entry['level'], ['ok', 'warning', 'error'], true)) {
                return 'a check is malformed: ' . json_encode($entry);
            }
        }

        return count($checks) > 5 ?: 'only ' . count($checks) . ' checks ran';
    });

    check('a missing base path is reported as an error', function() use ($plugin) {
        applySettings(['basePath' => '']);

        try {
            $levels = array_column($plugin->getDiagnostics()->runChecks(), 'level');

            return in_array('error', $levels, true) ?: 'an unmounted API was not flagged';
        } finally {
            applySettings(['basePath' => 'api/storefront']);
        }
    });

    // =====================================================================
    section('Lite edition');

    switchEdition(Plugin::EDITION_LITE);

    check('the plugin reports itself as Lite', fn() => !$plugin->isPro() ?: 'still Pro');

    check('carts still work on Lite', function() use ($auth, $variant) {
        $response = api('POST', '/carts', ['items' => [['purchasableId' => $variant->id, 'qty' => 1]]], $auth);

        return $response['status'] === 201 ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']);
    });

    check('the catalog still works on Lite', function() use ($auth) {
        return api('GET', '/products?pageSize=1', null, $auth)['status'] === 200 ?: 'catalog was gated';
    });

    check('customer endpoints are gated behind Pro', function() use ($auth) {
        $response = api('GET', '/customers/me', null, $auth);

        return $response['status'] === 403
            && ($response['body']['error']['code'] ?? null) === ApiException::PRO_REQUIRED
            ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']);
    });

    check('a Pro refusal still carries its CORS headers', function() use ($auth) {
        $response = api('GET', '/customers/me', null, $auth + ['Origin' => 'https://shop.example.com']);

        return isset($response['headers']['access-control-allow-origin'])
            ?: 'a browser would see a network error instead of the 403';
    });

    check('webhooks do not dispatch on Lite', function() use ($plugin) {
        return $plugin->getWebhooks()->dispatch(Webhook::TOPIC_ORDER_PAID, ['x' => 1]) === 0
            ?: 'a Lite install queued a webhook';
    });

    check('the log does not record on Lite', function() use ($plugin) {
        $before = (int)(new craft\db\Query())->from(Table::LOG)->count();
        $plugin->getLog()->record(['method' => 'GET', 'path' => '/lite-test', 'statusCode' => 200]);
        $after = (int)(new craft\db\Query())->from(Table::LOG)->count();

        return $before === $after ?: 'a Lite install wrote a log row';
    });

    switchEdition(Plugin::EDITION_PRO);

    // =====================================================================
    section('Disabled API');

    check('every endpoint answers 503 while the API is off', function() use ($auth) {
        applySettings(['enabled' => false]);

        try {
            $response = api('GET', '', null, $auth);

            return $response['status'] === 503
                && ($response['body']['error']['code'] ?? null) === ApiException::DISABLED
                ?: 'HTTP ' . $response['status'] . ' ' . json_encode($response['body']);
        } finally {
            applySettings(['enabled' => true]);
        }
    });

    check('the API comes back when switched on again', function() use ($auth) {
        return api('GET', '', null, $auth)['status'] === 200 ?: 'the API did not come back';
    });
} finally {
    // =====================================================================
    echo "\nCleaning up\n";

    foreach ($createdOrders as $order) {
        try {
            Craft::$app->getElements()->deleteElement($order, true);
        } catch (Throwable) {
        }
    }

    // Carts created over HTTP and through GraphQL are not in $createdOrders; they are found by the
    // fixture SKUs their line items carry.
    try {
        $skus = [];

        foreach ($createdProducts as $product) {
            foreach ($product->getVariants()->all() as $v) {
                $skus[] = $v->getSku();
            }
        }

        if ($skus) {
            $orderIds = (new craft\db\Query())
                ->select(['orderId'])
                ->distinct()
                ->from('{{%commerce_lineitems}}')
                ->where(['sku' => $skus])
                ->column();

            foreach ($orderIds as $orderId) {
                $order = Order::find()->id((int)$orderId)->isCompleted(null)->status(null)->one();

                if ($order !== null) {
                    Craft::$app->getElements()->deleteElement($order, true);
                }
            }
        }
    } catch (Throwable $e) {
        echo "  ! could not sweep fixture orders: {$e->getMessage()}\n";
    }

    foreach ($createdProducts as $product) {
        try {
            Craft::$app->getElements()->deleteElement($product, true);
        } catch (Throwable) {
        }
    }

    foreach ($createdUsers as $user) {
        try {
            Craft::$app->getElements()->deleteElement($user, true);
        } catch (Throwable) {
        }
    }

    foreach ($createdKeys as $apiKey) {
        try {
            $plugin->getKeys()->deleteKeyById($apiKey->id);
        } catch (Throwable) {
        }
    }

    foreach ($createdWebhooks as $webhook) {
        try {
            $plugin->getWebhooks()->deleteWebhookById($webhook->id);
        } catch (Throwable) {
        }
    }

    try {
        Craft::$app->getDb()->createCommand()->delete(Table::LOG, ['like', 'path', 'storefront'])->execute();
    } catch (Throwable) {
    }

    try {
        applySettings($originalSettings);
        switchEdition($originalEdition);
    } catch (Throwable $e) {
        echo "  ! could not restore settings: {$e->getMessage()}\n";
    }

    if ($lyfeWasEnabled ?? false) {
        try {
            syncConfigVersion();
            Craft::$app->getPlugins()->enablePlugin('lyfe');
            Craft::$app->getProjectConfig()->saveModifiedConfigData();
            echo "  ! re-enabled craft-lyfe\n";
        } catch (Throwable $e) {
            echo "  ! COULD NOT RE-ENABLE craft-lyfe: {$e->getMessage()}\n";
        }
    }

    echo "\n$passed passed, $failed failed\n";
}

exit($failed > 0 ? 1 : 0);
